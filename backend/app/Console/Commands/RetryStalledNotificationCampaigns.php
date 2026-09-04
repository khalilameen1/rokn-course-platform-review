<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationCampaign;
use App\Services\NotificationCampaignService;
use App\Support\DurableJobDispatch;
use Illuminate\Console\Command;

final class RetryStalledNotificationCampaigns extends Command
{
    protected $signature = 'notifications:retry-campaigns {--limit=50}';
    protected $description = 'Requeue notification campaigns that never completed their durable inbox delivery';

    public function handle(NotificationCampaignService $campaigns): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $exhaustedBefore = now()->subMinutes(30);

        // Do not leave an exhausted coordinator looking "in progress" for
        // ever.  Turning it into a durable dead letter makes the affected
        // audience visible to operations and prevents a retry storm.
        NotificationCampaign::query()
            ->where('retry_count', '>=', 3)
            ->whereIn('status', [
                NotificationCampaign::STATUS_QUEUED,
                NotificationCampaign::STATUS_DELIVERING,
            ])
            ->where('updated_at', '<=', $exhaustedBefore)
            ->update([
                'status' => NotificationCampaign::STATUS_FAILED,
                'failed_at' => now(),
                'failure_code' => 'recovery_exhausted',
                'updated_at' => now(),
            ]);

        $queued = 0;
        $candidates = NotificationCampaign::query()
            ->where('retry_count', '<', 3)
            ->where(function ($query): void {
                $query->where(function ($queued): void {
                    $queued->where('status', NotificationCampaign::STATUS_QUEUED)
                        // The coordinator unique lease is 15 minutes. Waiting
                        // until it expires avoids spending a recovery attempt
                        // merely because the notification queue is busy.
                        ->where('queued_at', '<=', now()->subMinutes(15));
                })->orWhere(function ($delivering): void {
                    $delivering->where('status', NotificationCampaign::STATUS_DELIVERING)
                        ->where('updated_at', '<=', now()->subMinutes(30));
                })->orWhere(function ($failed): void {
                    $failed->where('status', NotificationCampaign::STATUS_FAILED)
                        ->where('failed_at', '<=', now()->subMinutes(15));
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($candidates as $campaign) {
            $claimed = NotificationCampaign::query()
                ->whereKey($campaign->id)
                ->where('status', $campaign->status)
                ->where('retry_count', $campaign->retry_count)
                ->update([
                    'status' => NotificationCampaign::STATUS_QUEUED,
                    'retry_count' => $campaign->retry_count + 1,
                    'queued_at' => now(),
                    'failed_at' => null,
                    'failure_code' => null,
                    'coordinator_finished_at' => null,
                    'completed_at' => null,
                ]);
            if ($claimed !== 1) {
                continue;
            }

            try {
                DurableJobDispatch::now($campaigns->jobForCampaign($campaign));
                $queued++;
            } catch (\Throwable $exception) {
                // A broken queue write for one campaign must not prevent the
                // rest of the recovery batch from being considered.
                NotificationCampaign::query()->whereKey($campaign->id)->update([
                    'status' => NotificationCampaign::STATUS_FAILED,
                    'failed_at' => now(),
                    'failure_code' => 'recovery_queue_' . substr(hash('sha256', $exception::class), 0, 12),
                    'updated_at' => now(),
                ]);
                report($exception);
            }
        }

        $this->info("Queued {$queued} stalled notification campaign(s).");
        return self::SUCCESS;
    }
}
