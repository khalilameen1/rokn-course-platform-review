<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\NotificationAuthoringInput;
use PHPUnit\Framework\TestCase;

final class NotificationAuthoringInputTest extends TestCase
{
    public function test_snapshot_keeps_authored_fields_but_not_posted_author_identity_or_later_mutation(): void
    {
        $fields = [
            'authoring_request_id' => '9E59C6DE-DF2C-48D6-8124-3536EC258DD6',
            'title_ar' => ' عنوان ', 'message_ar' => ' رسالة ',
            'title_en' => 'Title', 'message_en' => 'Message',
            'user_id' => '17', 'course_id' => '42', 'audience' => 'enrolled',
            'notification_kind' => 'service', 'action_label' => 'ابدأ',
            'action_link' => '/wallet', 'send_at' => '2026-10-01T12:00',
            'authored_by' => 999, 'author_id' => 888,
        ];
        $input = NotificationAuthoringInput::fromValidated(5, $fields);
        $fields['title_ar'] = 'تغيير لاحق';
        $fields['user_id'] = 99;

        self::assertSame(5, $input->authorId);
        self::assertSame('9E59C6DE-DF2C-48D6-8124-3536EC258DD6', $input->requestId);
        self::assertSame(' عنوان ', $input->titleAr);
        self::assertSame(' رسالة ', $input->messageAr);
        self::assertSame('Title', $input->titleEn);
        self::assertSame('Message', $input->messageEn);
        self::assertSame(17, $input->targetStudentId);
        self::assertSame(42, $input->courseId);
        self::assertSame('enrolled', $input->audience);
        self::assertSame('service', $input->kind);
        self::assertSame('ابدأ', $input->actionLabel);
        self::assertSame('/wallet', $input->actionLink);
        self::assertSame('2026-10-01T12:00', $input->sendAt);
        self::assertNull($input->image);
    }

    public function test_missing_optional_fields_do_not_invent_a_target_schedule_or_image(): void
    {
        $input = NotificationAuthoringInput::fromValidated(2, [
            'authoring_request_id' => '9e59c6de-df2c-48d6-8124-3536ec258dd6',
            'title_ar' => 'عنوان', 'message_ar' => 'رسالة',
        ]);

        self::assertSame('all', $input->audience);
        self::assertNull($input->targetStudentId);
        self::assertNull($input->courseId);
        self::assertNull($input->titleEn);
        self::assertNull($input->messageEn);
        self::assertNull($input->kind);
        self::assertNull($input->actionLabel);
        self::assertNull($input->actionLink);
        self::assertNull($input->sendAt);
        self::assertNull($input->image);
    }
}
