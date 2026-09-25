<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\CleanupDeletedAccountPortfolioMedia;
use App\Support\DurableJobDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

/** Owns private portfolio erasure and the durable references for remote cleanup. */
final class AccountPortfolioErasureService
{
    /**
     * The account owner holds the learner lock and erases identity in this
     * transaction. No remote bytes may be removed before it commits.
     */
    public function eraseWithinDeletion(int $userId): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Portfolio erasure must share the account-deletion transaction.');
        }

        $remotePortfolioCleanupPending = false;
        if (Schema::hasTable('portfolio_items')) {
            $portfolioItemIds = DB::table('portfolio_items')->where('user_id', $userId)->pluck('id');
            if ($portfolioItemIds->isNotEmpty() && Schema::hasTable('portfolio_media')) {
                $itemsWithMedia = DB::table('portfolio_media')
                    ->whereIn('portfolio_item_id', $portfolioItemIds)
                    ->distinct()
                    ->pluck('portfolio_item_id');
                $emptyItemIds = $portfolioItemIds->diff($itemsWithMedia);
                if ($emptyItemIds->isNotEmpty()) {
                    DB::table('portfolio_items')->whereIn('id', $emptyItemIds)->delete();
                }
                $portfolioItemIds = $itemsWithMedia;
                // Bunny deletions are external and cannot be atomic with the DB
                // transaction. Keep private references for a retriable cleanup.
                $remotePortfolioCleanupPending = DB::table('portfolio_media')
                    ->whereIn('portfolio_item_id', $portfolioItemIds)
                    ->exists();
                $mediaUpdate = [];
                if (Schema::hasColumn('portfolio_media', 'caption')) {
                    $mediaUpdate['caption'] = null;
                }
                if (Schema::hasColumn('portfolio_media', 'updated_at')) {
                    $mediaUpdate['updated_at'] = now();
                }
                if ($mediaUpdate !== []) {
                    DB::table('portfolio_media')
                        ->whereIn('portfolio_item_id', $portfolioItemIds)
                        ->update($mediaUpdate);
                }
            } elseif ($portfolioItemIds->isNotEmpty()) {
                DB::table('portfolio_items')->whereIn('id', $portfolioItemIds)->delete();
                $portfolioItemIds = collect();
            }

            $portfolioUpdate = array_intersect_key([
                'title' => null,
                'description' => null,
                'slug' => null,
                'role' => null,
                'tools' => null,
                'external_url' => null,
                'is_public' => false,
                'is_featured' => false,
                'updated_at' => now(),
            ], array_flip(Schema::getColumnListing('portfolio_items')));
            if ($portfolioUpdate !== []) {
                DB::table('portfolio_items')
                    ->where('user_id', $userId)
                    ->whereIn('id', $portfolioItemIds)
                    ->update($portfolioUpdate);
            }
        }

        if ($remotePortfolioCleanupPending) {
            DB::afterCommit(static function () use ($userId): void {
                try {
                    DurableJobDispatch::now(new CleanupDeletedAccountPortfolioMedia($userId));
                } catch (Throwable $exception) {
                    // These private rows are the durable recovery ledger used
                    // by privacy:cleanup-portfolio-media when the queue returns.
                    Log::warning('Unable to dispatch deleted portfolio cleanup.', [
                        'deleted_user_id' => $userId,
                        'exception' => $exception::class,
                    ]);
                }
            });
        }

        return $remotePortfolioCleanupPending;
    }
}
