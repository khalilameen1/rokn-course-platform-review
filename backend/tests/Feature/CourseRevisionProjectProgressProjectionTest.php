<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\StudentSectionProgress;
use App\Models\User;
use App\Services\CourseRevisionLearnerReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CourseRevisionProjectProgressProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_reviews_are_projected_across_revisions_users_and_time_boundaries(): void
    {
        $student = $this->student('project-lineage@example.test');
        $otherStudent = $this->student('project-lineage-other@example.test');
        $currentCourse = $this->course('الكورس الحالي');
        $archiveCourse = $this->course('نسخة الكورس القديمة');
        $currentProject = Project::query()->create(['requirements_text_ar' => 'نفذ المشروع']);
        $historicalProject = Project::query()->create(['requirements_text_ar' => 'المشروع القديم']);
        $currentProjectSection = $this->section(
            $currentCourse,
            Project::class,
            (int) $currentProject->id,
            2
        );
        $historicalProjectSection = $this->section(
            $archiveCourse,
            Project::class,
            (int) $historicalProject->id,
            2
        );
        $lessonSection = $this->section($currentCourse, Lesson::class, 991, 1);
        $reviewedAt = now()->subHour()->startOfSecond();
        $lessonCompletedAt = now()->subHours(2)->startOfSecond();

        $revision = CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $currentCourse->id,
            'revision_course_id' => $archiveCourse->id,
            'base_authoring_version' => 1,
            'published_authoring_version' => 2,
            'status' => CourseAuthoringRevision::ARCHIVED,
            'clone_key' => (string) Str::uuid(),
            'published_at' => now()->subMinutes(30),
        ]);
        $this->learnerAlias(
            (int) $revision->id,
            CourseSection::class,
            (int) $historicalProjectSection->id,
            (int) $currentProjectSection->id
        );
        $this->learnerAlias(
            (int) $revision->id,
            Project::class,
            (int) $historicalProject->id,
            (int) $currentProject->id
        );

        ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $student->id,
            'project_id' => $historicalProject->id,
            'idempotency_key' => 'historical-project-pass',
            'review_status' => ProjectSubmission::STATUS_PASSED,
            'submitted_at' => $reviewedAt->copy()->subMinute(),
            'reviewed_at' => $reviewedAt,
        ]);
        ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $otherStudent->id,
            'project_id' => $currentProject->id,
            'idempotency_key' => 'other-student-retry',
            'review_status' => ProjectSubmission::STATUS_NEEDS_RESUBMISSION,
            'submitted_at' => now()->subMinutes(5),
            'reviewed_at' => now()->subMinutes(4),
        ]);
        StudentSectionProgress::query()->create([
            'user_id' => $otherStudent->id,
            'course_section_id' => $currentProjectSection->id,
            'is_completed' => true,
            'completed_at' => now()->subDay(),
        ]);
        StudentSectionProgress::query()->create([
            'user_id' => $student->id,
            'course_section_id' => $lessonSection->id,
            'is_completed' => true,
            'completed_at' => $lessonCompletedAt,
        ]);
        $storedProgressCount = StudentSectionProgress::query()->count();
        $reads = app(CourseRevisionLearnerReadService::class);

        $projectProgress = $reads->completedSectionProgress(
            (int) $student->id,
            (int) $currentProjectSection->id
        );
        self::assertNotNull($projectProgress);
        self::assertFalse($projectProgress->exists);
        self::assertSame($reviewedAt->timestamp, $projectProgress->completed_at?->timestamp);
        self::assertTrue($reads->completedSectionIds(
            (int) $student->id,
            [$currentProjectSection->id, $lessonSection->id]
        )->contains((int) $currentProjectSection->id));

        $beforeReview = $reads->sectionProgressRowsForUsers(
            [$student->id, $otherStudent->id],
            [$currentProjectSection->id, $lessonSection->id],
            $reviewedAt
        );
        self::assertFalse($beforeReview->contains(
            fn (StudentSectionProgress $row): bool =>
                (int) $row->course_section_id === (int) $currentProjectSection->id
        ));

        $afterReview = $reads->sectionProgressRowsForUsers(
            [$student->id, $otherStudent->id],
            [$currentProjectSection->id, $lessonSection->id],
            $reviewedAt->copy()->addSecond()
        );
        self::assertTrue($afterReview->contains(
            fn (StudentSectionProgress $row): bool =>
                (int) $row->user_id === (int) $student->id
                && (int) $row->course_section_id === (int) $currentProjectSection->id
                && $row->is_completed
        ));
        self::assertFalse($afterReview->contains(
            fn (StudentSectionProgress $row): bool =>
                (int) $row->user_id === (int) $otherStudent->id
                && (int) $row->course_section_id === (int) $currentProjectSection->id
        ));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $allRows = $reads->sectionProgressRowsForUsers(
            [$student->id, $otherStudent->id],
            [$currentProjectSection->id, $lessonSection->id]
        );
        $submissionQueryCount = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(
                strtolower((string) $query['query']),
                'project_submissions'
            ))
            ->count();
        DB::disableQueryLog();
        self::assertSame(1, $submissionQueryCount);
        $otherProject = $allRows->first(
            fn (StudentSectionProgress $row): bool =>
                (int) $row->user_id === (int) $otherStudent->id
                && (int) $row->course_section_id === (int) $currentProjectSection->id
        );
        self::assertNotNull($otherProject);
        self::assertFalse($otherProject->is_completed);
        self::assertFalse($reads->completedSectionIds(
            (int) $otherStudent->id,
            [$currentProjectSection->id]
        )->contains((int) $currentProjectSection->id));
        $lessonProgress = $allRows->first(
            fn (StudentSectionProgress $row): bool =>
                (int) $row->user_id === (int) $student->id
                && (int) $row->course_section_id === (int) $lessonSection->id
        );
        self::assertNotNull($lessonProgress);
        self::assertTrue($lessonProgress->exists);
        self::assertSame($lessonCompletedAt->timestamp, $lessonProgress->completed_at?->timestamp);
        self::assertSame($storedProgressCount, StudentSectionProgress::query()->count());
    }

    private function student(string $email): User
    {
        $student = new User();
        $student->forceFill([
            'name_ar' => 'طالب ركن',
            'email' => $email,
            'role' => 'client',
            'active' => true,
        ])->save();

        return $student;
    }

    private function course(string $name): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => $name,
            'price' => 0,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'authoring_version' => 2,
            'last_published_authoring_version' => 2,
            'published_at' => now(),
        ])->save();

        return $course;
    }

    private function section(
        Course $course,
        string $type,
        int $contentId,
        int $order
    ): CourseSection {
        $module = CourseModule::query()->firstOrCreate(
            ['course_id' => $course->id],
            ['title_ar' => 'الوحدة', 'order' => 1]
        );

        return CourseSection::query()->create([
            'course_id' => $course->id,
            'module_id' => $module->id,
            'sectionable_type' => $type,
            'sectionable_id' => $contentId,
            'section_type' => $type === Project::class ? 'project' : 'lesson',
            'title_ar' => $type === Project::class ? 'المشروع' : 'الدرس',
            'order' => $order,
        ]);
    }

    private function learnerAlias(
        int $revisionId,
        string $type,
        int $historicalId,
        int $currentId
    ): void {
        DB::table('course_authoring_revision_entities')->insert([
            'course_authoring_revision_id' => $revisionId,
            'entity_type' => $type,
            'source_entity_id' => $historicalId,
            'revision_entity_id' => $currentId,
            'survives_publish' => true,
            'carries_learner_state' => true,
            'learner_root_entity_id' => $historicalId,
        ]);
    }
}
