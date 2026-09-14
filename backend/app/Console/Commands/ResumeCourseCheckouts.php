<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CourseCheckout;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\CourseCheckoutService;
use Illuminate\Console\Command;

final class ResumeCourseCheckouts extends Command
{
    protected $signature = 'courses:resume-checkouts {--limit=100}';
    protected $description = 'Resume explicitly authorized course checkouts after verified payment';

    public function handle(CourseCheckoutService $checkouts): int
    {
        $limit = min(500, max(1, (int) $this->option('limit')));
        $rows = CourseCheckout::query()->where('status', 'pending_payment')->oldest('id')->limit($limit)->get();
        foreach ($rows as $row) {
            try {
                $user = User::query()->find($row->user_id);
                if (!$user) continue;
                if (!$row->funding_order_id && $row->channel === 'google') {
                    // Exact, Google-verified intent binding; no package/SKU inference.
                    $receipt = StorePurchase::query()->where('user_id', $user->id)->where('provider', 'google')
                        ->where('provider_payload->checkout_profile_id', $row->public_id)->oldest('id')->first();
                    if ($receipt) $checkouts->bindStorePurchase($user, $row->public_id, $receipt);
                }
                $checkouts->resume($user, $row->public_id);
            } catch (\Throwable $exception) { report($exception); }
        }
        $this->info('Checked '.$rows->count().' authorized checkouts');
        return self::SUCCESS;
    }
}
