<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Services\CertificateEligibilityService;
use App\Services\CoursePresentationService;
use App\Services\CourseLearningHealthService;
use App\Services\CurriculumCompletionService;
use App\Services\LearningDashboardService;
use App\Services\StudentProgressSummaryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProjectCompletionTruthTest extends ApiTestCase
{
    public static function boundaries(): iterable
    {
        foreach (['progress', 'certificate', 'certificate_batch', 'learning_dashboard', 'admin_summary', 'studio_health', 'earned_revision'] as $boundary) {
            yield "$boundary: passed without progress" => [$boundary, true];
            yield "$boundary: stale progress without pass" => [$boundary, false];
        }
    }

    #[DataProvider('boundaries')]
    public function test_review_result_owns_completion_at_every_read_boundary(string $boundary, bool $passed): void
    {
        $enrollment = CourseEnrollment::create([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
        ]);
        DB::table('student_section_progress')->insert([
            'user_id' => $this->user->id,
            'course_section_id' => $this->sectionId,
            'is_completed' => true,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('lesson_media_states')->insert([
            'lesson_id' => 10,
            'duration_seconds' => 60,
            'status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('lesson_watch_evidence')->insert([
            'user_id' => $this->user->id,
            'lesson_id' => 10,
            'course_section_id' => $this->sectionId,
            'duration_seconds' => 60,
            'verified_seconds' => 60,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // An ordinary crossing project is mandatory too, not only a project
        // carrying the optional graduation label.
        $project = Project::create(['is_graduation_project' => false]);
        $projectSectionId = DB::table('course_sections')->insertGetId([
            'course_id' => $this->courseId,
            'module_id' => $this->moduleId,
            'section_type' => 'project',
            'sectionable_type' => Project::class,
            'sectionable_id' => $project->id,
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ProjectSubmission::create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'project_id' => $project->id,
            'idempotency_key' => 'completion-truth',
            'review_status' => $passed
                ? ProjectSubmission::STATUS_PASSED
                : ProjectSubmission::STATUS_NEEDS_RESUBMISSION,
            'submitted_at' => now()->subMinute(),
            'reviewed_at' => now(),
        ]);
        if (!$passed) {
            DB::table('student_section_progress')->insert([
                'user_id' => $this->user->id,
                'course_section_id' => $projectSectionId,
                'is_completed' => true,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $course = Course::findOrFail($this->courseId);
        $actual = match ($boundary) {
            'progress' => app(CoursePresentationService::class)
                ->progressSummary((int) $this->user->id, $this->courseId)['is_completed'],
            'certificate' => app(CertificateEligibilityService::class)
                ->for($this->user, $course)['available'],
            'certificate_batch' => app(CertificateEligibilityService::class)->forCourses(
                $this->user,
                collect([$course]),
                collect([$enrollment]),
                [$this->courseId => ['certificate_available' => true]]
            )[$this->courseId]['available'],
            'learning_dashboard' => app(LearningDashboardService::class)
                ->forUser($this->user)['items']->first()['is_completed'],
            'admin_summary' => app(StudentProgressSummaryService::class)
                ->latestForUsers(collect([$this->user]))->first()['progress']['completed_sections'] === 2,
            'studio_health' => app(CourseLearningHealthService::class)
                ->forCourse($course)['completed_students'] === 1,
            'earned_revision' => app(CurriculumCompletionService::class)
                ->markCompleted((int) $this->user->id, $this->courseId) !== null,
        };

        self::assertSame($passed, $actual, $boundary);
        // Reading an authoritative result must not manufacture a progress row.
        self::assertSame(!$passed, DB::table('student_section_progress')
            ->where('user_id', $this->user->id)
            ->where('course_section_id', $projectSectionId)
            ->exists());
    }
}
