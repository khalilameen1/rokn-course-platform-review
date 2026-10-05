<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProductEventConflictException;
use App\Models\CourseCheckout;
use App\Models\Lesson;
use App\Models\LessonWatchEvidence;
use App\Models\ProductEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Support\BusinessClock;
use Ramsey\Uuid\Uuid;
use DateTimeInterface;

final class ProductEventService
{
    public function __construct(private OutboxService $outbox)
    {
    }

    /** Committed receipt evidence; never a synthetic device session. */
    public function recordCourseCheckoutTransition(User $user, CourseCheckout $checkout, string $event): void
    {
        if ($checkout->terms['mode'] !== 'purchase') return;
        $occurredAt = match ($event) {
            'checkout_quoted' => $checkout->created_at,
            'purchase_started' => $checkout->authorized_at,
            'purchase_completed' => $checkout->completed_at,
            default => throw new \LogicException('Unsupported checkout product event.'),
        };
        $this->record([
            // Reuse Laravel's installed Ramsey UUID implementation. The keyed
            // name keeps this internal ID unguessable from the public receipt.
            'event_id' => $this->transitionId('course-checkout:'.$checkout->public_id, $event),
            'session_key' => null,
            'event_name' => $event,
            'source' => 'server',
            'screen_key' => 'course_checkout',
            'course_id' => (int) $checkout->course_id,
            'occurred_at' => $occurredAt->toIso8601String(),
        ], $user);
    }

    /** Only positive credited playback and the frozen completion transition. */
    public function recordLessonWatchTransition(User $user, Lesson $lesson,
        LessonWatchEvidence $evidence, string $event, DateTimeInterface $occurredAt, int $courseId): void
    {
        if (!in_array($event, ['lesson_started', 'lesson_completed'], true)) {
            throw new \LogicException('Unsupported lesson product event.');
        }
        $this->record([
            'event_id' => $this->transitionId('lesson-evidence:'.$evidence->id, $event),
            'session_key' => null,
            'event_name' => $event,
            'source' => 'server',
            'screen_key' => 'player',
            'course_id' => $courseId,
            // Actual media identity, not a current lesson projected on read.
            'lesson_id' => (int) $lesson->id,
            'occurred_at' => $occurredAt->format(DATE_ATOM),
        ], $user);
    }

    private function transitionId(string $aggregate, string $event): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL,
            $this->keyedIdentity($aggregate.':'.$event));
    }

    public function record(array $data, ?User $user = null): ProductEvent
    {
        return DB::transaction(function () use ($data, $user) {
            $receivedAt = BusinessClock::utcNow()->setMicrosecond(0);
            $clientOccurredAt = CarbonImmutable::parse((string) $data['occurred_at'])
                ->utc()
                ->setMicrosecond(0);
            $serverEvent = ($data['source'] ?? null) === 'server';
            $occurredAt = $serverEvent || $clientOccurredAt->between(
                $receivedAt->subDays(7),
                $receivedAt->addMinutes(5),
                true
            ) ? $clientOccurredAt : $receivedAt;
            $sessionKey = $serverEvent ? null : $this->keyedIdentity('session:'.(string) $data['session_key']);
            $attributes = [
                'user_id' => $user?->id,
                'actor_key' => $this->keyedIdentity(
                    $user ? 'user:'.$user->id : 'guest-session:'.(string) $data['session_key']
                ),
                'session_key' => $sessionKey,
                'event_name' => (string) $data['event_name'],
                'source' => (string) ($data['source'] ?? 'app'),
                'screen_key' => $data['screen_key'] ?? null,
                'campaign_key' => $data['campaign_key'] ?? null,
                'course_id' => isset($data['course_id']) ? (int) $data['course_id'] : null,
                'module_id' => isset($data['module_id']) ? (int) $data['module_id'] : null,
                'lesson_id' => isset($data['lesson_id']) ? (int) $data['lesson_id'] : null,
                'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
                'milestone' => isset($data['milestone']) ? (int) $data['milestone'] : null,
                'value' => isset($data['value']) ? (int) $data['value'] : null,
                // SQL timestamp columns are second-precision in the supported
                // production schema. Normalize before the idempotency compare
                // so a normal JavaScript ISO timestamp with milliseconds does
                // not look like a conflicting retry.
                'occurred_at' => $occurredAt,
                'received_at' => $receivedAt,
            ];

            $event = ProductEvent::query()->firstOrCreate(
                ['event_id' => $data['event_id']],
                $attributes
            );

            if (!$event->wasRecentlyCreated && !$this->sameEvent($event, $attributes)) {
                throw new ProductEventConflictException('event_id payload mismatch');
            }

            // A durable guest event may be replayed after sign-in. Promote the
            // same immutable event to its known account instead of permanently
            // splitting that learner between anonymous and authenticated actors.
            if (!$event->wasRecentlyCreated && $user && $event->user_id === null) {
                $event->forceFill([
                    'user_id' => $user->id,
                    'actor_key' => $attributes['actor_key'],
                ])->save();
            }

            if ($event->wasRecentlyCreated) {
                $this->outbox->record(
                    'product.' . $event->event_name,
                    [
                        'event_id' => $event->event_id,
                        'event_name' => $event->event_name,
                        'source' => $event->source,
                        'screen_key' => $event->screen_key,
                        'campaign_key' => $event->campaign_key,
                        'course_id' => $event->course_id,
                        'module_id' => $event->module_id,
                        'lesson_id' => $event->lesson_id,
                        'project_id' => $event->project_id,
                        'milestone' => $event->milestone,
                        'value' => $event->value,
                        'occurred_at' => $event->occurred_at?->toIso8601String(),
                    ],
                    'product_event',
                    $event->id,
                    $event->event_id
                );
            }

            return $event;
        });
    }

    private function keyedIdentity(string $value): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded === false ? $key : $decoded;
        }

        if ($key === '') {
            throw new \RuntimeException('APP_KEY is required for product-event pseudonyms.');
        }

        return hash_hmac('sha256', $value, $key);
    }

    private function sameEvent(ProductEvent $event, array $attributes): bool
    {
        foreach (['event_name', 'source', 'screen_key', 'campaign_key'] as $field) {
            $actual = $event->{$field};
            $expected = $attributes[$field];
            if (($actual === null ? null : (string) $actual)
                !== ($expected === null ? null : (string) $expected)) {
                return false;
            }
        }

        foreach (['course_id', 'module_id', 'lesson_id', 'project_id', 'milestone', 'value'] as $field) {
            $actual = $event->{$field};
            $expected = $attributes[$field];
            if (($actual === null ? null : (int) $actual)
                !== ($expected === null ? null : (int) $expected)) {
                return false;
            }
        }

        // occurred_at is deliberately server-clamped when a device clock is
        // implausible. A later retry must remain idempotent even though its
        // receipt instant differs; the remaining immutable payload is enough
        // to detect event-id reuse for another action.
        return true;
    }
}
