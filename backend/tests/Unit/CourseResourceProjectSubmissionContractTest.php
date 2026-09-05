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

    public function test_an_existing_report_uses_the_current_reply_capability_after_a_plan_upgrade(): void
    {
        $presenter = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/ProjectSubmissionPresenter.php'
        );

        self::assertIsString($presenter);
        self::assertStringContainsString(
            "\$replyAvailable = (bool) (\$threadPayload['can_reply'] ?? false)",
            $presenter
        );
        self::assertStringContainsString(
            "\$effectiveFeedbackLevel = \$replyAvailable",
            $presenter
        );
        self::assertStringContainsString(
            "'reply_enabled' => \$effectiveReplyEnabled",
            $presenter
        );
        self::assertStringContainsString(
            "\$replyContract = \$this->feedbackThreads->activeReplyContract(\$thread)",
            $presenter
        );
    }
}
