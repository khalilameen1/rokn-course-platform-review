<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Services\ProjectSubmissionEffortGuard;
use App\Services\ProjectSubmissionInputService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProjectSubmissionInputTest extends TestCase
{
    public function test_normalization_preserves_arabic_and_file_order_and_removes_invisible_input(): void
    {
        $input = app(ProjectSubmissionInputService::class);
        $first = UploadedFile::fake()->createWithContent('first.txt', 'First project evidence');
        $second = UploadedFile::fake()->createWithContent('second.txt', 'Second project evidence');

        self::assertSame(
            ["هذه محاولتي\nوالنتيجة", [$first, $second]],
            $input->normalize("  \u{200F}هذه محاولتي\r\nوالنتيجة  ", [3 => $first, null, 'not a file', $second])
        );
        self::assertSame([null, []], $input->normalize(" \u{200B}\r\n", null));
    }

    public function test_replay_fingerprint_tracks_content_not_temporary_path_or_original_filename(): void
    {
        $input = app(ProjectSubmissionInputService::class);
        $original = UploadedFile::fake()->createWithContent('work.txt', 'Original project evidence');
        $retry = UploadedFile::fake()->createWithContent('renamed.txt', 'Original project evidence');
        $changed = UploadedFile::fake()->createWithContent('work.txt', 'Different project evidence');

        $fingerprint = $input->fingerprint('My explanation', [$original]);
        self::assertSame($fingerprint, $input->fingerprint(' My explanation ', [$retry]));
        self::assertNotSame($fingerprint, $input->fingerprint('Changed explanation', [$retry]));
        self::assertNotSame($fingerprint, $input->fingerprint('My explanation', [$changed]));
        self::assertNotSame(
            $input->fingerprint(null, [$original, $changed]),
            $input->fingerprint(null, [$changed, $original])
        );
    }

    public function test_course_file_types_override_the_global_defaults(): void
    {
        config()->set('projects.allowed_mime_types', ['text/plain']);
        $input = app(ProjectSubmissionInputService::class);
        $file = UploadedFile::fake()->createWithContent('work.txt', 'A readable project explanation');
        $project = new Project();

        $input->assertAllowedFileTypes($project, [$file]);
        $project->submission_allowed_mime_types = ['TEXT/PLAIN'];
        $input->assertAllowedFileTypes($project, [$file]);
        $project->submission_allowed_mime_types = ['image/png'];

        $this->expectException(ValidationException::class);
        $input->assertAllowedFileTypes($project, [$file]);
    }

    public function test_empty_project_file_whitelist_does_not_fall_back_to_global_defaults(): void
    {
        config()->set('projects.allowed_mime_types', ['text/plain']);
        $project = new Project(['submission_allowed_mime_types' => []]);
        $file = UploadedFile::fake()->createWithContent('work.txt', 'A readable project explanation');

        $this->expectException(ValidationException::class);
        app(ProjectSubmissionInputService::class)->assertAllowedFileTypes($project, [$file]);
    }

    public function test_course_file_types_cannot_reenable_a_globally_disabled_format(): void
    {
        config()->set('projects.allowed_mime_types', ['text/plain']);
        $project = new Project(['submission_allowed_mime_types' => ['image/png']]);
        $file = UploadedFile::fake()->image('work.png');

        $this->expectException(ValidationException::class);
        app(ProjectSubmissionInputService::class)->assertAllowedFileTypes($project, [$file]);
    }

    public function test_review_budget_counts_requirements_and_text_at_the_exact_limit(): void
    {
        config()->set('projects.evaluation_max_input_characters', 1000);
        $input = app(ProjectSubmissionInputService::class);
        $project = new Project(['requirements_text' => str_repeat('ر', 600)]);
        $input->assertReviewCapacity($project, str_repeat('ن', 400), []);

        try {
            $input->assertReviewCapacity($project, str_repeat('ن', 401), []);
            self::fail('Text beyond the combined review budget was accepted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('submission_text', $exception->errors());
        }
    }

    public function test_review_budget_includes_text_extracted_from_attachments(): void
    {
        config()->set('projects.evaluation_max_input_characters', 1000);
        $project = new Project(['requirements_text' => str_repeat('ر', 700)]);
        $file = UploadedFile::fake()->createWithContent('work.txt', str_repeat('Evidence ', 40));

        try {
            app(ProjectSubmissionInputService::class)->assertReviewCapacity($project, null, [$file]);
            self::fail('Attachment content bypassed the combined review budget.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('submission_files', $exception->errors());
        }
    }

    public function test_file_admission_uses_the_lower_of_project_and_provider_limits(): void
    {
        config()->set('projects.maximum_file_kilobytes', 8);
        config()->set('openrouter.attachment_provider_max_bytes', 1024);
        $file = UploadedFile::fake()->createWithContent('work.txt', str_repeat('x', 1025));

        $this->expectException(ValidationException::class);
        app(ProjectSubmissionInputService::class)->assertReviewCapacity(new Project(), null, [$file]);
    }

    public static function effortCases(): array
    {
        return [
            'empty' => [null, false],
            'too short' => ['محاولة', false],
            'repeated character' => [str_repeat('س', 20), false],
            'repeated word' => ['تجربة تجربة تجربة', false],
            'keyboard sequence' => ['asdfasdfasdf', false],
            'real explanation' => ['نفذت التصميم وعدلت المسافات بين العناصر', true],
        ];
    }

    #[DataProvider('effortCases')]
    public function test_effort_guard_has_a_direct_behavior_contract(?string $text, bool $valid): void
    {
        config()->set('projects.minimum_text_length', 10);
        self::assertSame(
            $valid ? ProjectSubmission::EFFORT_VALID : ProjectSubmission::EFFORT_INVALID,
            app(ProjectSubmissionEffortGuard::class)->assess($text, [])
        );
    }

    public function test_a_small_attachment_does_not_disqualify_a_meaningful_explanation(): void
    {
        config()->set('projects.minimum_file_bytes', 512);
        $file = UploadedFile::fake()->createWithContent('work.txt', 'tiny');
        $guard = app(ProjectSubmissionEffortGuard::class);

        self::assertSame(ProjectSubmission::EFFORT_INVALID, $guard->assess(null, [$file]));
        self::assertSame(
            ProjectSubmission::EFFORT_VALID,
            $guard->assess('نفذت المشروع وهذه هي الخطوات التي اتبعتها', [$file])
        );
    }
}
