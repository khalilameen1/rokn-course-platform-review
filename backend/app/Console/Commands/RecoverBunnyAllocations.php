<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BunnyDirectUpload;
use App\Models\BunnyVideoAllocationIntent;
use App\Models\PortfolioVideoUpload;
use App\Services\BunnyService;
use App\Services\BunnyMediaRegistry;
use Illuminate\Console\Command;

final class RecoverBunnyAllocations extends Command
{
    protected $signature = 'bunny:recover-allocations {--limit=100}';
    protected $description = 'Reconcile provider allocations interrupted before their GUID was persisted';

    public function handle(BunnyService $bunny, BunnyMediaRegistry $mediaRegistry): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        BunnyVideoAllocationIntent::query()
            ->where('status', 'allocating')
            ->whereNull('video_guid')
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->orderBy('id')->limit($limit)->get()
            ->each(function (BunnyVideoAllocationIntent $intent) use ($bunny, $mediaRegistry): void {
                $this->recover(
                    $bunny,
                    $mediaRegistry,
                    '[rokn-upload:' . strtolower((string) $intent->marker) . ']',
                    function (string $guid) use ($intent): void {
                        $intent->forceFill([
                            'video_guid' => $guid,
                            'status' => 'recovered',
                        ])->save();
                    }
                );
            });
        BunnyVideoAllocationIntent::query()
            ->where('status', 'allocating')
            ->whereNotNull('video_guid')
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->orderBy('id')->limit($limit)->get()
            ->each(function (BunnyVideoAllocationIntent $intent) use ($bunny, $mediaRegistry): void {
                $candidate = $mediaRegistry->queueVideoCleanup(
                    (string) $intent->video_guid,
                    null,
                    'interrupted_verified_upload_allocation',
                    1,
                    false
                );
                if (!$candidate) throw new \RuntimeException('Unable to persist recovered allocation.');
                $intent->forceFill(['status' => 'recovered'])->save();
            });

        BunnyDirectUpload::query()
            ->where('status', 'allocating')
            ->whereNull('video_guid')
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->orderBy('id')->limit($limit)->get()
            ->each(function (BunnyDirectUpload $upload) use ($bunny, $mediaRegistry): void {
                $this->recover(
                    $bunny,
                    $mediaRegistry,
                    '[rokn:' . strtolower((string) $upload->idempotency_key) . ']',
                    function (string $guid) use ($upload): void {
                        $upload->forceFill([
                            'video_guid' => $guid,
                            'status' => 'failed',
                            'allocation_token' => null,
                        ])->save();
                    }
                );
            });
        BunnyDirectUpload::query()
            ->where('status', 'allocating')
            ->whereNotNull('video_guid')
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->orderBy('id')->limit($limit)->get()
            ->each(function (BunnyDirectUpload $upload) use ($bunny, $mediaRegistry): void {
                $candidate = $mediaRegistry->queueVideoCleanup(
                    (string) $upload->video_guid,
                    null,
                    'interrupted_direct_upload_allocation',
                    1,
                    false
                );
                if (!$candidate) throw new \RuntimeException('Unable to persist recovered allocation.');
                $upload->forceFill(['status' => 'failed', 'allocation_token' => null])->save();
            });

        PortfolioVideoUpload::query()
            ->where('status', 'allocating')
            ->whereNull('video_guid')
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->orderBy('id')->limit($limit)->get()
            ->each(function (PortfolioVideoUpload $upload) use ($bunny, $mediaRegistry): void {
                $this->recover(
                    $bunny,
                    $mediaRegistry,
                    '[rokn-portfolio:' . strtolower((string) $upload->idempotency_key) . ']',
                    function (string $guid) use ($upload): void {
                        $upload->forceFill([
                            'video_guid' => $guid,
                            'status' => 'failed',
                            'allocation_token' => null,
                        ])->save();
                    }
                );
            });
        PortfolioVideoUpload::query()
            ->where('status', 'allocating')
            ->whereNotNull('video_guid')
            ->where('updated_at', '<=', now()->subMinutes(5))
            ->orderBy('id')->limit($limit)->get()
            ->each(function (PortfolioVideoUpload $upload) use ($bunny, $mediaRegistry): void {
                $candidate = $mediaRegistry->queueVideoCleanup(
                    (string) $upload->video_guid,
                    null,
                    'interrupted_portfolio_upload_allocation',
                    1,
                    false
                );
                if (!$candidate) throw new \RuntimeException('Unable to persist recovered allocation.');
                $upload->forceFill(['status' => 'failed', 'allocation_token' => null])->save();
            });

        BunnyVideoAllocationIntent::query()
            ->whereIn('status', ['uploaded', 'recovered', 'failed'])
            ->where('updated_at', '<=', now()->subDays(7))
            ->delete();
        return self::SUCCESS;
    }

    private function recover(BunnyService $bunny, BunnyMediaRegistry $mediaRegistry, string $marker, callable $commit): void
    {
        foreach ($bunny->findVideoGuidsByTitleMarker($marker) as $guid) {
            $candidate = $mediaRegistry->queueVideoCleanup(
                $guid,
                null,
                'interrupted_allocation',
                1,
                false
            );
            if (!$candidate) throw new \RuntimeException('Unable to persist recovered allocation.');
            $commit($guid);
        }
    }
}
