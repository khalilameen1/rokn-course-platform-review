<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationCampaign;
use App\Services\NotificationCampaignService;
use App\Support\DurableJobDispatch;
use Illuminate\Console\Command;

final class DispatchScheduledNotificationCampaigns extends Command
{
    protected $signature = 'notifications:dispatch-scheduled {--limit=100}';
    protected $description = 'Atomically release due notification campaigns to the notification queue';

    public function handle(NotificationCampaignService $campaigns): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $queued = 0;
        $due = NotificationCampaign::query()
            ->where('status', NotificationCampaign::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($due as $campaign) {
            $claimed = NotificationCampaign::query()
                ->whereKey($campaign->id)
                ->where('status', NotificationCampaign::STATUS_SCHEDULED)
                ->where('scheduled_at', '<=', now())
                ->update([
                    'status' => NotificationCampaign::STATUS_QUEUED,
                    'queued_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($claimed !== 1) continue;

            try {
                DurableJobDispatch::now($campaigns->jobForCampaign($campaign));
                $queued++;
            } catch (\Throwable $exception) {
                NotificationCampaign::query()->whereKey($campaign->id)->update([
                    'status' => NotificationCampaign::STATUS_SCHEDULED,
                    'queued_at' => null,
                    'failure_code' => 'schedule_queue_' . substr(hash('sha256', $exception::class), 0, 12),
                    'updated_at' => now(),
                ]);
                report($exception);
            }
        }

        $this->info("Queued {$queued} scheduled notification campaign(s).");
        return self::SUCCESS;
    }
}
