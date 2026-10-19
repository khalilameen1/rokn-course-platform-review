<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProductAnalyticsController;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Services\AdminProductAnalyticsReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminProductAnalyticsReadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_composition_does_not_resolve_a_controller_or_write_data(): void
    {
        $this->app->bind(ProductAnalyticsController::class, static function (): never {
            throw new \LogicException('Analytics reading cannot resolve a controller.');
        });
        Http::preventStrayRequests();
        Bus::fake();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $report = app(AdminProductAnalyticsReadService::class)->report(periodKey: '7d');
        $writes = array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']) === 1);
        DB::disableQueryLog();
        self::assertSame([], $writes);
        self::assertSame('7d', $report['period']->key);
        self::assertNotNull($report['paymentChannelReport']);
        self::assertNotNull($report['invoiceReport']);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_course_report_keeps_canonical_archived_identity_and_excludes_platform_cash(): void
    {
        $canonical = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس أصلي', 'is_coming_soon' => true]);
        $revision = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'نسخة تحرير', 'is_coming_soon' => true]);
        CourseAuthoringRevision::query()->create(['canonical_course_id' => $canonical->id, 'revision_course_id' => $revision->id,
            'base_authoring_version' => 1, 'status' => CourseAuthoringRevision::DRAFT, 'active_slot' => 'course:'.$canonical->id, 'clone_key' => (string) Str::uuid()]);
        $canonical->delete();
        $report = app(AdminProductAnalyticsReadService::class)->report((int) $revision->id, 'all');
        self::assertSame((int) $canonical->id, $report['filters']['course_id']);
        self::assertSame([$canonical->id], $report['courses']->modelKeys());
        self::assertNull($report['paymentChannelReport']);
        self::assertNull($report['invoiceReport']);
        self::assertSame('unavailable', $report['paymentChanges']['gross']['status']);
    }
}
