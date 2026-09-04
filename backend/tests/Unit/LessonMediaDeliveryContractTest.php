<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LessonMediaDeliveryContractTest extends TestCase
{
    public function test_learner_playback_never_reconstructs_editorial_media_state(): void
    {
        $source = $this->source('app/Services/PlaybackManifestService.php');
        $method = $this->method($source, 'mediaGeneration');

        self::assertStringContainsString("->with(['courseSection.module', 'course', 'mediaState'])", $method);
        self::assertStringContainsString('has no published media state', $method);
        self::assertStringNotContainsString('createOrFirst', $method);
        self::assertStringNotContainsString('resetForGeneration', $method);
        self::assertStringNotContainsString('lockForUpdate', $method);
    }

    public function test_lessons_have_one_secure_stream_delivery_source(): void
    {
        $bunny = $this->source('app/Services/BunnyService.php');
        $manifest = $this->source('app/Services/PlaybackManifestService.php');

        self::assertStringNotContainsString('getFallbackVideo', $bunny);
        self::assertStringNotContainsString('BUNNY_FALLBACK_CDN_HOSTNAME', $this->source('config/bunny.php'));
        self::assertStringNotContainsString('vz-{$this->getLibraryId()}.b-cdn.net', $bunny);
        self::assertStringNotContainsString("'fallback_url'", $manifest);
        self::assertStringNotContainsString("'video_source_type' => \$lesson->video_source_type ?? 'youtube'", $bunny);
        self::assertStringNotContainsString("\$data['video_link'] = \$lesson->video_link", $bunny);
    }

    public function test_playback_preserves_purchase_and_project_lock_reasons(): void
    {
        $completion = $this->source('app/Services/CourseCompletionService.php');
        $manifest = $this->source('app/Services/PlaybackManifestService.php');
        $controller = $this->source('app/Http/Controllers/API/PlaybackController.php');

        self::assertStringContainsString('function sectionAccessState(', $completion);
        self::assertStringContainsString("'course_purchase_required'", $completion);
        self::assertStringContainsString('->sectionAccessState($user, $section)', $manifest);
        self::assertStringContainsString("'module_project_not_passed'", $controller);
        self::assertStringContainsString("'course_purchase_required'", $controller);
        self::assertStringContainsString("'previous_section_incomplete'", $controller);
    }

    public function test_manifest_allocation_serializes_with_atomic_course_publish(): void
    {
        $manifest = $this->source('app/Services/PlaybackManifestService.php');
        $issue = $this->method($manifest, 'issue');

        self::assertStringContainsString('Course::query()', $issue);
        self::assertStringContainsString('->lockForUpdate()', $issue);
        self::assertStringContainsString('$stillCurrent', $issue);
        self::assertStringContainsString('throw new CourseRevisionChangedException(', $issue);
    }

    public function test_fresh_players_never_share_an_implicit_playback_session(): void
    {
        $issue = $this->method(
            $this->source('app/Services/PlaybackManifestService.php'),
            'issue'
        );

        self::assertStringContainsString("\$clientContext['playback_session_id']", $issue);
        self::assertStringNotContainsString('now()->subSeconds(30)', $issue);
        self::assertStringNotContainsString('$recent', $issue);
    }

    public function test_lesson_completion_serializes_with_course_publish(): void
    {
        $completion = $this->method(
            $this->source('app/Services/CourseCompletionService.php'),
            'complete'
        );

        self::assertStringContainsString('Course::query()', $completion);
        self::assertStringContainsString('->lockForUpdate()', $completion);
        self::assertStringContainsString('completeWithinCourseLock', $completion);
    }

    public function test_learner_course_map_exposes_server_completion_beside_server_lock_state(): void
    {
        $resource = $this->source('app/Http/Resources/CourseResource.php');

        self::assertStringContainsString("'is_locked' => \$isLocked", $resource);
        self::assertStringContainsString("['is_completed'] ?? false", $resource);
    }

    public function test_course_section_order_is_the_only_learner_sequence_contract(): void
    {
        $base = $this->source('app/Http/Resources/BaseCourseResource.php');
        $course = $this->source('app/Http/Resources/CourseResource.php');
        $authoring = $this->source('app/Services/CourseSectionContentService.php');

        self::assertStringContainsString("'order' => \$section->order", $base);
        self::assertStringContainsString("'order' => \$section->order", $course);
        self::assertStringNotContainsString("\$content['priority']", $base);
        self::assertStringNotContainsString("\$content['priority']", $course);
        self::assertStringNotContainsString("'priority' =>", $authoring);
    }

    public function test_course_files_are_download_only_and_renewable(): void
    {
        $resource = $this->source('app/Http/Resources/CourseResource.php');
        $attachments = $this->source('app/Services/CourseAttachmentService.php');
        $pdf = $this->source('app/Http/Controllers/API/CoursePdfController.php');

        self::assertStringContainsString("'download_only' => true", $attachments);
        self::assertStringContainsString("'download_refresh_endpoint'", $attachments);
        self::assertStringContainsString('pdfPayload($user, $course, $pdf)', $pdf);
        self::assertStringNotContainsString("\$moduleData['attachments_link']", $resource);
    }

    public function test_missing_attachment_storage_never_falls_back_to_a_public_disk(): void
    {
        $pdf = $this->source('app/Models/CoursePdf.php');
        $attachments = $this->source('app/Services/CourseAttachmentService.php');

        self::assertStringNotContainsString(": 'local'", $pdf);
        self::assertStringContainsString('is_array(config("filesystems.disks.{$diskName}"))', $attachments);
        self::assertFileDoesNotExist(dirname(__DIR__, 2).'/app/Models/Attachment.php');
    }

    private function source(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
        self::assertIsString($source);

        return $source;
    }

    private function method(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method.'(');
        self::assertNotFalse($start);
        $tail = substr($source, $start);
        $next = strpos($tail, "\n    private function ", 20);

        return $next === false ? $tail : substr($tail, 0, $next);
    }
}
