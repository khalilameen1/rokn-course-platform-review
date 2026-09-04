<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Course;
use App\Models\NotificationCampaign;
use App\Models\StudentNotification;
use App\Models\User;
use App\Services\NotificationDeliveryPolicy;
use App\Services\StudentNotificationPresentationService;
use Tests\TestCase;

final class StudentNotificationPresentationServiceTest extends TestCase
{
    public function test_learner_copy_filters_diagnostics_for_inbox_and_push(): void
    {
        $service = app(StudentNotificationPresentationService::class);

        self::assertSame(
            'إشعار من ركن',
            $service->learnerArabicText('SQLSTATE API_KEY فشل', 'إشعار من ركن')
        );
        self::assertSame(
            'Rokn notification',
            $service->learnerText('OAuth exception from Firebase', 'Rokn notification')
        );
        self::assertSame(
            "وصل تقريرك\nافتحه الآن",
            $service->learnerArabicText('وصل تقريرك، افتحه الآن', 'إشعار من ركن')
        );
        self::assertSame(
            'Your certificate is ready',
            $service->learnerText('Your certificate is ready', 'Rokn notification')
        );
    }

    public function test_invalid_legacy_link_still_returns_a_complete_mobile_action(): void
    {
        $presentation = app(StudentNotificationPresentationService::class)->for(
            new StudentNotification([
                'notification_type' => 'service_notice',
                'link' => 'javascript:alert(1)',
            ])
        );

        self::assertSame('rokn://home', $presentation['link']);
        self::assertSame('افتح ركن', $presentation['action_label_ar']);
        self::assertSame('Open Rokn', $presentation['action_label_en']);
    }

    public function test_course_fallback_image_is_part_of_the_delivery_presentation(): void
    {
        $course = new Course();
        $course->forceFill([
            'id' => 18,
            'image' => 'https://cdn.example/course-18.webp',
            'is_coming_soon' => false,
        ]);
        $course->setRawAttributes(array_merge($course->getAttributes(), [
            'sections_count' => 1,
        ]));
        $course->setRelation('photo', null);
        $notification = new StudentNotification([
            'notification_type' => 'course_update',
            'notifiable_type' => Course::class,
            'notifiable_id' => 18,
        ]);
        $notification->setRelation('notifiable', $course);

        $presentation = app(StudentNotificationPresentationService::class)->for($notification);

        self::assertSame('https://cdn.example/course-18.webp', $presentation['image_url']);
        self::assertSame('rokn://course/18', $presentation['link']);
    }

    public function test_withdrawn_course_does_not_keep_a_broken_course_cta(): void
    {
        $course = new Course([
            'id' => 19,
            'is_coming_soon' => true,
        ]);
        $notification = new StudentNotification([
            'notification_type' => 'course_update',
            'notifiable_type' => Course::class,
            'notifiable_id' => 19,
            'link' => 'rokn://course/19',
            'action_label_ar' => 'افتح الكورس',
            'action_label_en' => 'View course',
        ]);
        $notification->setRelation('notifiable', $course);

        $presentation = app(StudentNotificationPresentationService::class)->for($notification);

        self::assertSame('rokn://home', $presentation['link']);
        self::assertSame('افتح ركن', $presentation['action_label_ar']);
        self::assertSame('Open Rokn', $presentation['action_label_en']);
    }

    public function test_student_role_and_hidden_course_targeting_have_one_normalized_contract(): void
    {
        $user = new User();
        $user->forceFill([
            'role' => ' Client ',
            'active' => true,
            'marketing_notifications_enabled' => true,
        ]);
        $campaign = new NotificationCampaign([
            'audience' => 'all',
            'user_ids' => [31],
        ]);

        self::assertTrue(NotificationDeliveryPolicy::allowsInbox($user, 'service_notice'));
        self::assertTrue($campaign->canDeliverHiddenCourse());
        self::assertFalse((new NotificationCampaign([
            'audience' => 'not_enrolled',
            'user_ids' => [],
        ]))->canDeliverHiddenCourse());
    }
}
