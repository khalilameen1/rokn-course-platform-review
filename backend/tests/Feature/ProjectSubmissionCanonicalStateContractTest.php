<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\CourseRevisionLearnerReadService;
use App\Services\ProjectSubmissionReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProjectSubmissionCanonicalStateContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_learner_runtime_has_no_shadow_evaluation_store(): void
    {
        // The replaced evaluation table and model are gone from the migrated
        // schema, so no learner read or write path can consult them; the
        // project, certificate and portfolio feature tests run against this
        // schema.
        self::assertFalse(Schema::hasTable('user_project_evaluations'));
        self::assertFalse(class_exists('App\\Models\\UserProjectEvaluation'));
    }

    public function test_revision_reader_selects_one_latest_canonical_submission_per_logical_project(): void
    {
        $student = $this->student();
        $current = $this->course('الكورس الحالي');
        $archive = $this->course('نسخة الكورس القديمة');
        $currentProject = Project::query()->create(['requirements_text_ar' => 'نفذ المشروع']);
        $historicalProject = Project::query()->create(['requirements_text_ar' => 'المشروع القديم']);
        $this->section($current, $currentProject);
        $this->section($archive, $historicalProject);
        $revision = CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $current->id,
            'revision_course_id' => $archive->id,
            'base_authoring_version' => 1,
            'published_authoring_version' => 2,
            'status' => CourseAuthoringRevision::ARCHIVED,
            'clone_key' => (string) Str::uuid(),
            'published_at' => now()->subMinutes(30),
        ]);
        DB::table('course_authoring_revision_entities')->insert([
            'course_authoring_revision_id' => $revision->id,
            'entity_type' => Project::class,
            'source_entity_id' => $historicalProject->id,
            'revision_entity_id' => $currentProject->id,
            'survives_publish' => true,
            'carries_learner_state' => true,
            'learner_root_entity_id' => $historicalProject->id,
        ]);
        $reads = app(CourseRevisionLearnerReadService::class);

        // An older pass on the archived project, then a newer attempt that
        // needs changes on the current one: the newest attempt is canonical.
        $this->attempt($student, $historicalProject, ProjectSubmission::STATUS_PASSED);
        $this->attempt($student, $historicalProject, ProjectSubmission::STATUS_PASSED);
        $latest = $this->attempt($student, $currentProject, ProjectSubmission::STATUS_NEEDS_RESUBMISSION);
        $this->attempt($this->student(), $currentProject, ProjectSubmission::STATUS_PASSED);

        $submissions = $reads->projectSubmissions((int) $student->id, [$currentProject->id]);
        self::assertSame([(int) $currentProject->id], $submissions->keys()->all());
        self::assertSame((int) $latest->id, (int) $submissions->first()->id);
        self::assertSame([], $reads->passedProjectIds((int) $student->id, [$currentProject->id])->all());

        // A later passing attempt, even on the archived alias, becomes canonical.
        $passed = $this->attempt($student, $historicalProject, ProjectSubmission::STATUS_PASSED);
        $submissions = $reads->projectSubmissions((int) $student->id, [$currentProject->id]);
        self::assertCount(1, $submissions);
        self::assertSame((int) $passed->id, (int) $submissions->first()->id);
        self::assertSame(
            [(int) $currentProject->id],
            $reads->passedProjectIds((int) $student->id, [$currentProject->id])->all()
        );
    }

    public function test_project_review_cannot_mutate_an_issued_certificate_claim(): void
    {
        $student = $this->student();
        $course = $this->course('كورس الشهادة');
        $project = Project::query()->create(['requirements_text_ar' => 'نفذ المشروع']);
        $this->section($course, $project);
        $certificate = Certificate::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $student->id, 'course_id' => $course->id,
            'holder_name' => 'طالب ركن', 'course_name' => $course->name_ar,
            'generated_at' => now(), 'image_path' => 'pending', 'status' => 'active',
            'verification_level' => 'completion',
        ]);
        $issued = $certificate->fresh()->getAttributes();
        $reviewer = $this->student(['role' => 'admin']);
        $reviews = app(ProjectSubmissionReviewService::class);

        $rejected = $reviews->reviewByStaff(
            $this->attempt($student, $project, ProjectSubmission::STATUS_PENDING),
            $reviewer,
            false,
            'ارفع تنفيذك للمشروع'
        );
        self::assertSame(ProjectSubmission::STATUS_NEEDS_RESUBMISSION, $rejected->review_status);
        self::assertSame($issued, $certificate->fresh()->getAttributes());

        $accepted = $reviews->reviewByStaff(
            $this->attempt($student, $project, ProjectSubmission::STATUS_PENDING),
            $reviewer,
            true,
            'تنفيذ ممتاز'
        );
        self::assertSame(ProjectSubmission::STATUS_PASSED, $accepted->review_status);
        self::assertSame($issued, $certificate->fresh()->getAttributes());
        self::assertSame(1, Certificate::query()->count());
    }

    private function attempt(User $student, Project $project, string $status): ProjectSubmission
    {
        return ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $student->id,
            'project_id' => $project->id,
            'idempotency_key' => (string) Str::uuid(),
            'submission_text' => 'تنفيذ واضح للمشروع',
            'effort_status' => 'valid',
            'review_status' => $status,
            'submitted_at' => now(),
            'reviewed_at' => $status === ProjectSubmission::STATUS_PENDING ? null : now(),
        ])->fresh();
    }

    private function student(array $attributes = []): User
    {
        $student = new User;
        $student->forceFill(array_replace([
            'name' => 'طالب ركن',
            'email' => Str::uuid().'@test.rokn',
            'role' => 'client',
            'active' => true,
        ], $attributes))->save();

        return $student;
    }

    private function course(string $name): Course
    {
        $course = new Course;
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

    private function section(Course $course, Project $project): CourseSection
    {
        $module = CourseModule::query()->firstOrCreate(
            ['course_id' => $course->id],
            ['title_ar' => 'الوحدة', 'order' => 1]
        );

        return CourseSection::query()->create([
            'course_id' => $course->id,
            'module_id' => $module->id,
            'sectionable_type' => Project::class,
            'sectionable_id' => $project->id,
            'section_type' => 'project',
            'title_ar' => 'المشروع',
            'order' => 1,
        ]);
    }
}
