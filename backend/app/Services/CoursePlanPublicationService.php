<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishes editable offers while preserving sold plan IDs and all receipts.
 * Runs inside the graph publisher's transaction, under its course locks.
 * This is not checkout and never edits an enrollment or usage ledger.
 */
final class CoursePlanPublicationService
{
    public function publishWithinTransaction(Course $canonical, Course $archive): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Course revision writes must share the authoring transaction.');
        }

        $rows = CourseAccessPlan::query()
            ->whereIn('course_id', [$canonical->id, $archive->id])
            ->orderBy('course_id')->orderBy('id')->lockForUpdate()->get();
        $live = $rows->where('course_id', $canonical->id)->keyBy('code');
        $draft = $rows->where('course_id', $archive->id)->keyBy('code');
        foreach ([$live, $draft] as $plans) {
            if ($plans->count() > count(CourseAccessPlan::CODES)
                || array_diff($plans->keys()->all(), CourseAccessPlan::CODES) !== []) {
                throw new \LogicException('Unsupported course access-plan identities.');
            }
        }
        if ($live->keys()->diff($draft->keys())->isNotEmpty()) {
            // Readiness normally rejects an incomplete draft before this point.
            // Never remove a previously sold identity to publish a missing tier.
            throw ValidationException::withMessages(['course' => ['أكمل فئات الكورس الثلاث قبل النشر.']]);
        }

        $offerAttributes = static fn (CourseAccessPlan $plan): array => collect($plan->getAttributes())
            ->except(['id', 'course_id', 'code', 'created_at', 'updated_at'])->all();
        $liveOffers = $live->map($offerAttributes);
        $draftOffers = $draft->map($offerAttributes);
        $archiveOffers = $liveOffers->all();
        $archiveSorts = array_fill_keys($live->pluck('sort_order')->all(), true);
        foreach ($draftOffers as $code => $offer) {
            if (isset($archiveOffers[$code])) continue;
            // A legacy course may acquire its first plans in this revision.
            // Keep the isolated draft identity, but not an active offer that
            // would falsely describe a tier available before this publication.
            $offer['is_active'] = false;
            if (isset($archiveSorts[$offer['sort_order']])) {
                $offer['sort_order'] = $this->reserveAccessPlanSort($archiveSorts);
            } else {
                $archiveSorts[$offer['sort_order']] = true;
            }
            $archiveOffers[$code] = $offer;
        }

        // UNIQUE(course_id, sort_order) also applies to inactive rows. Reserve
        // temporary unused SMALLINT UNSIGNED slots before applying a permutation
        // of tier positions; neither foreign-key identity ever changes.
        $occupied = array_fill_keys($rows->pluck('sort_order')->all(), true);
        foreach ([...$draftOffers->values()->all(), ...array_values($archiveOffers)] as $offer) {
            $sort = (int) $offer['sort_order'];
            if ($sort < 0 || $sort > 65535) {
                throw new \LogicException('Course access-plan sort order is out of range.');
            }
            $occupied[$sort] = true;
        }
        foreach ($rows as $plan) {
            $plan->forceFill(['sort_order' => $this->reserveAccessPlanSort($occupied)])->save();
        }

        foreach ($draft as $code => $draftPlan) {
            $livePlan = $live->get($code) ?: new CourseAccessPlan([
                'course_id' => $canonical->id,
                'code' => $code,
            ]);
            $livePlan->forceFill($draftOffers->get($code))->save();
            $draftPlan->forceFill($archiveOffers[$code])->save();
        }
    }

    /** @param array<int,bool> $occupied */
    private function reserveAccessPlanSort(array &$occupied): int
    {
        for ($sort = 65535; $sort >= 0; $sort--) {
            if (!isset($occupied[$sort])) {
                $occupied[$sort] = true;
                return $sort;
            }
        }
        throw new \LogicException('No course access-plan sort slot is available.');
    }
}
