<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\TransientWebhookDeliveryException;
use App\Models\OutboxEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookDestinationPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class DeliverOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 90;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $outboxEventId)
    {
        $this->onQueue((string) config('webhooks.queue', 'webhooks'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(WebhookDestinationPolicy $destinations): void
    {
        $claim = $this->claim();
        if ($claim === null || $claim === 'busy') {
            return;
        }
        if (is_int($claim)) {
            $this->release($claim);

            return;
        }

        $event = OutboxEvent::query()->find($this->outboxEventId);
        if (! $event) {
            return;
        }

        $endpoints = WebhookEndpoint::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->accepts($event->topic));

        $transientFailure = null;
        foreach ($endpoints as $endpoint) {
            try {
                $this->deliver($event, $endpoint, $destinations);
            } catch (TransientWebhookDeliveryException $exception) {
                // One slow destination must not starve every endpoint that
                // follows it. Delivered endpoints remain idempotently closed;
                // the event is retried only for destinations still pending.
                $transientFailure ??= $exception;
            }
        }
        if ($transientFailure) {
            $delay = $this->retryDelay();
            OutboxEvent::query()->whereKey($event->id)->update([
                'status' => OutboxEvent::STATUS_PENDING,
                'locked_at' => null,
                'available_at' => now()->addSeconds($delay),
                'last_error_fingerprint' => hash('sha256', $transientFailure->getMessage()),
                'updated_at' => now(),
            ]);
            throw $transientFailure;
        }

        $hasFailed = WebhookDelivery::query()
            ->where('outbox_event_id', $event->id)
            ->where('status', WebhookDelivery::STATUS_FAILED)
            ->exists();

        OutboxEvent::query()->whereKey($event->id)->update([
            'status' => $hasFailed ? OutboxEvent::STATUS_FAILED : OutboxEvent::STATUS_DELIVERED,
            'locked_at' => null,
            'available_at' => null,
            'delivered_at' => $hasFailed ? null : now(),
            'updated_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        OutboxEvent::query()
            ->whereKey($this->outboxEventId)
            ->whereNotIn('status', [
                OutboxEvent::STATUS_DELIVERED,
                OutboxEvent::STATUS_SKIPPED,
            ])
            ->update([
                'status' => OutboxEvent::STATUS_FAILED,
                'locked_at' => null,
                'available_at' => null,
                'last_error_fingerprint' => $exception
                    ? hash('sha256', get_class($exception).':'.$exception->getMessage())
                    : null,
                'updated_at' => now(),
            ]);
    }

    /** @return OutboxEvent|'busy'|int|null */
    private function claim(): OutboxEvent|string|int|null
    {
        return DB::transaction(function (): OutboxEvent|string|int|null {
            $event = OutboxEvent::query()->lockForUpdate()->find($this->outboxEventId);
            if (
                ! $event
                || in_array($event->status, [
                    OutboxEvent::STATUS_DELIVERED,
                    OutboxEvent::STATUS_FAILED,
                    OutboxEvent::STATUS_BLOCKED,
                    OutboxEvent::STATUS_SKIPPED,
                ], true)
            ) {
                return null;
            }

            if ($event->aggregate_type && $event->aggregate_id) {
                $predecessor = OutboxEvent::query()
                    ->where('aggregate_type', $event->aggregate_type)
                    ->where('aggregate_id', $event->aggregate_id)
                    ->where('id', '<', $event->id)
                    ->whereNotIn('status', [
                        OutboxEvent::STATUS_DELIVERED,
                        OutboxEvent::STATUS_SKIPPED,
                    ])
                    ->orderBy('id')
                    ->first(['id']);
                if ($predecessor) {
                    $event->forceFill([
                        'status' => OutboxEvent::STATUS_BLOCKED,
                        'locked_at' => null,
                        'available_at' => null,
                        'last_error_fingerprint' => hash(
                            'sha256',
                            'blocked-by-predecessor:'.$predecessor->id
                        ),
                    ])->save();

                    return null;
                }
            }

            $staleBefore = now()->subSeconds(max(30, (int) config('webhooks.claim_stale_seconds', 180)));
            if (
                $event->status === OutboxEvent::STATUS_PROCESSING
                && $event->locked_at
                && $event->locked_at->gt($staleBefore)
            ) {
                return 'busy';
            }

            if ($event->available_at && $event->available_at->isFuture()) {
                return max(1, (int) ceil(now()->diffInSeconds($event->available_at)));
            }

            $event->forceFill([
                'status' => OutboxEvent::STATUS_PROCESSING,
                'attempts' => (int) $event->attempts + 1,
                'dispatched_at' => now(),
                'locked_at' => now(),
                'available_at' => null,
            ])->save();

            return $event;
        });
    }

    private function deliver(
        OutboxEvent $event,
        WebhookEndpoint $endpoint,
        WebhookDestinationPolicy $destinations
    ): void {
        $delivery = WebhookDelivery::query()->firstOrCreate(
            [
                'webhook_endpoint_id' => $endpoint->id,
                'outbox_event_id' => $event->id,
            ],
            [
                'delivery_key' => hash('sha256', $endpoint->id.':'.$event->event_key),
                'status' => WebhookDelivery::STATUS_PENDING,
                'available_at' => now(),
            ]
        );
        if ($delivery->status === WebhookDelivery::STATUS_DELIVERED) {
            return;
        }

        try {
            $destination = $destinations->resolve((string) $endpoint->url);
            $secret = (string) $endpoint->secret;
            if (strlen($secret) < 32) {
                throw new \InvalidArgumentException('Webhook signing secret is too short.');
            }
        } catch (\InvalidArgumentException $exception) {
            $this->failDelivery($delivery, null, $exception);

            return;
        }

        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_PROCESSING,
            'attempts' => (int) $delivery->attempts + 1,
            'available_at' => null,
            'response_code' => null,
            'error_fingerprint' => null,
        ])->save();

        $body = json_encode([
            'id' => $event->event_key,
            'topic' => $event->topic,
            'occurred_at' => $event->created_at?->toIso8601String(),
            'data' => $event->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        $timeout = min(
            max(2, (int) $endpoint->timeout_seconds),
            max(2, (int) config('webhooks.max_timeout_seconds', 15))
        );

        $options = ['allow_redirects' => false, 'http_errors' => false];
        if (defined('CURLOPT_RESOLVE')) {
            $address = str_contains($destination['address'], ':')
                ? '['.$destination['address'].']'
                : $destination['address'];
            $options['curl'] = [
                constant('CURLOPT_RESOLVE') => [
                    $destination['host'].':'.$destination['port'].':'.$address,
                ],
            ];
        }

        try {
            $response = Http::withOptions($options)
                ->connectTimeout(min($timeout, max(1, (int) config('webhooks.connect_timeout_seconds', 3))))
                ->timeout($timeout)
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => 'Rokn-Webhook/1.0',
                    'X-Rokn-Event-Id' => $event->event_key,
                    'X-Rokn-Topic' => $event->topic,
                    'X-Rokn-Timestamp' => $timestamp,
                    'X-Rokn-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->post($destination['url']);
        } catch (ConnectionException $exception) {
            $this->retryDelivery($delivery, null, $exception);
        }

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            $delivery->forceFill([
                'status' => WebhookDelivery::STATUS_DELIVERED,
                'response_code' => $status,
                'available_at' => null,
                'delivered_at' => now(),
                'error_fingerprint' => null,
            ])->save();

            return;
        }

        $error = new \RuntimeException('Webhook returned HTTP '.$status.'.');
        if ($status === 408 || $status === 425 || $status === 429 || $status >= 500) {
            $this->retryDelivery($delivery, $status, $error);
        }

        $this->failDelivery($delivery, $status, $error);
    }

    private function retryDelivery(WebhookDelivery $delivery, ?int $status, Throwable $exception): never
    {
        $delay = $this->retryDelay();
        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_PENDING,
            'response_code' => $status,
            'available_at' => now()->addSeconds($delay),
            'error_fingerprint' => hash('sha256', get_class($exception).':'.$exception->getMessage()),
        ])->save();

        throw new TransientWebhookDeliveryException('Transient webhook delivery failure.', 0, $exception);
    }

    private function failDelivery(WebhookDelivery $delivery, ?int $status, Throwable $exception): void
    {
        $delivery->forceFill([
            'status' => WebhookDelivery::STATUS_FAILED,
            'response_code' => $status,
            'available_at' => null,
            'error_fingerprint' => hash('sha256', get_class($exception).':'.$exception->getMessage()),
        ])->save();
    }

    private function retryDelay(): int
    {
        $backoff = $this->backoff();
        $attempt = max(1, $this->attempts());

        return $backoff[min($attempt - 1, count($backoff) - 1)];
    }
}
