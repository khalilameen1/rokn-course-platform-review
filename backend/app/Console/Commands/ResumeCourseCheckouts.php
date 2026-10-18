<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CourseCheckout;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\CourseCheckoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class ResumeCourseCheckouts extends Command
{
    /** Backoff ceiling: a stuck row is retried at most this often. */
    private const MAX_BACKOFF_MINUTES = 30;

    protected $signature = 'courses:resume-checkouts {--limit=100}';

    protected $description = 'Resume explicitly authorized course checkouts after verified payment';

    public function handle(CourseCheckoutService $checkouts): int
    {
        $limit = min(500, max(1, (int) $this->option('limit')));
        $backoff = Schema::hasColumns('course_checkouts', ['resume_attempts', 'next_resume_at']);
        // Same contract as Laravel's database queue: a row is due when its
        // next attempt time has passed (jobs.available_at), and a retry that
        // makes no progress is released with exponential backoff. Rows never
        // attempted (NULL) sort first on MySQL and SQLite, so a set of stuck
        // rows can no longer occupy every batch and starve newer checkouts.
        $rows = CourseCheckout::query()
            ->where('status', 'pending_payment')
            ->when($backoff, fn ($query) => $query
                ->where(fn ($due) => $due->whereNull('next_resume_at')->orWhere('next_resume_at', '<=', now()))
                ->orderBy('next_resume_at'))
            ->orderBy('id')
            ->limit($limit)
            ->get();
        foreach ($rows as $row) {
            try {
                $user = User::query()->find($row->user_id);
                if (! $user) {
                    continue;
                }
                if (! $row->funding_order_id && $row->channel === 'google') {
                    // Exact, Google-verified intent binding; no package/SKU inference.
                    $receipt = StorePurchase::query()->where('user_id', $user->id)->where('provider', 'google')
                        ->where('provider_payload->checkout_profile_id', $row->public_id)->oldest('id')->first();
                    if ($receipt) {
                        $checkouts->bindStorePurchase($user, $row->public_id, $receipt);
                    }
                }
                $checkouts->resume($user, $row->public_id);
            } catch (\Throwable $exception) {
                report($exception);
            } finally {
                if ($backoff) {
                    $this->deferIfStillPending($row);
                }
            }
        }
        $this->info('Checked '.$rows->count().' authorized checkouts');

        return self::SUCCESS;
    }

    private function deferIfStillPending(CourseCheckout $row): void
    {
        $attempts = (int) $row->resume_attempts + 1;
        $minutes = min(self::MAX_BACKOFF_MINUTES, 2 ** min(10, $attempts - 1));
        // Base query: a scheduling hint must not bump updated_at or run the
        // model's immutable-terms guard. Rows that left pending_payment are
        // untouched.
        CourseCheckout::query()->toBase()
            ->where('id', $row->id)
            ->where('status', 'pending_payment')
            ->update([
                'resume_attempts' => min(65535, $attempts),
                'next_resume_at' => now()->addMinutes($minutes),
            ]);
    }
}
