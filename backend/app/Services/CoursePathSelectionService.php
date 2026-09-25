<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reconciles the course studio's path selection with live path administration. */
final class CoursePathSelectionService
{
    /** Caller owns canonical -> revision -> draft locks and the authoring transaction. */
    public function recordReviewedSelection(Course $draft, ?int $previousPathId): void
    {
        $this->requireTransaction();
        $revision = CourseAuthoringRevision::query()->where('revision_course_id', $draft->id)
            ->where('status', CourseAuthoringRevision::DRAFT)->first();
        if (!$revision) return;

        $identity = [
            'course_authoring_revision_id' => $revision->id,
            'entity_type' => CourseAuthoringRevision::PATH_SNAPSHOT,
        ];
        $snapshot = DB::table('course_authoring_revision_entities')->where($identity)->first();
        $draftPath = (int) ($draft->path_id ?? 0);
        if ($snapshot && (int) ($previousPathId ?? 0) === $draftPath
            && (int) $snapshot->source_entity_id === $draftPath) {
            // An unchanged field sent with a full editor form is not a new
            // selection. Keep its original base so a newer live move survives.
            return;
        }

        $livePath = (int) (Course::query()->whereKey($revision->canonical_course_id)->value('path_id') ?? 0);
        DB::table('course_authoring_revision_entities')->updateOrInsert($identity, [
            'source_entity_id' => $livePath,
            'revision_entity_id' => (int) $draft->id,
        ]);
    }

    /** Caller holds the publication locks. Does not mutate either course. */
    public function forPublication(CourseAuthoringRevision $revision, Course $canonical, Course $draft): ?int
    {
        $this->requireTransaction();
        $snapshot = DB::table('course_authoring_revision_entities')
            ->where('course_authoring_revision_id', $revision->id)
            ->where('entity_type', CourseAuthoringRevision::PATH_SNAPSHOT)->first();
        $livePath = (int) ($canonical->path_id ?? 0);
        $draftPath = (int) ($draft->path_id ?? 0);

        if (!$snapshot) {
            if ($livePath !== $draftPath) $this->conflict();
            return $draftPath ?: null;
        }

        $basePath = (int) $snapshot->source_entity_id;
        if ($draftPath === $basePath) return $livePath ?: null;
        if ($livePath !== $basePath && $livePath !== $draftPath) $this->conflict();

        return $draftPath ?: null;
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages([
            'authoring_version' => ["تغيّر مسار الكورس منذ بدء المسودة\nراجع اختيار المسار واحفظه قبل النشر"],
        ])->status(409);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Path reconciliation must share the course authoring transaction.');
        }
    }
}
