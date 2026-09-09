<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiUsageEvent;
use App\Services\OpenRouterCostReconciliationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class OpenRouterCostReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['openrouter.api_key' => 'local-test-key']);
        Http::preventStrayRequests();
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->string('provider_request_id')->nullable();
            $table->decimal('reserved_cost_usd', 12, 6)->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('fx_rate_to_egp', 12, 4)->nullable();
            $table->decimal('cost_egp', 12, 6)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_usage_events');
        parent::tearDown();
    }

    public function test_generation_cost_replaces_only_estimate_once_using_historical_fx(): void
    {
        $event = $this->event(['fx_rate_to_egp' => 50, 'cost_egp' => 1.25]);
        $completedAt = $event->completed_at->toISOString();
        Http::fake(['openrouter.ai/*' => Http::response(['data' => [
            'id' => 'gen-original', 'total_cost' => .001234,
            'created_at' => '2026-09-08T10:00:00Z',
        ]])]);

        $service = app(OpenRouterCostReconciliationService::class);
        self::assertSame('confirmed', $service->reconcile($event->id));
        self::assertSame('skipped', $service->reconcile($event->id));
        $event->refresh();
        self::assertSame('0.001234', $event->cost_usd);
        self::assertSame('0.061700', $event->cost_egp);
        self::assertSame('0.025000', $event->reserved_cost_usd);
        self::assertSame('50.0000', $event->fx_rate_to_egp);
        self::assertSame($completedAt, $event->completed_at->toISOString());
        self::assertSame('provider', $event->metadata['cost_usage_source']);
        self::assertSame('openrouter_generation', $event->metadata['provider_cost_source']);
        self::assertFalse($event->metadata['entitlement_delivered']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://openrouter.ai/api/v1/generation?id=gen-original');
    }

    public function test_explicit_zero_is_confirmed_without_inventing_an_fx_rate(): void
    {
        $event = $this->event();
        Http::fake(['openrouter.ai/*' => Http::response(['data' => [
            'id' => 'gen-original', 'total_cost' => 0,
        ]])]);
        self::assertSame('confirmed', app(OpenRouterCostReconciliationService::class)->reconcile($event->id));
        self::assertSame('0.000000', $event->fresh()->cost_usd);
        self::assertNull($event->fresh()->fx_rate_to_egp);
        self::assertNull($event->fresh()->cost_egp);
    }

    public function test_not_ready_retries_later_and_never_changes_budget_estimate_on_failure(): void
    {
        $event = $this->event();
        Http::fake(['openrouter.ai/*' => Http::sequence()->push([], 404)
            ->push(['data' => ['id' => 'gen-original', 'total_cost' => .003]])]);
        $service = app(OpenRouterCostReconciliationService::class);
        self::assertSame('pending', $service->reconcile($event->id));
        self::assertSame('0.025000', $event->fresh()->cost_usd);
        self::assertSame('reservation_fallback', $event->fresh()->metadata['cost_usage_source']);
        self::assertSame('skipped', $service->reconcile($event->id));
        $this->travel(61)->minutes();
        self::assertSame('confirmed', $service->reconcile($event->id));
        self::assertNull($event->fresh()->cost_egp);
        Http::assertSentCount(2);
    }

    public function test_invalid_or_failed_provider_results_remain_unknown(): void
    {
        $cases = [
            [200, ['data' => ['id' => 'gen-other', 'total_cost' => .01]]],
            [200, ['data' => ['id' => 'gen-original']]],
            [200, ['data' => ['id' => 'gen-original', 'total_cost' => -1]]],
            [200, ['data' => ['id' => 'gen-original', 'total_cost' => 'invalid']]],
            [200, ['data' => ['id' => 'gen-original', 'total_cost' => true]]],
            [200, ['data' => ['id' => 'gen-original', 'total_cost' => 1e30]]],
            [401, []], [500, []],
        ];
        $sequence = Http::sequence();
        foreach ($cases as [$status, $body]) $sequence->push($body, $status);
        Http::fake(['openrouter.ai/*' => $sequence]);
        foreach ($cases as [$status, $body]) {
            $event = $this->event();
            self::assertSame('pending', app(OpenRouterCostReconciliationService::class)->reconcile($event->id));
            self::assertSame('reservation_fallback', $event->fresh()->metadata['cost_usage_source']);
            self::assertSame('0.025000', $event->fresh()->cost_usd);
        }
    }

    public function test_rate_limit_defers_the_whole_batch_without_consuming_other_generations(): void
    {
        $first = $this->event();
        $second = $this->event(['provider_request_id' => 'gen-second']);
        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push([], 429, ['Retry-After' => '7200'])
            ->push(['data' => ['id' => 'gen-second', 'total_cost' => .002]])]);
        $this->artisan('ai:reconcile-provider-costs --limit=2')->assertSuccessful();
        Http::assertSentCount(1);
        self::assertSame('skipped', app(OpenRouterCostReconciliationService::class)->reconcile($second->id));
        self::assertSame('0.025000', $first->fresh()->cost_usd);
        $this->travel(121)->minutes();
        self::assertSame('confirmed', app(OpenRouterCostReconciliationService::class)->reconcile($second->id));
    }

    public function test_missing_identity_confirmed_cost_and_unsettled_work_never_make_http_requests(): void
    {
        foreach ([
            ['provider_request_id' => null],
            ['metadata' => ['cost_usage_source' => 'provider']],
            ['status' => 'reserved'],
        ] as $attributes) {
            self::assertSame('skipped', app(OpenRouterCostReconciliationService::class)
                ->reconcile($this->event($attributes)->id));
        }
        Http::assertNothingSent();
    }

    public function test_metadata_generation_identity_is_supported_and_conflicting_ids_are_not_guessed(): void
    {
        $metadata = ['cost_usage_source' => 'reservation_fallback', 'provider_generation_id' => 'gen-metadata'];
        $event = $this->event(['provider_request_id' => null, 'metadata' => $metadata]);
        Http::fake(['openrouter.ai/*' => Http::response(['data' => ['id' => 'gen-metadata', 'total_cost' => .004]])]);
        self::assertSame('confirmed', app(OpenRouterCostReconciliationService::class)->reconcile($event->id));
        $conflict = $this->event(['metadata' => $metadata]);
        self::assertSame('skipped', app(OpenRouterCostReconciliationService::class)->reconcile($conflict->id));
        Http::assertSentCount(1);
    }

    public function test_concurrent_confirmation_is_not_overwritten_by_a_late_generation_response(): void
    {
        $event = $this->event();
        Http::fake(function () use ($event) {
            $event->update(['cost_usd' => .008, 'metadata' => ['cost_usage_source' => 'provider']]);
            return Http::response(['data' => ['id' => 'gen-original', 'total_cost' => .002]]);
        });
        self::assertSame('skipped', app(OpenRouterCostReconciliationService::class)->reconcile($event->id));
        self::assertSame('0.008000', $event->fresh()->cost_usd);
    }

    public function test_connection_failure_releases_lock_and_remains_pending_until_explicit_later_scan(): void
    {
        $event = $this->event();
        $attempt = 0;
        Http::fake(function () use (&$attempt) {
            if (++$attempt === 1) throw new ConnectionException('Local simulated failure');
            return Http::response(['data' => ['id' => 'gen-original', 'total_cost' => .006]]);
        });
        $service = app(OpenRouterCostReconciliationService::class);
        self::assertSame('pending', $service->reconcile($event->id));
        self::assertSame('connection_unavailable', $event->fresh()->metadata['cost_reconciliation']['status']);
        $this->travel(61)->minutes();
        self::assertSame('confirmed', $service->reconcile($event->id));
        self::assertSame(2, $attempt);
    }

    public function test_command_bounds_requests_without_unresolvable_old_rows_starving_valid_receipts(): void
    {
        $this->event(['provider_request_id' => '']);
        $this->event(['metadata' => ['provider_generation_id' => 'conflicting-id']]);
        $first = $this->event(['provider_request_id' => 'gen-first']);
        $second = $this->event(['provider_request_id' => 'gen-second']);
        Http::fake(fn ($request) => Http::response(['data' => [
            'id' => $request['id'], 'total_cost' => .002,
        ]]));
        $this->artisan('ai:reconcile-provider-costs --limit=1')->assertSuccessful();
        self::assertSame('provider', $first->fresh()->metadata['cost_usage_source']);
        self::assertSame('reservation_fallback', $second->fresh()->metadata['cost_usage_source']);
        Http::assertSentCount(1);
        $this->artisan('ai:reconcile-provider-costs --limit=1')->assertSuccessful();
        self::assertSame('provider', $second->fresh()->metadata['cost_usage_source']);
        Http::assertSentCount(2);
    }

    private function event(array $attributes = []): AiUsageEvent
    {
        return AiUsageEvent::create($attributes + [
            'status' => 'completed', 'provider_request_id' => 'gen-original',
            'cost_usd' => .025, 'reserved_cost_usd' => .025,
            'completed_at' => now()->subDay(),
            'metadata' => ['cost_usage_source' => 'reservation_fallback', 'entitlement_delivered' => false],
        ]);
    }
}
