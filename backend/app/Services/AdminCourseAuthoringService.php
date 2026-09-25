<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\CourseAuthoringEdit;
use App\Models\Course;
use App\Models\Photo;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AdminCourseAuthoringService
{
    public function __construct(
        private CoursePlanAuthoringService $planAuthoring,
        private CoursePlanAttachmentGrantService $attachmentGrants,
        private CourseAuthoringConcurrencyService $authoring,
        private CourseHeroSelectionService $heroSelection,
        private CoursePathSelectionService $pathSelection,
        private CoursePublishingService $publishing,
        private CourseStagedAuthoringService $stagedAuthoring,
        private CourseRevisionResolver $revisions,
        private StoredFileDeletionService $files,
        private StoredFileUploadService $uploads
    ) {
    }

    /**
     * @param Closure(Course):void $completeIntent
     * @return array{status:string, course:?Course}
     */
    public function create(CourseAuthoringEdit $edit, Closure $completeIntent): array
    {
        $requestId = $edit->requestId;
        if (!$requestId) {
            throw new \InvalidArgumentException('Course creation requires a stable request identity.');
        }
        $existing = Course::query()->where('authoring_request_id', $requestId)->first();
        if ($existing) {
            $this->completeExistingIntent($existing, $completeIntent);
            return ['status' => 'existing', 'course' => $existing];
        }

        $data = array_merge($edit->attributes, [
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
            $imagePath = $this->storeImage($edit->image);
            $course = DB::transaction(function () use ($data, $edit, $imagePath, $completeIntent): Course {
                $course = Course::create($data);
                $this->planAuthoring->createDefaults($course);
                if ($edit->planOffers !== null) {
                    $this->planAuthoring->syncAdminPlans(
                        $course,
                        $edit->planOffers
                    );
                }
                $course->classifications()->sync($edit->classificationIds ?? []);
                $course->teachers()->sync($edit->teacherIds ?? []);
                if ($imagePath) {
                    $course->allPhotos()->create(['path' => $imagePath, 'type' => 'featured']);
                }
                $completeIntent($course);

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
        CourseAuthoringEdit $edit,
        Course $course,
        bool $administrator,
        bool $canCurateHome
    ): array
    {
        $wasDraft = (bool) $course->is_coming_soon;
        $publishingRequested = $edit->publishingRequested;
        $catalogVisibilitySubmitted = $edit->catalogVisible !== null;
        $catalogVisible = $catalogVisibilitySubmitted
            ? $edit->catalogVisible
            : (bool) $course->is_catalog_visible;
        $data = $edit->attributes;
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
        $managedDraft = $this->revisions->isManagedDraft($course);
        $canonical = $this->revisions->canonicalFor($course);
        $explicitHero = $managedDraft
            ? $this->revisions->explicitHeroSelection($course)
            : null;
        $preservedHero = $managedDraft
            // A fresh clone clears its implementation flag. Once the editor
            // explicitly checks or unchecks the control, the draft owns that
            // intent across every later partial save.
            ? ($explicitHero ?? (bool) $canonical->is_main_course)
            : (!(bool) $course->is_coming_soon && (bool) $course->is_main_course);
        $heroRequested = $canCurateHome && $edit->mainCourse !== null
            ? $edit->mainCourse
            : $preservedHero;

        try {
            $imagePath = $this->storeImage($edit->image);
            if ($imagePath) {
                $oldPhotos = $course->allPhotos()->where('type', 'featured')->get(['photos.id', 'photos.path']);
            }
            DB::transaction(function () use (
                $course,
                $data,
                $edit,
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
                $locked = $this->authoring->lockExpected($course, (int) $edit->expectedVersion);
                $previousPathId = $locked->path_id === null ? null : (int) $locked->path_id;
                $locked->update($data);
                if ($managedDraft && array_key_exists('path_id', $data)) {
                    $this->pathSelection->recordReviewedSelection($locked, $previousPathId);
                }
                if ($wasDraft) {
                    $locked->updateQuietly(['is_main_course' => $heroRequested]);
                    if ($managedDraft && $canCurateHome && $edit->mainCourse !== null) {
                        $this->stagedAuthoring->confirmHeroSelection($locked);
                    }
                }
                if ($edit->planOffers !== null) {
                    $this->planAuthoring->syncAdminPlans(
                        $locked,
                        $edit->planOffers
                    );
                }
                if (!$managedDraft && $administrator && (
                    $edit->grantChatAttachments
                    || $edit->grantProjectAttachments
                )) {
                    $this->attachmentGrants->grantAttachmentsToCurrentEnrollments(
                        $locked,
                        $edit->grantChatAttachments,
                        $edit->grantProjectAttachments
                    );
                }
                if ($edit->classificationIds !== null) {
                    $locked->classifications()->sync($edit->classificationIds);
                    $this->stagedAuthoring->confirmClassificationSelection($locked);
                }
                if ($edit->teacherIds !== null) {
                    $locked->teachers()->sync($edit->teacherIds);
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
                    $administrator && $edit->grantChatAttachments,
                    $administrator && $edit->grantProjectAttachments
                );
                $course = $published['course'];
                $ownedVersion = (int) $published['published_revision'];
            } catch (ValidationException $exception) {
                // The editor save above already committed. A readiness rejection
                // must acknowledge that version just like a first publication;
                // real revision conflicts still require reconciliation.
                if ($exception->status === 422 && array_keys($exception->errors()) === ['course']) {
                    return [
                        'status' => 'not_ready',
                        'course' => $course,
                        'issues' => $exception->errors()['course'],
                    ];
                }
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
                    CourseContentNotificationService::notifyCourseUpdate(
                        course: $locked->fresh(),
                        deliveryKey: 'course-published:'.$locked->id.':v'.$publishedVersion
                    );
                } elseif ($previousVersion === 0 && $catalogVisible) {
                    CourseContentNotificationService::notifyNewCourse(
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

    /** @param Closure(Course):void $completeIntent */
    private function completeExistingIntent(Course $course, Closure $completeIntent): void
    {
        DB::transaction(function () use ($course, $completeIntent): void {
            $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $completeIntent($locked);
        }, 3);
    }

    private function storeImage(?UploadedFile $image): ?string
    {
        if (!$image) {
            return null;
        }
        $path = $this->uploads->storeTrackedUpload($image, 'courses');
        if (!is_string($path) || trim($path) === '') {
            throw new \RuntimeException('Course image storage failed');
        }

        return $path;
    }
}
