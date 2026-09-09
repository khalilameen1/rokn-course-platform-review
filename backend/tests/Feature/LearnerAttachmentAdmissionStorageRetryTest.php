<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Models\AiInputAttachment;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\FeedbackReport;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Services\CourseAccessPlanService;
use App\Services\ProjectSubmissionService;
use App\Services\StoredFileReferenceService;
use App\Services\SupportCaseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LearnerAttachmentAdmissionStorageRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('feedback');
        config(['projects.submission_disk' => 'local']);
    }

    public function test_project_retry_keeps_all_files_when_old_orphan_cleanup_has_started(): void
    {
        [$user, $project] = $this->projectContext();
        $service = app(ProjectSubmissionService::class);
        $requestId = (string) Str::uuid();
        $files = [
            UploadedFile::fake()->createWithContent('before.txt', str_repeat('Before work notes ', 50)),
            UploadedFile::fake()->createWithContent('after.txt', str_repeat('After work notes ', 50)),
        ];
        DB::statement("CREATE TRIGGER reject_submission_admission BEFORE INSERT ON project_submissions
            BEGIN SELECT RAISE(ABORT, 'admission unavailable'); END");
        try {
            $service->submit($user, $project, null, $files, $requestId);
            self::fail('Failed admission must not commit a submission.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('admission unavailable', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER reject_submission_admission');
        }
        self::assertSame(0, ProjectSubmission::count());
        self::assertSame(0, AiInputAttachment::count());
        $oldCleanups = AccountFileDeletion::query()->get();
        self::assertCount(2, $oldCleanups);
        $oldPaths = $oldCleanups->pluck('path')->all();
        $this->travel(61)->minutes();
        $disk = Storage::disk('local');
        $accepted = null;
        $interleaved = \Mockery::mock($disk);
        $interleaved->shouldReceive('delete')->once()->with($oldPaths[0])
            ->andReturnUsing(function () use ($service, $user, $project, $files, $requestId, $disk, $oldPaths, &$accepted): bool {
                // The real cleanup already observed no committed owner.
                $accepted = $service->submit($user, $project, null, $files, $requestId);
                return $disk->delete($oldPaths[0]);
            });
        Storage::set('local', $interleaved);
        try {
            (new DeleteAccountFile((int) $oldCleanups[0]->id))->handle(app(StoredFileReferenceService::class));
        } finally {
            Storage::set('local', $disk);
        }
        (new DeleteAccountFile((int) $oldCleanups[1]->id))->handle(app(StoredFileReferenceService::class));
        self::assertInstanceOf(ProjectSubmission::class, $accepted);
        self::assertSame('pending', $accepted->review_status);
        $descriptors = data_get($accepted->submission_metadata, 'files');
        self::assertCount(2, $descriptors);
        foreach ($descriptors as $index => $descriptor) {
            $disk->assertExists($descriptor['path']);
            self::assertSame(file_get_contents($files[$index]->getPathname()), $disk->get($descriptor['path']));
            self::assertNotContains($descriptor['path'], $oldPaths);
        }
        self::assertSame($descriptors[0]['path'], $accepted->submission_file);
        self::assertSame(2, AiInputAttachment::where('owner_id', $accepted->id)->count());
        $replayDisk = \Mockery::mock($disk);
        $replayDisk->shouldNotReceive('putFileAs');
        Storage::set('local', $replayDisk);
        try {
            $replay = $service->submit($user, $project, null, $files, $requestId);
            try {
                $service->submit($user, $project, null, array_reverse($files), $requestId);
                self::fail('Reordering the submitted files must not rebind an accepted receipt.');
            } catch (\UnexpectedValueException $exception) {
                self::assertSame('Project submission idempotency key was reused for different content.', $exception->getMessage());
            }
        } finally {
            Storage::set('local', $disk);
        }
        self::assertSame($accepted->public_id, $replay->public_id);
        self::assertSame(1, ProjectSubmission::count());
        self::assertCount(2, $disk->allFiles('project_submissions'));
        self::assertSame(0, AiUsageEvent::count());
        Http::assertNothingSent();
    }

    public function test_support_retry_keeps_screenshot_when_old_orphan_cleanup_has_started(): void
    {
        $report = FeedbackReport::create([
            'public_id' => (string) Str::ulid(), 'category' => 'other',
            'status' => 'new', 'priority' => 'normal', 'message' => 'مشكلة في الصفحة',
        ]);
        $service = app(SupportCaseService::class);
        $requestId = (string) Str::uuid();
        $image = UploadedFile::fake()->image('screen.png', 20, 20);
        DB::statement("CREATE TRIGGER reject_support_message BEFORE INSERT ON support_case_messages
            BEGIN SELECT RAISE(ABORT, 'message unavailable'); END");
        try {
            $service->appendLearnerMessage($report, null, 'صورة المشكلة', $requestId, $image);
            self::fail('Failed message admission must not be acknowledged.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('message unavailable', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER reject_support_message');
        }
        self::assertSame(0, $report->messages()->count());
        self::assertSame(0, $report->attachments()->count());
        $cleanup = AccountFileDeletion::query()->sole();
        $oldPath = $cleanup->path;
        $disk = Storage::disk('feedback');
        $sanitizedBytes = $disk->get($oldPath);
        $this->travel(61)->minutes();
        $message = null;
        $interleaved = \Mockery::mock($disk);
        $interleaved->shouldReceive('delete')->once()->with($oldPath)
            ->andReturnUsing(function () use ($service, $report, $requestId, $image, $disk, $oldPath, &$message): bool {
                $message = $service->appendLearnerMessage($report, null, 'صورة المشكلة', $requestId, $image);
                return $disk->delete($oldPath);
            });
        Storage::set('feedback', $interleaved);
        try {
            (new DeleteAccountFile((int) $cleanup->id))->handle(app(StoredFileReferenceService::class));
        } finally {
            Storage::set('feedback', $disk);
        }
        $attachment = $report->attachments()->sole();
        $disk->assertExists($attachment->path);
        self::assertSame($sanitizedBytes, $disk->get($attachment->path));
        self::assertNotSame($oldPath, $attachment->path);
        self::assertSame($message->id, $attachment->support_case_message_id);
        $readOnlyDisk = \Mockery::mock($disk);
        $readOnlyDisk->shouldNotReceive('put');
        Storage::set('feedback', $readOnlyDisk);
        try {
            $replay = $service->appendLearnerMessage($report, null, 'صورة المشكلة', $requestId, $image);
            try {
                $service->appendLearnerMessage($report, null, 'رسالة مختلفة', $requestId, $image);
                self::fail('Changed content must not rebind an accepted support message.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
            }
        } finally {
            Storage::set('feedback', $disk);
        }
        self::assertSame($message->public_id, $replay->public_id);
        self::assertSame(1, $report->messages()->count());
        self::assertSame(2, (int) $report->fresh()->version);
        self::assertCount(1, $disk->allFiles());
        Http::assertNothingSent();
    }

    private function projectContext(): array
    {
        $user = new \App\Models\User();
        $user->forceFill(['name' => 'Learner', 'email' => 'storage-retry@example.test',
            'active' => true, 'role' => 'client'])->save();
        $course = Course::factory()->make();
        $course->forceFill(['tenant_id' => 1, 'is_coming_soon' => false])->save();
        $project = Project::factory()->create();
        $module = CourseModule::create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        CourseSection::factory()->project()->create(['course_id' => $course->id, 'module_id' => $module->id,
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id]);
        $plan = CourseAccessPlan::create(['course_id' => $course->id, 'code' => 'basic',
            'name_ar' => 'تعلم', 'price_coins' => 100, 'minimum_paid_coins' => 0,
            'project_feedback_level' => 'pass_only']);
        $enrollment = new CourseEnrollment();
        $enrollment->forceFill(['tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id,
            'access_plan_id' => $plan->id,
            'access_plan_snapshot' => app(CourseAccessPlanService::class)->snapshot($plan->fresh()),
            'is_active' => true, 'enrolled_at' => now()])->save();
        return [$user, $project];
    }
}
