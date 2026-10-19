<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FeedbackReport;
use App\Models\User;
use App\Services\AdminSupportCaseReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminSupportCaseReadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_relation_queries_stay_constant_as_visible_rows_grow(): void
    {
        $user = User::query()->forceCreate(['name' => 'طالب', 'email' => 'support-read@example.test', 'role' => 'admin', 'active' => true]);
        $this->report(['user_id' => $user->id, 'assigned_to' => $user->id]);
        $small = $this->readQueryCount();
        for ($i = 0; $i < 12; $i++) {
            $this->report(['user_id' => $user->id, 'assigned_to' => $user->id]);
        }
        self::assertSame($small, $this->readQueryCount());
    }

    public function test_case_detail_resolves_portfolio_owner_without_changing_case_or_messages(): void
    {
        $owner = User::query()->forceCreate(['name' => 'صاحب الأعمال', 'role' => 'client', 'active' => true]);
        $report = $this->report(['screen_key' => 'public_portfolio_report', 'context' => ['portfolio_owner_id' => $owner->id]]);
        $before = $report->refresh()->getAttributes();
        $details = app(AdminSupportCaseReadService::class)->show($report);
        self::assertSame($owner->id, $details['portfolioOwner']->id);
        self::assertSame($before, $report->fresh()->getAttributes());
        self::assertSame(0, $report->messages()->count());
        self::assertSame(0, $report->events()->count());
    }

    private function readQueryCount(): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page = app(AdminSupportCaseReadService::class)->query([])->paginate(30);
        foreach ($page as $report) {
            self::assertSame('طالب', $report->user->name);
            self::assertSame('طالب', $report->assignee->name);
            self::assertNull($report->course);
        }
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function report(array $data): FeedbackReport
    {
        return FeedbackReport::query()->create(['public_id' => (string) Str::ulid(), 'category' => 'bug', 'status' => 'new',
            'priority' => 'normal', 'message' => 'بلاغ للمراجعة', 'version' => 1, ...$data]);
    }
}
