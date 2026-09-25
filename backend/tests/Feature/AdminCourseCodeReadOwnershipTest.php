<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CourseCodeController;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\Lesson;
use App\Services\AdminCourseCodeAuthoringService;
use App\Services\AdminCourseCodeReadService;
use App\Services\CourseCodeRedemptionService;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ProductionCourseCodeSchema;
use Tests\TestCase;

final class AdminCourseCodeReadOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        ProductionCourseCodeSchema::applySqliteBridge();
        Http::preventStrayRequests();
        Bus::fake();
        app()->setLocale('ar');
        foreach ([CourseCodeController::class, AdminCourseCodeAuthoringService::class, CourseCodeRedemptionService::class] as $writer) {
            $this->app->bind($writer, static function (): never {
                throw new \LogicException('Code reads must not resolve HTTP or write owners.');
            });
        }
    }

    public function test_screen_csv_and_pdf_share_selection_without_mutation(): void
    {
        $selected = $this->code(['name' => 'دفعة التصميم', 'is_active' => true]);
        $this->code(['name' => 'دفعة التصميم', 'is_active' => false]);
        $this->code(['name' => 'دفعة أخرى', 'is_active' => true]);
        $before = CourseCode::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $filters = ['name' => 'التصميم', 'status' => 'active'];
        $reader = app(AdminCourseCodeReadService::class);
        DB::enableQueryLog();
        try {
            self::assertSame([$selected->id], $reader->query($filters)->pluck('id')->all());
            $csv = iterator_to_array($reader->csvRows($filters));
            self::assertCount(2, $csv);
            self::assertSame($selected->code, $csv[1][0]);
            self::assertSame([$selected->code], $reader->pdfRows($filters)->pluck('code')->all());
            foreach (DB::getQueryLog() as $query) {
                self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|drop)\b/i', $query['query']);
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        self::assertSame($before, CourseCode::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_local_day_filter_includes_start_and_excludes_next_day_for_both_date_fields(): void
    {
        config(['app.business_timezone' => 'Africa/Cairo']);
        [$from, $to] = BusinessClock::localDayRangeUtc('2026-09-25');
        $before = $this->code(['start_date' => $from->subSecond(), 'expiry_date' => $from->subSecond()]);
        $start = $this->code(['start_date' => $from, 'expiry_date' => $from]);
        $end = $this->code(['start_date' => $to->subSecond(), 'expiry_date' => $to->subSecond()]);
        $next = $this->code(['start_date' => $to, 'expiry_date' => $to]);
        foreach (['start_date', 'expiry_date'] as $field) {
            $ids = app(AdminCourseCodeReadService::class)->query([$field => '2026-09-25'])->orderBy('id')->pluck('id')->all();
            self::assertSame([$start->id, $end->id], $ids);
            self::assertNotContains($before->id, $ids);
            self::assertNotContains($next->id, $ids);
        }
    }

    public function test_csv_stream_crosses_chunk_boundary_while_pdf_stays_bounded(): void
    {
        $rows = [];
        for ($i = 1; $i <= 505; $i++) {
            $rows[] = [
                'code' => sprintf('READ-%04d', $i), 'name' => 'دفعة كبيرة', 'type' => 'course',
                'max_uses' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('course_codes')->insert($rows);
        $reader = app(AdminCourseCodeReadService::class);
        $stream = $reader->csvRows([]);
        self::assertInstanceOf(\Generator::class, $stream);
        $csv = iterator_to_array($stream);
        self::assertCount(506, $csv);
        self::assertSame('الكود', $csv[0][0]);
        self::assertSame('READ-0505', $csv[1][0]);
        self::assertSame('READ-0001', $csv[505][0]);
        self::assertCount(505, array_unique(array_column(array_slice($csv, 1), 0)));
        self::assertCount(AdminCourseCodeReadService::PDF_LIMIT + 1, $reader->pdfRows([]));
    }

    public function test_csv_escapes_formula_like_text_and_retains_grant_metadata(): void
    {
        $this->code([
            'code' => '=FORMULA', 'name' => '+NAME', 'is_grant' => true,
            'allowed_email_domains' => ['college.example'], 'is_active' => false,
        ]);
        $csv = iterator_to_array(app(AdminCourseCodeReadService::class)->csvRows([]));
        self::assertSame("'=FORMULA", $csv[1][0]);
        self::assertSame("'+NAME", $csv[1][1]);
        self::assertSame('نعم', $csv[1][8]);
        self::assertSame('college.example', $csv[1][9]);
        self::assertSame('معطل', $csv[1][10]);
    }

    public function test_lesson_options_and_historical_pdf_rows_use_the_actual_lesson_title(): void
    {
        $course = Course::factory()->create(['tenant_id' => 1, 'is_coming_soon' => true]);
        $later = Lesson::query()->create([
            'list_id' => $course->id, 'title_ar' => 'المقطع الثاني', 'title_en' => 'Second',
            'title' => null, 'priority' => 2,
        ]);
        $first = Lesson::query()->create([
            'list_id' => $course->id, 'title_ar' => 'المقطع الأول', 'title_en' => 'First',
            'title' => null, 'priority' => 1,
        ]);
        $this->code(['type' => 'lesson', 'lesson_id' => $first->id]);
        $reader = app(AdminCourseCodeReadService::class);
        self::assertSame([
            ['id' => $first->id, 'title' => 'المقطع الأول'],
            ['id' => $later->id, 'title' => 'المقطع الثاني'],
        ], $reader->lessonOptions($course->id)->all());
        self::assertSame('المقطع الأول', $reader->pdfRows([])->sole()->target_content_name);
    }

    private function code(array $values = []): CourseCode
    {
        return CourseCode::query()->create([
            'code' => (string) Str::uuid(), 'type' => 'course', 'max_uses' => 100, 'is_active' => true,
            ...$values,
        ]);
    }
}
