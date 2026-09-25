<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectSubmissionOrchestrator;
use App\Services\ProjectSubmissionService;
use App\Support\UploadBudget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Tests\TestCase;

final class ProjectSubmissionBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The orphan ledger must really commit before file I/O, so no outer
        // test transaction may wrap this submission lifecycle.
        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    public function test_direct_submission_owners_preserve_the_supplied_budget_and_a_fresh_attempt_can_retry(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config()->set('projects.submission_disk', 'project-budget-local');
        config()->set('filesystems.disks.project-budget-local', ['driver' => 'local', 'throw' => true]);
        Storage::fake('project-budget-local');
        $user = User::query()->forceCreate(['name_ar' => 'الطالب', 'role' => 'client', 'active' => true]);
        $course = Course::factory()->make();
        $course->forceFill(['tenant_id' => 1, 'is_coming_soon' => false])->save();
        $project = Project::factory()->create();
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        CourseSection::factory()->project()->create([
            'course_id' => $course->id, 'sectionable_type' => Project::class, 'sectionable_id' => $project->id,
            'module_id' => $module->id,
        ]);
        CourseEnrollment::query()->forceCreate([
            'tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id,
            'is_active' => true, 'enrolled_at' => now(), 'access_granted_at' => now(),
        ]);
        $project->load('section.course');
        $image = UploadedFile::fake()->image('work.jpg', 600, 600);
        $elapsed = 0.0;
        $clock = static function () use (&$elapsed): float { return $elapsed; };
        $expired = UploadBudget::start(16, $clock);
        $elapsed = 17.0;

        foreach ([ProjectSubmissionService::class, ProjectSubmissionOrchestrator::class] as $owner) {
            try {
                app($owner)->submit($user, $project, null, [$image], 'budget-retry', [], $expired);
                self::fail('Submission reset or dropped the supplied upload budget.');
            } catch (UnableToWriteFile) {
                self::assertSame(0, DB::table('project_submissions')->count());
                self::assertSame(0, DB::table('account_file_deletions')->count());
                self::assertSame([], Storage::disk('project-budget-local')->allFiles());
            }
        }

        $result = app(ProjectSubmissionOrchestrator::class)->submit(
            $user, $project, null, [$image], 'budget-retry', [], UploadBudget::start(16, $clock)
        );
        self::assertSame('submitted', $result['state']);
        self::assertSame(1, DB::table('project_submissions')->count());
        self::assertCount(1, Storage::disk('project-budget-local')->allFiles());

        // An acknowledged replay is not another upload and is independent of
        // whether the old attempt's transfer budget is now exhausted.
        $replay = app(ProjectSubmissionOrchestrator::class)->submit(
            $user, $project, null, [$image], 'budget-retry', [], $expired
        );
        self::assertSame($result['submission']->id, $replay['submission']->id);
        self::assertSame(1, DB::table('project_submissions')->count());
        self::assertCount(1, Storage::disk('project-budget-local')->allFiles());
    }
}
