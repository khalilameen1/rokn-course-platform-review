<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Requests\API\SubmitProjectRequest;
use App\Http\Requests\API\UploadProjectFeedbackAttachmentRequest;
use App\Models\Project;
use App\Services\AiInputAttachmentService;
use App\Services\ProjectSubmissionFilePolicy;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProjectSubmissionFilePolicyTest extends TestCase
{
    public function test_catalogue_formats_are_normalized_deduplicated_and_limited_to_supported_content(): void
    {
        config(['projects.allowed_mime_types' => ['TEXT/PLAIN', 'text/plain', 'video/mp4']]);
        $policy = app(ProjectSubmissionFilePolicy::class);

        self::assertSame(['text/plain'], $policy->allowedMimeTypes());
        self::assertSame(['text/plain'], $policy->allowedMimeTypes(new Project()));
        self::assertSame([], $policy->allowedMimeTypes(new Project([
            'submission_allowed_mime_types' => [],
        ])));
        self::assertSame([], $policy->allowedMimeTypes(new Project([
            'submission_allowed_mime_types' => ['video/mp4'],
        ])));
    }

    public function test_container_transport_aliases_are_not_offered_or_accepted_as_project_formats(): void
    {
        $policy = app(ProjectSubmissionFilePolicy::class);
        $project = new Project();
        $archive = UploadedFile::fake()->createWithContent('work.zip', "PK\x05\x06".str_repeat("\0", 18));

        self::assertContains('application/zip', $policy->requestMimeTypes());
        self::assertNotContains('application/zip', $policy->allowedMimeTypes($project));
        self::assertFalse($policy->acceptsType($project, $archive));
    }

    #[DataProvider('sizeLimits')]
    public function test_http_and_followup_upload_limits_share_the_same_cap(int $projectKb, int $providerBytes, int $expectedBytes): void
    {
        config([
            'projects.maximum_file_kilobytes' => $projectKb,
            'openrouter.attachment_provider_max_bytes' => $providerBytes,
        ]);
        $policy = app(ProjectSubmissionFilePolicy::class);
        $submission = (new SubmitProjectRequest())->rules($policy);
        $followup = (new UploadProjectFeedbackAttachmentRequest())->rules(app(AiInputAttachmentService::class));

        self::assertSame($expectedBytes, ProjectSubmissionFilePolicy::maximumFileBytes());
        foreach ([$submission['submission_file'], $submission['submission_files.*'], $followup['attachment']] as $rules) {
            self::assertContains('max:'.($expectedBytes / 1024), $rules);
        }
    }

    public static function sizeLimits(): array
    {
        return [
            'provider is smaller' => [25600, 8 * 1024 * 1024, 8 * 1024 * 1024],
            'project is smaller' => [3072, 8 * 1024 * 1024, 3 * 1024 * 1024],
        ];
    }
}
