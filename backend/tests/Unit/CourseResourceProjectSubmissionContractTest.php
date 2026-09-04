<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CourseResourceProjectSubmissionContractTest extends TestCase
{
    public function test_course_map_exposes_the_canonical_submission_summary_without_transcript_loading(): void
    {
        $resource = file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Resources/CourseResource.php'
        );
        $presenter = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/ProjectSubmissionPresenter.php'
        );

        self::assertIsString($resource);
        self::assertIsString($presenter);
        self::assertStringContainsString('ProjectSubmissionPresenter::class', $resource);
        self::assertStringNotContainsString("\$content['submission_status']", $resource);
        foreach ([
            "'submission_status'",
            "'can_submit'",
            "'can_continue'",
            "'feedback_level'",
            "'report_enabled'",
            "'report_status'",
            "'reply_enabled'",
            "'can_reply'",
            "'feedback'",
            "'poll_after_seconds'",
        ] as $field) {
            self::assertStringContainsString($field, $presenter);
        }
        foreach (["'passed' =>", "'can_resubmit' =>", "'needs_resubmission' =>", "'provisional' =>", "'authoritative' =>", "'is_reviewing' =>"] as $alias) {
            self::assertStringNotContainsString($alias, $presenter);
        }
        self::assertStringContainsString("['aiInputAttachments', 'feedbackThread.enrollment']", $presenter);
        self::assertStringNotContainsString("->with(['aiInputAttachments', 'feedbackThread.messages'])", $presenter);
    }
}
