<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\FeedbackReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminSupportExportFilterParityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Course $course;
    private FeedbackReport $wanted;
    private FeedbackReport $guest;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.business_timezone', 'Africa/Cairo');
        Carbon::setTestNow('2026-09-09 12:00:00');
        Http::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->admin = User::query()->forceCreate([
            'name' => 'Support administrator', 'email' => 'admin@example.test',
            'role' => 'admin', 'active' => true,
        ]);
        $learner = User::query()->forceCreate([
            'name' => 'Student name', 'email' => 'learner@example.test',
            'role' => 'user', 'active' => true,
        ]);
        $this->course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'الكورس', 'price' => 400,
        ]);
        $this->wanted = $this->report([
            'user_id' => $learner->id, 'course_id' => $this->course->id,
            'app_version' => '1.0.77', 'assigned_to' => $this->admin->id,
            'first_response_due_at' => now()->subHour(),
        ]);
        $this->guest = $this->report([
            'requester_email' => 'guest@example.test', 'app_version' => '1.0.76',
            'first_response_due_at' => now()->addHour(),
        ]);
        $this->report([
            'app_version' => '1.0.76', 'status' => 'resolved',
            'first_response_due_at' => now()->subHour(),
        ]);
        $this->report([
            'app_version' => '1.0.76', 'last_staff_message_at' => now()->subMinutes(5),
            'first_response_due_at' => now()->subHour(),
        ]);
        $this->actingAs($this->admin, 'web');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function filters(): array
    {
        return array_map(static fn (string $kind): array => [$kind], [
            'version', 'overdue', 'course', 'learner_email', 'guest_email', 'combined',
        ]);
    }

    #[DataProvider('filters')]
    public function test_export_contains_the_same_selected_cases_as_the_inbox(string $kind): void
    {
        $filters = match ($kind) {
            'version' => ['app_version' => '1.0.77'],
            'overdue' => ['overdue' => '1'],
            'course' => ['course_id' => $this->course->id],
            'learner_email' => ['q' => 'learner@example.test'],
            'guest_email' => ['q' => 'guest@example.test'],
            'combined' => [
                'app_version' => '1.0.77', 'overdue' => '1', 'category' => 'bug',
                'status' => 'new', 'priority' => 'high', 'assigned_to' => $this->admin->id,
                'from' => '2026-09-09', 'to' => '2026-09-09',
            ],
        };
        $expected = $kind === 'guest_email' ? $this->guest : $this->wanted;
        $inbox = $this->get(route('admin.feedback.index', $filters))->assertOk();
        self::assertSame([$expected->public_id], $inbox->viewData('reports')->pluck('public_id')->all());
        $inbox->assertSee(route('admin.feedback.export', $filters));

        $rows = $this->exportRows($filters);
        self::assertSame([strtoupper(substr($expected->public_id, -8))], array_column($rows, 0));
        Http::assertNothingSent();
    }

    public function test_both_views_use_the_same_half_open_cairo_day(): void
    {
        $records = [];
        foreach (['2026-09-08 20:59:59', '2026-09-08 21:00:00', '2026-09-09 20:59:59', '2026-09-09 21:00:00'] as $instant) {
            $records[] = $this->report(['app_version' => 'calendar', 'created_at' => $instant]);
        }
        $filters = ['app_version' => 'calendar', 'from' => '2026-09-09', 'to' => '2026-09-09'];
        $reports = $this->get(route('admin.feedback.index', $filters))->assertOk()->viewData('reports');
        self::assertEqualsCanonicalizing([$records[1]->id, $records[2]->id], $reports->pluck('id')->all());
        self::assertSame(
            array_map(static fn (FeedbackReport $report): string => strtoupper(substr($report->public_id, -8)), [$records[1], $records[2]]),
            array_column($this->exportRows($filters), 0)
        );
    }

    public function test_export_keeps_filters_across_chunks_without_exporting_only_the_current_page(): void
    {
        $records = [];
        for ($index = 0; $index < 501; $index++) {
            $records[] = [
                'public_id' => (string) Str::ulid(), 'category' => 'bug', 'status' => 'new',
                'priority' => 'normal', 'message' => 'بلاغ دعم', 'version' => 1,
                'app_version' => 'many', 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        FeedbackReport::query()->insert($records);
        $filters = ['app_version' => 'many', 'page' => 2];
        $reports = $this->get(route('admin.feedback.index', $filters))->assertOk()->viewData('reports');
        self::assertSame(30, $reports->count());
        self::assertSame(501, $reports->total());
        self::assertSame(
            array_map(static fn (array $record): string => strtoupper(substr($record['public_id'], -8)), $records),
            array_column($this->exportRows($filters), 0)
        );
    }

    public function test_invalid_filters_are_rejected_by_both_endpoints(): void
    {
        foreach (['index', 'export'] as $action) {
            foreach (['overdue' => 'tomorrow', 'course_id' => 'invalid', 'app_version' => str_repeat('x', 33)] as $field => $value) {
                $this->getJson(route('admin.feedback.'.$action, [$field => $value]))
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
    }

    public function test_moderators_cannot_open_or_export_support_cases(): void
    {
        $this->admin->forceFill(['role' => 'moderator'])->save();
        foreach (['index', 'export'] as $action) {
            $this->get(route('admin.feedback.'.$action))->assertForbidden();
        }
    }

    private function exportRows(array $filters): array
    {
        $response = $this->get(route('admin.feedback.export', $filters))->assertOk();
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $response->streamedContent());
        rewind($stream);
        fgetcsv($stream, escape: '');
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }

    private function report(array $attributes): FeedbackReport
    {
        return FeedbackReport::query()->forceCreate([
            'public_id' => (string) Str::ulid(), 'category' => 'bug',
            'status' => 'new', 'priority' => 'high', 'message' => 'يتوقف فتح المقطع',
            'version' => 1, ...$attributes,
        ]);
    }
}
