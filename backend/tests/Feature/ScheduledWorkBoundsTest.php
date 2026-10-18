<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\OperationalRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ScheduledWorkBoundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_keeps_deleting_chunks_until_the_backlog_is_gone(): void
    {
        $this->productEvents(250, now()->subDays(181));
        $this->productEvents(5, now()->subDays(10));

        $this->artisan('data:prune-operational', ['--limit' => 100])
            ->expectsOutputToContain('product_events=250')
            ->assertSuccessful();

        self::assertSame(5, DB::table('product_events')->count());
        self::assertSame(0, DB::table('product_events')->where('received_at', '<=', now()->subDays(180))->count());
    }

    public function test_exhausted_time_budget_still_runs_one_chunk_per_table(): void
    {
        $this->productEvents(250, now()->subDays(181));

        $this->artisan('data:prune-operational', ['--limit' => 100, '--max-seconds' => 0])
            ->expectsOutputToContain('product_events=100')
            ->assertSuccessful();

        self::assertSame(150, DB::table('product_events')->count());
    }

    public function test_resume_reaches_new_checkouts_while_old_ones_stay_stuck(): void
    {
        // Missing owners can never resume: the permanently stuck case.
        $stuck = [$this->checkout(), $this->checkout(), $this->checkout()];
        $completed = $this->checkout('completed');

        $this->artisan('courses:resume-checkouts', ['--limit' => 2])->assertExitCode(0);
        self::assertSame([1, 1, 0], $this->attempts($stuck));

        $fresh = $this->checkout();
        $this->artisan('courses:resume-checkouts', ['--limit' => 2])->assertExitCode(0);

        // The never-attempted rows are reached before the backed-off ones.
        self::assertSame([1, 1, 1], $this->attempts($stuck));
        self::assertSame([1], $this->attempts([$fresh]));
        self::assertSame([0], $this->attempts([$completed]));
        self::assertNull(DB::table('course_checkouts')->where('id', $completed)->value('next_resume_at'));

        // Nothing is due until the first backoff (one minute) passes.
        $this->artisan('courses:resume-checkouts', ['--limit' => 2])->assertExitCode(0);
        self::assertSame([1, 1, 1, 1], $this->attempts([...$stuck, $fresh]));

        $this->travel(2)->minutes();
        $this->artisan('courses:resume-checkouts', ['--limit' => 10])->assertExitCode(0);
        self::assertSame([2, 2, 2, 2], $this->attempts([...$stuck, $fresh]));
        $next = DB::table('course_checkouts')->where('id', $fresh)->value('next_resume_at');
        self::assertEqualsWithDelta(now()->addMinutes(2)->timestamp, strtotime((string) $next), 5);
    }

    public function test_runtime_monitor_single_pass_aggregates_keep_their_values(): void
    {
        $now = now();
        $rows = [
            // Actionable permanent failures for two users.
            ['user_id' => 1, 'push_failed_at' => $now->copy()->subDays(3), 'push_failure_code' => 'provider_rejected'],
            ['user_id' => 1, 'push_failed_at' => $now->copy()->subDay(), 'push_failure_code' => 'provider_rejected'],
            ['user_id' => 2, 'push_failed_at' => $now->copy()->subHours(2), 'push_failure_code' => 'attempts_exhausted'],
            // Benign dead letters, a delivered push and a NULL code are excluded.
            ['user_id' => 3, 'push_failed_at' => $now->copy()->subDays(9), 'push_failure_code' => 'no_registered_device'],
            ['user_id' => 3, 'push_failed_at' => $now->copy()->subDays(8), 'push_failure_code' => 'preference_disabled'],
            ['user_id' => 4, 'push_failed_at' => $now->copy()->subDays(7), 'push_failure_code' => null],
            ['user_id' => 5, 'push_failed_at' => $now->copy()->subDays(6), 'push_failure_code' => 'provider_rejected', 'push_sent_at' => $now],
            // Stale in-flight pushes for two users, plus one still fresh.
            ['user_id' => 6, 'created_at' => $now->copy()->subHour()],
            ['user_id' => 6, 'created_at' => $now->copy()->subMinutes(30)],
            ['user_id' => 7, 'created_at' => $now->copy()->subMinutes(20)],
            ['user_id' => 8, 'created_at' => $now->copy()->subMinutes(5)],
        ];
        $users = [];
        foreach (range(1, 8) as $index) {
            $users[$index] = User::query()->forceCreate([
                'name' => 'Monitor '.$index,
                'email' => 'monitor-'.$index.'@rokn.test',
                'role' => 'client',
                'active' => true,
            ])->id;
        }
        foreach ($rows as $row) {
            DB::table('student_notifications')->insert(['user_id' => $users[$row['user_id']]] + $row + [
                'notification_type' => 'course_update',
                'title_ar' => 'تحديث',
                'title_en' => 'Update',
                'message_ar' => 'تحديث',
                'message_en' => 'Update',
                'is_read' => 0,
                'push_sent_at' => null,
                'push_failed_at' => null,
                'push_failure_code' => null,
                'created_at' => $now->copy()->subDays(10),
                'updated_at' => $now,
            ]);
        }

        $notifications = app(OperationalRuntimeService::class)->snapshot()['notifications'];

        self::assertSame(3, $notifications['failed_pushes']);
        self::assertSame(2, $notifications['failed_pushes_affected']);
        self::assertSame(
            $now->copy()->subDays(3)->format('Y-m-d H:i:s'),
            substr((string) $notifications['failed_pushes_oldest_at'], 0, 19)
        );
        self::assertSame(3, $notifications['stale']);
        self::assertSame(2, $notifications['stale_affected']);
        self::assertSame(
            $now->copy()->subHour()->format('Y-m-d H:i:s'),
            substr((string) $notifications['stale_oldest_at'], 0, 19)
        );
    }

    public function test_empty_monitor_aggregates_keep_their_zero_and_null_shape(): void
    {
        $notifications = app(OperationalRuntimeService::class)->snapshot()['notifications'];

        self::assertSame(0, $notifications['failed_pushes']);
        self::assertSame(0, $notifications['failed_pushes_affected']);
        self::assertNull($notifications['failed_pushes_oldest_at']);
        self::assertSame(0, $notifications['stale']);
        self::assertSame(0, $notifications['stale_affected']);
        self::assertNull($notifications['stale_oldest_at']);
    }

    public function test_scheduled_scans_have_left_prefix_indexes(): void
    {
        self::assertTrue(Schema::hasIndex('course_checkouts', ['status', 'next_resume_at', 'id']));
        self::assertTrue(Schema::hasIndex('orders', ['payment_method', 'created_at', 'id']));
        self::assertTrue(Schema::hasIndex(
            'student_notifications',
            ['push_sent_at', 'push_failure_code', 'push_failed_at', 'user_id']
        ));
        self::assertTrue(Schema::hasIndex('playback_sessions', ['metrics_rolled_up_at', 'ended_at', 'id']));
    }

    public function test_bounding_migration_is_resumable_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_10_17_120000_bound_scheduled_background_work.php');

        $migration->up();
        $migration->down();
        self::assertFalse(Schema::hasColumn('course_checkouts', 'next_resume_at'));
        self::assertFalse(Schema::hasIndex('playback_sessions', 'playback_sessions_rollup_pending'));
        $migration->up();
        $migration->up();
        self::assertTrue(Schema::hasColumns('course_checkouts', ['resume_attempts', 'next_resume_at']));
        self::assertTrue(Schema::hasIndex('orders', 'orders_provider_reconcile_window'));
    }

    private function productEvents(int $count, \DateTimeInterface $receivedAt): void
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'event_id' => (string) Str::uuid(),
                'event_name' => 'lesson_opened',
                'occurred_at' => $receivedAt,
                'received_at' => $receivedAt,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('product_events')->insert($chunk);
        }
    }

    private function checkout(string $status = 'pending_payment'): int
    {
        return (int) DB::table('course_checkouts')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'user_id' => 999999,
            'course_id' => 1,
            'status' => $status,
            'channel' => 'direct',
            'terms' => '{}',
            'terms_hash' => str_repeat('a', 64),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<int> $ids @return list<int> */
    private function attempts(array $ids): array
    {
        return array_map(
            fn (int $id): int => (int) DB::table('course_checkouts')->where('id', $id)->value('resume_attempts'),
            $ids
        );
    }
}
