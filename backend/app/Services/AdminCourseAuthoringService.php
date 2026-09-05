<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\Admin\CourseRequest;
use App\Models\Course;
use App\Models\Photo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AdminCourseAuthoringService
{
    public function __construct(
        private CourseAccessPlanService $accessPlans,
        private AdminAuthoringCreateIntentService $createIntents,
        private CourseAuthoringConcurrencyService $authoring,
        private CourseHeroSelectionService $heroSelection,
        private CoursePublishingService $publishing,
        private CourseStagedAuthoringService $stagedAuthoring,
        private StoredFileDeletionService $files
    ) {
    }

    /** @return array{status:string, course:?Course} */
    public function create(CourseRequest $request): array
    {
        $requestId = (string) $request->validated('authoring_request_id');
        $existing = Course::query()->where('authoring_request_id', $requestId)->first();
        if ($existing) {
            $this->completeExistingIntent($request, $existing);
            return ['status' => 'existing', 'course' => $existing];
        }

        $data = array_merge($this->courseData($request), [
            'authoring_request_id' => $requestId,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            // A draft cannot be the public hero. The explicit choice is made
            // in the studio and becomes public only with a successful publish.
            'is_main_course' => false,
            // A new course has no downloadable material yet. Enabling the
            // discovery prompt here makes an otherwise complete course fail
            // publication until the moderator finds and disables an unrelated
            // setting. It can be enabled explicitly after attachments exist.
            'attachment_prompt_enabled' => false,
        ]);
        $imagePath = null;

        try {
            $imagePath = $this->storeImage($request);
            $course = DB::transaction(function () use ($data, $request, $imagePath): Course {
                $course = Course::create($data);
                $this->accessPlans->createDefaults($course);
                if ($request->has('access_plans')) {
                    $this->accessPlans->syncAdminPlans(
                        $course,
                        (array) $request->validated('access_plans', [])
                    );
                }
                $course->classifications()->sync($request->input('classification_ids', []));
                $course->teachers()->sync($request->input('teacher_ids', []));
                if ($imagePath) {
                    $course->allPhotos()->create(['path' => $imagePath, 'type' => 'featured']);
                }
                $this->createIntents->completeRedirect(
                    $request,
                    route('admin.courses.show', $course),
                    302,
                    Course::class,
                    $course->id
                );

                return $course;
            }, 3);

            return ['status' => 'created', 'course' => $course];
        } catch (\Throwable $exception) {
            if ($imagePath) {
                $this->files->deleteOrQueue('public', $imagePath);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            $existing = Course::query()->where('authoring_request_id', $requestId)->first();
            if ($existing) {
                return ['status' => 'existing', 'course' => $existing];
            }
            report($exception);

            return ['status' => 'failed', 'course' => null];
        }
    }

    /** @return array{status:string, course:Course, issues?:array} */
    public function update(
        CourseRequest $request,
        Course $course,
        bool $administrator,
        bool $canCurateHome
    ): array
    {
        $validated = $request->validated();
        $wasDraft = (bool) $course->is_coming_soon;
        $publishingRequested = $request->input('publishing_intent') === 'publish';
        $catalogVisibilitySubmitted = array_key_exists('is_catalog_visible', $validated);
        $catalogVisible = $catalogVisibilitySubmitted
            ? $request->boolean('is_catalog_visible')
            : (bool) $course->is_catalog_visible;
        $data = $this->courseData($request);
        if (!$wasDraft && $publishingRequested) {
            $data['is_catalog_visible'] = $catalogVisible;
        }
        if ($wasDraft && $catalogVisibilitySubmitted && !$catalogVisible) {
            $data['is_catalog_visible'] = false;
        }
        if ($wasDraft && $publishingRequested) {
            $data['is_coming_soon'] = true;
        }

        $imagePath = null;
        $oldPhotos = collect();
        $liveIssues = [];
        $ownedVersion = null;
        $managedDraft = $this->stagedAuthoring->isManagedDraft($course);
        $canonical = $this->stagedAuthoring->canonicalFor($course);
        $explicitHero = $managedDraft
            ? $this->stagedAuthoring->explicitHeroSelection($course)
            : null;
        $preservedHero = $managedDraft
            // A fresh clone clears its implementation flag. Once the editor
            // explicitly checks or unchecks the control, the draft owns that
            // intent across every later partial save.
            ? ($explicitHero ?? (bool) $canonical->is_main_course)
            : (!(bool) $course->is_coming_soon && (bool) $course->is_main_course);
        $heroRequested = $canCurateHome && $request->has('is_main_course')
            ? $request->boolean('is_main_course')
            : $preservedHero;

        try {
            $imagePath = $this->storeImage($request);
            if ($imagePath) {
                $oldPhotos = $course->allPhotos()->where('type', 'featured')->get(['photos.id', 'photos.path']);
            }
            DB::transaction(function () use (
                $course,
                $data,
                $request,
                $validated,
                $administrator,
                $imagePath,
                $oldPhotos,
                $managedDraft,
                $canCurateHome,
                $wasDraft,
                $publishingRequested,
                $heroRequested,
                &$liveIssues,
                &$ownedVersion
            ): void {
                $locked = $this->authoring->lock($request, $course);
                $locked->update($data);
                if ($wasDraft) {
                    $locked->updateQuietly(['is_main_course' => $heroRequested]);
                    if ($managedDraft && $canCurateHome && $request->has('is_main_course')) {
                        $this->stagedAuthoring->confirmHeroSelection($locked);
                    }
                }
                if ($request->has('access_plans')) {
                    $this->accessPlans->syncAdminPlans(
                        $locked,
                        (array) $request->validated('access_plans', [])
                    );
                }
                if (!$managedDraft && $administrator && (
                    $request->boolean('grant_chat_attachments_to_current_enrollments')
                    || $request->boolean('grant_project_followup_attachments_to_current_enrollments')
                )) {
                    $this->accessPlans->grantAttachmentsToCurrentEnrollments(
                        $locked,
                        $request->boolean('grant_chat_attachments_to_current_enrollments'),
                        $request->boolean('grant_project_followup_attachments_to_current_enrollments')
                    );
                }
                if (array_key_exists('classification_ids', $validated)
                    || $request->boolean('classification_ids_present')) {
                    $locked->classifications()->sync((array) $request->input('classification_ids', []));
                    $this->stagedAuthoring->confirmClassificationSelection($locked);
                }
                if (array_key_exists('teacher_ids', $validated)
                    || $request->boolean('teacher_ids_present')) {
                    $locked->teachers()->sync((array) $request->input('teacher_ids', []));
                }
                if ($imagePath) {
                    $locked->allPhotos()->create(['path' => $imagePath, 'type' => 'featured']);
                    Photo::query()->whereIn('id', $oldPhotos->pluck('id'))
                        ->lockForUpdate()->get()->each->delete();
                }
                if (!$wasDraft && $publishingRequested) {
                    $audit = $this->publishing->audit($locked->fresh());
                    if (!$audit['ready']) {
                        $liveIssues = $audit['issues'];
                        throw new \DomainException('published_course_incomplete');
                    }
                }
                $ownedVersion = $this->authoring->advance($locked);
            }, 3);
        } catch (\Throwable $exception) {
            if ($imagePath) {
                $this->files->deleteOrQueue('public', $imagePath);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            if ($exception instanceof \DomainException
                && $exception->getMessage() === 'published_course_incomplete') {
                return ['status' => 'live_incomplete', 'course' => $course, 'issues' => $liveIssues];
            }
            report($exception);

            return ['status' => 'save_failed', 'course' => $course];
        }

        $course->refresh();
        if ($wasDraft && $publishingRequested && $managedDraft) {
            try {
                $published = $this->stagedAuthoring->publish(
                    $course,
                    (int) $ownedVersion,
                    $catalogVisible,
                    $administrator && $request->boolean('grant_chat_attachments_to_current_enrollments'),
                    $administrator && $request->boolean('grant_project_followup_attachments_to_current_enrollments')
                );
                $course = $published['course'];
                $ownedVersion = (int) $published['published_revision'];
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                report($exception);
                return ['status' => 'staged_publish_failed', 'course' => $course];
            }
        } elseif ($wasDraft && $publishingRequested) {
            $publish = $this->publishDirectly($course, (int) $ownedVersion, $catalogVisible);
            if ($publish['status'] !== 'published') {
                return $publish;
            }
            $ownedVersion = $publish['version'];
            $course->refresh();
        }

        $fresh = $course->fresh();
        if (
            !$managedDraft
            && $fresh->is_coming_soon
            && $catalogVisibilitySubmitted
            && $catalogVisible
        ) {
            $catalog = $this->publishCatalogCard($fresh, (int) $ownedVersion);
            if ($catalog['status'] !== 'catalog_published') {
                return $catalog;
            }
            $ownedVersion = $catalog['version'];
        }

        if (!($wasDraft && !$publishingRequested)) {
            try {
                $this->heroSelection->synchronize($course, (int) $ownedVersion, $heroRequested);
            } catch (\Throwable $exception) {
                report($exception);
                return ['status' => 'hero_failed', 'course' => $course];
            }
        }

        return ['status' => 'updated', 'course' => $course];
    }

    /** @return array{status:string, course:Course, issues?:array, version?:int} */
    private function publishDirectly(Course $course, int $expectedVersion, bool $catalogVisible): array
    {
        $audit = null;
        $publishedVersion = null;
        try {
            DB::transaction(function () use (
                $course,
                $expectedVersion,
                $catalogVisible,
                &$audit,
                &$publishedVersion
            ): void {
                $locked = $this->authoring->lockExpected($course, $expectedVersion);
                $audit = $this->publishing->audit($locked->fresh());
                if (!$audit['ready']) {
                    return;
                }
                $previousVersion = (int) ($locked->last_published_authoring_version ?? 0);
                $locked->update(['is_coming_soon' => false, 'is_catalog_visible' => $catalogVisible]);
                $publishedVersion = $this->authoring->advance($locked);
                $locked->forceFill([
                    'last_published_authoring_version' => $publishedVersion,
                    'published_at' => now(),
                ])->save();
                if ($previousVersion > 0 && $publishedVersion > $previousVersion) {
                    NotificationService::notifyCourseUpdate(
                        $locked->fresh(),
                        'published_changes',
                        'course-published:'.$locked->id.':v'.$publishedVersion
                    );
                } elseif ($previousVersion === 0 && $catalogVisible) {
                    NotificationService::notifyNewCourse(
                        $locked->fresh(),
                        'course-published:'.$locked->id.':v'.$publishedVersion.':new'
                    );
                }
            }, 3);
        } catch (\Throwable $exception) {
            report($exception);
            return ['status' => 'publish_failed', 'course' => $course];
        }
        if (!($audit['ready'] ?? false)) {
            return [
                'status' => 'not_ready',
                'course' => $course,
                'issues' => (array) ($audit['issues'] ?? []),
            ];
        }

        return ['status' => 'published', 'course' => $course, 'version' => (int) $publishedVersion];
    }

    /** @return array{status:string, course:Course, issues?:array, version?:int} */
    private function publishCatalogCard(Course $course, int $expectedVersion): array
    {
        $audit = null;
        $version = $expectedVersion;
        try {
            DB::transaction(function () use ($course, $expectedVersion, &$audit, &$version): void {
                $locked = $this->authoring->lockExpected($course, $expectedVersion);
                $audit = $this->publishing->auditCatalogCard($locked->fresh());
                if ($audit['ready']) {
                    $locked->update(['is_catalog_visible' => true]);
                    $version = $this->authoring->advance($locked);
                }
            }, 3);
        } catch (\Throwable $exception) {
            report($exception);
            return ['status' => 'catalog_publish_failed', 'course' => $course];
        }
        if (!($audit['ready'] ?? false)) {
            return [
                'status' => 'catalog_not_ready',
                'course' => $course,
                'issues' => (array) ($audit['issues'] ?? []),
            ];
        }

        return ['status' => 'catalog_published', 'course' => $course, 'version' => $version];
    }

    private function completeExistingIntent(CourseRequest $request, Course $course): void
    {
        DB::transaction(function () use ($request, $course): void {
            Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $this->createIntents->completeRedirect(
                $request,
                route('admin.courses.show', $course),
                302,
                Course::class,
                $course->id
            );
        }, 3);
    }

    private function storeImage(CourseRequest $request): ?string
    {
        if (!$request->hasFile('image')) {
            return null;
        }
        $path = $this->files->storeTrackedUpload($request->file('image'), 'courses');
        if (!is_string($path) || trim($path) === '') {
            throw new \RuntimeException('Course image storage failed');
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function courseData(CourseRequest $request): array
    {
        return collect($request->validated())->except([
            'image',
            'classification_ids',
            'classification_ids_present',
            'teacher_ids',
            'teacher_ids_present',
            'access_plans',
            'authoring_version',
            'authoring_request_id',
            'grant_chat_attachments_to_current_enrollments',
            'grant_project_followup_attachments_to_current_enrollments',
            'is_main_course',
            // Publication state and the compatibility price mirror are owned
            // by the publishing and access-plan services, never a general save.
            'is_coming_soon',
            'is_catalog_visible',
            'price',
            'publishing_intent',
        ])->all();
    }
}
