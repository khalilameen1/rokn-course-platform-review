<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductEvent;
use App\Services\PurchaseFunnelReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseFunnelReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));
        Schema::create('product_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->char('actor_key', 64)->nullable();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('event_name', 64);
            $table->string('source', 32);
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('product_events');
        parent::tearDown();
    }

    public function test_independent_actors_cannot_form_a_conversion(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->event('checkout_quoted', 'a', 1, $at);
        $this->event('purchase_started', 'b', 1, $at->addMinute());
        $this->event('purchase_completed', 'c', 1, $at->addMinutes(2));
        self::assertSame([1, 0, 0], $this->counts($this->report()));
    }

    public function test_unverified_client_purchase_events_do_not_form_a_committed_conversion(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->event('checkout_quoted', 'a', 1, $at);
        $this->event('purchase_started', 'a', 1, $at->addMinute(), 'app');
        $this->event('purchase_completed', 'a', 1, $at->addMinutes(2), 'app');
        self::assertSame([1, 0, 0], $this->counts($this->report()));
        $this->event('purchase_started', 'a', 1, $at->addMinute());
        $this->event('purchase_completed', 'a', 1, $at->addMinutes(2));
        self::assertSame([1, 1, 1], $this->counts($this->report()));
    }

    public function test_device_clock_skew_cannot_move_the_server_quote_cohort_or_reverse_conversion(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->event('paywall_viewed', 'a', 1, $at->addMinutes(5), 'app');
        $this->event('paywall_viewed', 'b', 1, $at->subMinutes(5), 'app');
        $this->journey('a', 1, $at);
        $this->journey('b', 1, $at);
        self::assertSame([2, 2, 2], $this->counts($this->report()));
    }

    public function test_skipping_optional_preview_and_reward_tasks_does_not_erase_a_purchase(): void
    {
        $this->journey('a', 1, CarbonImmutable::now('UTC')->subHour());
        $result = $this->report();
        self::assertSame([1, 1, 1], $this->counts($result));
        self::assertSame(100.0, $result['steps'][2]['conversion_from_entry']);
        self::assertSame(0, $result['pending_actors']);
    }

    public function test_courses_are_not_stitched_together_in_the_all_courses_report(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->event('checkout_quoted', 'a', 1, $at);
        $this->event('purchase_started', 'a', 2, $at->addMinute());
        $this->event('purchase_completed', 'a', 2, $at->addMinutes(2));
        self::assertSame([1, 0, 0], $this->counts($this->report()));
        self::assertSame([0, 0, 0], $this->counts($this->report(2)));
    }

    public function test_repeated_visits_and_successful_courses_count_each_actor_once(): void
    {
        $at = CarbonImmutable::now('UTC')->subHours(3);
        $this->journey('a', 1, $at);
        $this->journey('a', 1, $at->addHour());
        $this->journey('a', 2, $at->addHours(2));
        self::assertSame([1, 1, 1], $this->counts($this->report()));
        self::assertSame([1, 1, 1], $this->counts($this->report(2)));
    }

    public function test_a_failed_early_visit_does_not_hide_a_later_in_window_attempt(): void
    {
        $this->event('checkout_quoted', 'a', 1, CarbonImmutable::now('UTC')->subDays(20));
        $this->journey('a', 1, CarbonImmutable::now('UTC')->subDay());
        self::assertSame([1, 1, 1], $this->counts($this->report()));
    }

    public function test_outside_window_completion_is_not_attributed_to_the_entry(): void
    {
        $at = CarbonImmutable::now('UTC')->subDays(20);
        $this->event('checkout_quoted', 'a', 1, $at);
        $this->event('purchase_started', 'a', 1, $at->addMinute());
        $this->event('purchase_completed', 'a', 1, $at->addDays(14)->addSecond());
        $result = $this->report();
        self::assertSame([1, 1, 0], $this->counts($result));
        self::assertSame(0, $result['pending_actors']);
    }

    public function test_exact_conversion_deadline_is_included(): void
    {
        $at = CarbonImmutable::now('UTC')->subDays(20);
        $this->event('checkout_quoted', 'a', 1, $at);
        $this->event('purchase_started', 'a', 1, $at->addMinute());
        $this->event('purchase_completed', 'a', 1, $at->addDays(14));
        self::assertSame([1, 1, 1], $this->counts($this->report()));
    }

    public function test_entry_period_is_half_open_but_conversion_can_follow_its_end(): void
    {
        $period = ReportPeriod::fromKey('7d', CarbonImmutable::now('UTC')->subDays(10));
        $this->journey('outside-start', 1, $period->start->subSecond());
        $this->event('checkout_quoted', 'included-start', 1, $period->start);
        $this->journey('excluded-end', 1, $period->end);
        $this->event('checkout_quoted', 'after-period', 1, $period->end->subSecond());
        $this->event('purchase_started', 'after-period', 1, $period->end->addMinute());
        $this->event('purchase_completed', 'after-period', 1, $period->end->addMinutes(2));
        self::assertSame([2, 1, 1], $this->counts($this->report(null, $period)));
    }

    public function test_occurred_time_not_delayed_receipt_order_controls_the_sequence(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->event('purchase_completed', 'a', 1, $at->addMinutes(2));
        $this->event('purchase_started', 'a', 1, $at->addMinute());
        $this->event('checkout_quoted', 'a', 1, $at);
        self::assertSame([1, 1, 1], $this->counts($this->report()));
        $this->event('purchase_started', 'b', 1, $at->subMinute());
        $this->event('checkout_quoted', 'b', 1, $at);
        $this->event('purchase_completed', 'b', 1, $at->addMinutes(2));
        self::assertSame([2, 1, 1], $this->counts($this->report()));
    }

    public function test_same_second_events_use_the_declared_storage_precision(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        foreach (['purchase_completed', 'purchase_started', 'checkout_quoted'] as $event) {
            $this->event($event, 'a', 1, $at);
        }
        self::assertSame([1, 1, 1], $this->counts($this->report()));
    }

    public function test_missing_identity_course_or_future_events_do_not_enter_the_funnel(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->event('checkout_quoted', null, 1, $at);
        $this->event('checkout_quoted', 'a', null, $at);
        $this->journey('future', 1, CarbonImmutable::now('UTC'));
        $result = $this->report();
        self::assertSame([0, 0, 0], $this->counts($result));
        self::assertNull($result['steps'][0]['conversion_from_entry']);
        self::assertNull($result['steps'][1]['conversion_from_previous']);
    }

    public function test_pending_time_is_not_reported_as_final_abandonment_or_counted_twice(): void
    {
        $at = CarbonImmutable::now('UTC')->subHour();
        $this->journey('completed', 1, $at);
        $this->event('checkout_quoted', 'completed', 2, $at->addMinutes(3));
        $this->event('checkout_quoted', 'pending', 1, $at);
        $this->event('checkout_quoted', 'pending', 2, $at->addMinutes(3));
        $result = $this->report();
        self::assertSame([2, 1, 1], $this->counts($result));
        self::assertSame(1, $result['pending_actors']);
        self::assertSame(50.0, $result['steps'][1]['conversion_from_entry']);
        self::assertSame(1, $result['steps'][1]['not_reached_yet']);
    }

    private function report(?int $courseId = null, ?ReportPeriod $period = null): array
    {
        return app(PurchaseFunnelReportService::class)->report($courseId, $period ?? ReportPeriod::fromKey('30d'));
    }

    private function counts(array $report): array
    {
        return array_column($report['steps'], 'actors');
    }

    private function journey(string $actor, int $course, CarbonImmutable $at): void
    {
        $this->event('checkout_quoted', $actor, $course, $at);
        $this->event('purchase_started', $actor, $course, $at->addMinute());
        $this->event('purchase_completed', $actor, $course, $at->addMinutes(2));
    }

    private function event(string $name, ?string $actor, ?int $course, CarbonImmutable $at, string $source = 'server'): void
    {
        ProductEvent::create([
            'event_id' => (string) Str::uuid(), 'source' => $source,
            'actor_key' => $actor === null ? null : hash('sha256', $actor),
            'course_id' => $course, 'event_name' => $name,
            'occurred_at' => $at, 'received_at' => CarbonImmutable::now('UTC'),
        ]);
    }
}
