<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BunnyVideoCleanupCandidate;
use App\Models\CourseSection;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;

/** Reviews eligibility only. Remote deletion belongs to the cleanup worker. */
final class AdminBunnyCleanupReviewService
{
    public const VERIFIED_REASONS = [
        'publish_race_or_failure',
        'superseded_video',
        'unpublished_upload',
        'section_create_rollback',
        'section_update_rollback',
        'section_type_changed',
        'section_deleted',
    ];

    /** Returns false when a course section still references the video. */
    public function approve(int $candidateId, int $reviewerId): bool
    {
        return DB::transaction(function () use ($candidateId, $reviewerId): bool {
            $candidate = BunnyVideoCleanupCandidate::query()->whereKey($candidateId)
                ->lockForUpdate()->firstOrFail();
            abort_if($candidate->remote_deleted_at, 409, 'تم حذف هذا الفيديو بالفعل');

            return $this->approveLockedCandidate($candidate, $reviewerId);
        });
    }

    /**
     * @param list<int|string> $ids Validated distinct candidate IDs.
     * @return array{approved: int, skipped_active: int}
     */
    public function approveBatch(array $ids, int $reviewerId): array
    {
        return DB::transaction(function () use ($ids, $reviewerId): array {
            $candidates = BunnyVideoCleanupCandidate::query()
                ->whereIn('id', $ids)
                ->whereNull('remote_deleted_at')
                ->whereNull('reviewed_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $result = ['approved' => 0, 'skipped_active' => 0];
            foreach ($candidates as $candidate) {
                $result[$this->approveLockedCandidate($candidate, $reviewerId) ? 'approved' : 'skipped_active']++;
            }

            return $result;
        });
    }

    /** Called with the candidate locked; approval is not permission to bypass worker reference checks. */
    private function approveLockedCandidate(BunnyVideoCleanupCandidate $candidate, int $reviewerId): bool
    {
        $activeReference = CourseSection::query()
            ->join('lessons', function ($join): void {
                $join->on('lessons.id', '=', 'course_sections.sectionable_id')
                    ->where('course_sections.sectionable_type', '=', Lesson::class);
            })
            ->where('lessons.bunny_video_id', $candidate->video_guid)
            ->exists();
        if ($activeReference) return false;

        $candidate->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $reviewerId,
            'requires_review' => false,
            'eligible_after' => $candidate->eligible_after->isFuture() ? $candidate->eligible_after : now(),
            'last_error' => null,
        ])->save();

        return true;
    }
}
