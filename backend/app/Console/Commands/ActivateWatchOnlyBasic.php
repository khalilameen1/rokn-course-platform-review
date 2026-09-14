<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseAuthoringRevision;
use App\Services\CourseAccessPlanService;
use App\Services\CourseAuthoringConcurrencyService;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use App\Support\CourseAccessPlanSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit one-course rollout; never edits an existing working draft. */
final class ActivateWatchOnlyBasic extends Command
{
    protected $signature = 'courses:activate-watch-only-basic
        {course : Canonical published course ID}
        {--expected-version= : Required current canonical authoring version}
        {--apply : Publish the previewed Basic-only change}';

    protected $description = 'Preview or publish watch-only Basic without repricing or rewriting purchased receipts';

    private const WATCH_ONLY = [
        'projects_enabled' => false,
        'certificate_enabled' => false,
        'chat_enabled' => false,
        'chat_message_limit' => 0,
        'chat_token_budget' => 0,
        'chat_attachments_enabled' => false,
        'chat_attachment_max_files' => 0,
        'ai_budget_usd' => 0,
        'request_reserve_usd' => 0,
        'project_feedback_level' => CourseAccessPlan::FEEDBACK_PASS_ONLY,
        'project_feedback_token_budget' => 0,
        'project_feedback_budget_usd' => 0,
        'project_feedback_reserve_usd' => 0,
        'project_followup_message_limit' => 0,
        'project_followup_token_budget' => 0,
        'project_followup_budget_usd' => 0,
        'project_followup_reserve_usd' => 0,
        'project_followup_attachments_enabled' => false,
        'project_followup_attachment_max_files' => 0,
        'project_output_enabled' => false,
    ];

    public function handle(
        CourseAccessPlanService $plans,
        CourseAuthoringConcurrencyService $authoring,
        CoursePublishingService $publishing,
        CourseStagedAuthoringService $staged
    ): int {
        $courseId = filter_var($this->argument('course'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $expected = filter_var($this->option('expected-version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($courseId === false || $expected === false) {
            $this->error('A positive course ID and --expected-version are required.');
            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use ($courseId, $expected, $plans, $authoring, $publishing, $staged): array {
                // Serializes draft creation/publication and every course debit.
                $course = Course::query()->whereKey($courseId)->lockForUpdate()->firstOrFail();
                if (CourseAuthoringRevision::query()->where('revision_course_id', $courseId)->exists()
                    || !$course->isPublishedForLearning()
                    || (int) $course->last_published_authoring_version < 1) {
                    throw new \DomainException('Only a canonical published course can be activated.');
                }
                if ((int) $course->authoring_version !== $expected) {
                    throw new \DomainException('The canonical authoring version changed; review it again.');
                }
                // draftFor normally reuses a working draft. This rollout must
                // never adopt it, even when it appears identical to live data.
                if (CourseAuthoringRevision::query()->where('canonical_course_id', $courseId)
                    ->where(fn ($query) => $query->where('status', CourseAuthoringRevision::DRAFT)->orWhereNotNull('active_slot'))
                    ->lockForUpdate()->exists()) {
                    throw new \DomainException('An existing working draft must be reviewed separately; nothing was changed.');
                }
                $offers = $course->accessPlans()->orderBy('id')->lockForUpdate()->get();
                if ($offers->count() !== count(CourseAccessPlan::CODES)
                    || array_diff(CourseAccessPlan::CODES, $offers->pluck('code')->all()) !== []) {
                    throw new \DomainException('Exactly the three existing plan identities are required.');
                }
                $basic = $offers->firstWhere('code', CourseAccessPlan::BASIC);
                $candidate = clone $basic;
                $candidate->forceFill(self::WATCH_ONLY);
                if (!$candidate->isDirty()) return ['status' => 'unchanged', 'version' => $expected];

                CourseAccessPlanSnapshot::assertValidForPlan((int) $candidate->id, $plans->snapshot($candidate));
                // Preview readiness using an unsaved copy; a dry run never
                // creates a draft, updates catalogue caches or queues notices.
                $preview = clone $course;
                $preview->setRelation('accessPlans', $offers->map(fn ($plan) => $plan->id === $basic->id ? $candidate : $plan));
                $audit = $publishing->audit($preview);
                if (!$audit['ready']) throw ValidationException::withMessages(['course' => $audit['issues']]);
                $summary = [
                    'status' => 'preview', 'version' => $expected,
                    'price_coins' => (int) $basic->price_coins,
                    'minimum_paid_coins' => (int) $basic->minimum_paid_coins,
                    'warnings' => $audit['warnings'] ?? [],
                ];
                if (!$this->option('apply')) return $summary;

                $expectedOffers = $offers->mapWithKeys(fn ($plan): array => [
                    $plan->code => $this->offerFacts($plan->id === $basic->id ? $candidate : $plan),
                ])->all();
                $courseFacts = $this->courseFacts($course);
                $draft = $staged->draftFor($course);
                $lockedDraft = $authoring->lockExpected($draft, (int) $draft->authoring_version);
                // Cloning intentionally clears the hero flag; preserve the
                // canonical choice without introducing any curation changes.
                $lockedDraft->forceFill(['is_main_course' => $course->is_main_course])->saveQuietly();
                $lockedDraft->accessPlans()->where('code', CourseAccessPlan::BASIC)
                    ->firstOrFail()->forceFill(self::WATCH_ONLY)->save();
                $draftVersion = $authoring->advance($lockedDraft);
                $published = $staged->publish($lockedDraft, $draftVersion, (bool) $course->is_catalog_visible, false, false);
                $after = $published['course'];
                $actualOffers = $after->accessPlans()->orderBy('id')->get()
                    ->mapWithKeys(fn ($plan): array => [$plan->code => $this->offerFacts($plan)])->all();
                if ($expectedOffers !== $actualOffers || $courseFacts !== $this->courseFacts($after)) {
                    throw new \LogicException('Publication changed protected course or plan facts; the activation was rolled back.');
                }
                return [...$summary, 'status' => 'published', 'version' => $published['published_revision']];
            }, 3);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) foreach ($messages as $message) $this->error($message);
            return self::FAILURE;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        if ($result['status'] === 'unchanged') {
            $this->info("Course {$courseId}: already watch-only; no changes or notification.");
            return self::SUCCESS;
        }
        $this->info("Course {$courseId}: {$result['status']}; authoring version {$result['version']}.");
        $this->line("Basic price {$result['price_coins']} coins and paid floor {$result['minimum_paid_coins']} preserved; Plus/Pro and purchased receipts unchanged.");
        foreach ($result['warnings'] as $warning) $this->warn($warning);
        $this->line($result['status'] === 'published'
            ? 'Normal staged publication and course-update notification processing completed.'
            : 'Dry run only. Use --apply with the same expected version to publish; normal course-update notifications apply.');
        return self::SUCCESS;
    }

    private function offerFacts(CourseAccessPlan $plan): array
    {
        return collect($plan->attributesToArray())->except(['updated_at'])->all();
    }

    private function courseFacts(Course $course): array
    {
        return collect($course->getAttributes())->except([
            'updated_at', 'authoring_version', 'last_published_authoring_version', 'published_at', 'authoring_request_id',
        ])->all();
    }
}
