<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Middleware\RequireProductFeature;
use App\Models\Project;
use App\Services\ProjectSubmissionOrchestrator;
use App\Services\ProjectSubmissionService;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToWriteFile;
use Mockery;

final class ProjectUploadFailureResponseTest extends ApiTestCase
{
    public function test_remote_storage_failure_returns_a_retryable_json_response_before_proxy_timeout(): void
    {
        // This test isolates the upload failure contract from the independent
        // operations feature gate exercised by ProductFeatureFlagTest.
        $this->withoutMiddleware(RequireProductFeature::class);
        Queue::fake();
        config()->set('projects.submission_disk', 'project-test');
        config()->set('filesystems.disks.project-test', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/project-upload-failure'),
            'throw' => true,
        ]);

        $projectId = $this->createAccessibleProject();

        $disk = Mockery::mock();
        $disk->shouldReceive('putFileAs')
            ->once()
            ->andThrow(UnableToWriteFile::atLocation('project-submission', 'storage timeout'));
        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')->twice()->with('project-test')->andReturn($disk);
        $this->app->instance('filesystem', $factory);
        $this->app->instance(FilesystemFactory::class, $factory);

        $baseImage = UploadedFile::fake()->image('attempt.jpg', 600, 600);
        $imageBytes = (string) file_get_contents($baseImage->getRealPath());
        $allowedSize = ProjectSubmissionOrchestrator::maximumFileBytes();
        $allowedImage = UploadedFile::fake()->createWithContent(
            'attempt.jpg',
            $imageBytes.str_repeat("\0", $allowedSize - strlen($imageBytes))
        );

        $response = $this->actingAs($this->user, 'api')->post(
            "/api/v1/projects/{$projectId}/submissions",
            [
                'submission_files' => [$allowedImage],
                'client_submission_id' => 'project-upload-timeout-1',
            ],
            ['Accept' => 'application/json']
        );

        $response
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'project_upload_temporarily_unavailable')
            ->assertHeader('Retry-After', '3');
        self::assertSame(0, DB::table('project_submissions')->count());
    }

    public function test_file_above_provider_limit_is_rejected_before_storage_or_ai_usage(): void
    {
        $this->withoutMiddleware(RequireProductFeature::class);
        config()->set('projects.maximum_file_kilobytes', 25600);
        config()->set('openrouter.attachment_provider_max_bytes', 8 * 1024 * 1024);
        $projectId = $this->createAccessibleProject();
        $oversizedPdf = UploadedFile::fake()->createWithContent(
            'oversized.pdf',
            "%PDF-1.4\n".str_repeat('A', 9 * 1024 * 1024)."\n%%EOF"
        );

        $response = $this->actingAs($this->user, 'api')->post(
            "/api/v1/projects/{$projectId}/submissions",
            [
                'submission_files' => [$oversizedPdf],
                'client_submission_id' => 'project-upload-too-large-1',
            ],
            ['Accept' => 'application/json']
        );
        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['submission_files.0']);
        self::assertStringContainsString(
            '8 ميجابايت',
            (string) ($response->json('errors')['submission_files.0'][0] ?? '')
        );

        self::assertSame(0, DB::table('project_submissions')->count());
        self::assertSame(0, DB::table('ai_usage_events')->count());
        self::assertSame(0, DB::table('account_file_deletions')->count());
    }

    public function test_extracted_document_over_review_budget_is_rejected_before_storage(): void
    {
        config()->set('projects.evaluation_max_input_characters', 60000);
        $project = Project::with('section')->findOrFail($this->createAccessibleProject());
        $document = UploadedFile::fake()->createWithContent(
            'long-project.txt',
            str_repeat('A', 60001)
        );

        try {
            app(ProjectSubmissionService::class)->submit(
                $this->user,
                $project,
                null,
                [$document],
                'project-input-too-long-1'
            );
            self::fail('An input larger than the evaluator contract reached storage.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('submission_files', $exception->errors());
        }

        self::assertSame(0, DB::table('project_submissions')->count());
        self::assertSame(0, DB::table('ai_usage_events')->count());
        self::assertSame(0, DB::table('account_file_deletions')->count());
    }

    public function test_text_only_submission_cannot_bypass_the_shared_review_input_budget(): void
    {
        config()->set('projects.evaluation_max_input_characters', 60000);
        $projectId = $this->createAccessibleProject();
        DB::table('projects')->where('id', $projectId)->update([
            'requirements_text' => str_repeat('م', 59995),
        ]);
        $project = Project::with('section')->findOrFail($projectId);

        try {
            app(ProjectSubmissionService::class)->submit(
                $this->user,
                $project,
                'محاولة واضحة',
                [],
                'project-text-input-too-long-1'
            );
            self::fail('A text-only input larger than the evaluator contract was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('submission_text', $exception->errors());
        }

        self::assertSame(0, DB::table('project_submissions')->count());
        self::assertSame(0, DB::table('ai_usage_events')->count());
        self::assertSame(0, DB::table('account_file_deletions')->count());
    }

    public function test_project_payload_reports_the_same_provider_file_limit_used_by_admission(): void
    {
        $this->withoutMiddleware(RequireProductFeature::class);
        config()->set('projects.maximum_file_kilobytes', 25600);
        config()->set('openrouter.attachment_provider_max_bytes', 8 * 1024 * 1024);
        $projectId = $this->createAccessibleProject();

        $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/projects/{$projectId}")
            ->assertOk()
            ->assertJsonPath('data.submission_max_file_bytes', 8 * 1024 * 1024);
    }

    private function createAccessibleProject(): int
    {
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'ارفع صورة لما نفذته',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $this->courseId,
            'module_id' => $this->moduleId,
            'title_ar' => 'مشروع العبور',
            'section_type' => 'project',
            'sectionable_type' => Project::class,
            'sectionable_id' => $projectId,
            'order' => 2,
            'sort_order' => 2,
            'is_free' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $projectId;
    }
}
