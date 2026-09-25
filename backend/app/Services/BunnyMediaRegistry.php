<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\BunnyStorageCleanupCandidate;
use App\Support\BunnyStoragePath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Owns durable cleanup reservations and publication guards, without remote IO. */
class BunnyMediaRegistry
{
    public function __construct(private readonly BunnyConfiguration $configuration) {}

    public function queueVideoCleanup(
        string $videoGuid,
        ?Lesson $lesson,
        string $reason,
        int $delayHours,
        bool $requiresReview = true
    ): ?BunnyVideoCleanupCandidate {
        $videoGuid = trim($videoGuid);
        if ($videoGuid === '') {
            return null;
        }

        try {
            return BunnyVideoCleanupCandidate::query()->updateOrCreate(
                ['video_guid' => $videoGuid],
                [
                    'lesson_id' => $lesson && $lesson->exists ? $lesson->getKey() : null,
                    'reason' => $reason,
                    'requires_review' => $requiresReview,
                    'eligible_after' => now()->addHours(max(1, $delayHours)),
                    'reviewed_at' => $requiresReview ? null : now(),
                    'reviewed_by' => null,
                    'remote_deleted_at' => null,
                    'last_error' => null,
                ]
            );
        } catch (Throwable $exception) {
            Log::error('Unable to record Bunny cleanup candidate', [
                'video_guid' => $videoGuid,
                'lesson_id' => $lesson?->getKey(),
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    public function queueStorageCleanup(string $path, string $reason, int $delayMinutes = 0): bool
    {
        $normalized = BunnyStoragePath::normalize($path, $this->configuration->getStorageCdnHostname());
        if ($normalized === null) return false;

        $attributes = [
            'path' => $normalized,
            'reason' => mb_substr($reason, 0, 100),
            'eligible_after' => now()->addMinutes(max(0, $delayMinutes)),
            'completed_at' => null,
            'attempts' => 0,
            'last_attempt_at' => null,
            'last_error' => null,
        ];
        if (Schema::hasColumn('bunny_storage_cleanup_candidates', 'quarantined_at')) {
            $attributes['quarantined_at'] = null;
        }

        return DB::transaction(function () use ($normalized, $attributes): bool {
            $pathHash = hash('sha256', $normalized);
            BunnyStorageCleanupCandidate::query()->firstOrCreate(
                ['path_hash' => $pathHash],
                $attributes
            );
            $candidate = BunnyStorageCleanupCandidate::query()
                ->where('path_hash', $pathHash)
                ->lockForUpdate()
                ->firstOrFail();

            // Once a delete request has left our process its result can be
            // unknown. Reusing the same deterministic object key would let a
            // late DELETE erase newly uploaded bytes.
            if ($candidate->completed_at === null && $candidate->last_attempt_at !== null) {
                return false;
            }

            $candidate->forceFill($attributes)->save();
            return true;
        }, 3);
    }

    /** Consume a staged Storage cleanup row atomically with its live reference. */
    public function consumeStorageCleanupCandidate(string $path): void
    {
        $normalized = BunnyStoragePath::normalize($path, $this->configuration->getStorageCdnHostname());
        if ($normalized === null) {
            throw new RuntimeException('The staged Bunny Storage path is invalid.');
        }
        $candidate = BunnyStorageCleanupCandidate::query()
            ->where('path_hash', hash('sha256', $normalized))
            ->whereNull('completed_at')
            ->whereNull('last_attempt_at')
            ->lockForUpdate()
            ->first();
        if (!$candidate) {
            throw new RuntimeException('The staged Bunny Storage object is no longer safe to publish.');
        }

        $candidate->delete();
    }
}
