<?php

namespace App\Services;

use App\Support\NotificationAudience;
use App\Support\NotificationCampaignIntent;
use App\Models\Course;
use App\Models\Lesson;
use App\Support\RoknAppLink;
use Illuminate\Support\Str;

final class CourseContentNotificationService
{
    /**
     * Send notification for a new course lesson.
     *
     * @param Lesson $lesson
     * @param Course $course
     * @return bool
     */
    public static function notifyNewCourseLesson(Lesson $lesson, Course $course): bool
    {
        if (!self::courseCanReceiveContentNotifications($course)) {
            return false;
        }

        $courseNameAr = (string) ($course->name_ar ?? $course->title ?? 'الكورس');
        $courseNameEn = (string) ($course->name_en ?? $course->title ?? 'Course');
        $copy = self::templatePayload('new_course_lesson', [
            'course' => $courseNameAr,
            'lesson' => (string) $lesson->title,
        ], [
            'title_ar' => 'مقطع جديد',
            'title_en' => 'New lesson available',
            'message_ar' => $lesson->title . "\n" . $courseNameAr,
            'message_en' => $lesson->title . "\n" . $courseNameEn,
            'action_label_ar' => 'شاهد الآن',
            'action_label_en' => 'Watch now',
        ]);
        if ($copy === null) {
            return false;
        }

        $link = RoknAppLink::course((int) $course->id);

        return app(NotificationCampaignService::class)->queue(new NotificationCampaignIntent(
            notificationType: 'new_course_lesson',
            deliveryKey: 'lesson-published:' . $lesson->id,
            audience: new NotificationAudience(
                selector: NotificationAudience::ENROLLED,
                courseId: (int) $course->id
            ),
            titleAr: $copy['title_ar'],
            titleEn: $copy['title_en'],
            messageAr: $copy['message_ar'],
            messageEn: $copy['message_en'],
            notifiableType: Lesson::class,
            notifiableId: (int) $lesson->id,
            link: $link,
            imageUrl: $copy['image_url'] ?? null,
            actionLabelAr: $copy['action_label_ar'] ?? null,
            actionLabelEn: $copy['action_label_en'] ?? null
        ));
    }

    /**
     * Send notification for course update.
     *
     * @param Course $course
     * @return bool
     */
    public static function notifyCourseUpdate(
        Course $course,
        ?string $deliveryKey = null
    ): bool
    {
        if (!self::courseCanReceiveContentNotifications($course)) {
            return false;
        }

        $courseNameAr = (string) ($course->name_ar ?? $course->title ?? 'الكورس');
        $courseNameEn = (string) ($course->name_en ?? $course->title ?? 'Course');
        $copy = self::templatePayload('course_update', [
            'course' => $courseNameAr,
        ], [
            'title_ar' => 'جديد في كورسك',
            'title_en' => 'Course update',
            'message_ar' => $courseNameAr,
            'message_en' => $courseNameEn,
            'action_label_ar' => 'افتح الكورس',
            'action_label_en' => 'View course',
        ]);
        if ($copy === null) {
            return false;
        }

        $link = RoknAppLink::course((int) $course->id);

        return app(NotificationCampaignService::class)->queue(new NotificationCampaignIntent(
            notificationType: 'course_update',
            deliveryKey: $deliveryKey ?: (string) Str::uuid(),
            audience: new NotificationAudience(
                selector: NotificationAudience::ENROLLED,
                courseId: (int) $course->id
            ),
            titleAr: $copy['title_ar'],
            titleEn: $copy['title_en'],
            messageAr: $copy['message_ar'],
            messageEn: $copy['message_en'],
            notifiableType: Course::class,
            notifiableId: (int) $course->id,
            link: $link,
            imageUrl: $copy['image_url'] ?? null,
            actionLabelAr: $copy['action_label_ar'] ?? null,
            actionLabelEn: $copy['action_label_en'] ?? null
        ));
    }

    public static function notifyNewCourse(Course $course, string $deliveryKey): bool
    {
        if (!self::courseCanReceiveContentNotifications($course)
            || !$course->is_catalog_visible) {
            return false;
        }
        $courseNameAr = (string) ($course->name_ar ?: $course->name_en ?: 'كورس ركن');
        $courseNameEn = (string) ($course->name_en ?: $course->name_ar ?: 'Rokn course');
        $copy = self::templatePayload('new_course', ['course' => $courseNameAr], [
            'title_ar' => 'كورس جديد',
            'title_en' => 'New course',
            'message_ar' => $courseNameAr,
            'message_en' => $courseNameEn,
            'action_label_ar' => 'افتح الكورس',
            'action_label_en' => 'View course',
        ]);
        if ($copy === null) return false;

        return app(NotificationCampaignService::class)->queue(new NotificationCampaignIntent(
            notificationType: 'new_course',
            deliveryKey: $deliveryKey,
            audience: new NotificationAudience(
                selector: NotificationAudience::NOT_ENROLLED,
                courseId: (int) $course->id
            ),
            titleAr: $copy['title_ar'],
            titleEn: $copy['title_en'],
            messageAr: $copy['message_ar'],
            messageEn: $copy['message_en'],
            notifiableType: Course::class,
            notifiableId: (int) $course->id,
            link: RoknAppLink::course((int) $course->id),
            imageUrl: $copy['image_url'] ?? null,
            actionLabelAr: $copy['action_label_ar'] ?? null,
            actionLabelEn: $copy['action_label_en'] ?? null
        ));
    }

    /** @param array<string, mixed> $variables @param array<string, mixed> $fallback */
    private static function templatePayload(string $key, array $variables, array $fallback): ?array
    {
        return app(EngagementMessageService::class)->notificationPayload($key, $variables, $fallback);
    }

    private static function courseCanReceiveContentNotifications(Course $course): bool
    {
        if ($course->is_coming_soon) {
            return false;
        }

        $current = $course->fresh();
        if (!$current) {
            return false;
        }

        return (bool) data_get(app(CoursePublishingService::class)->audit($current), 'ready');
    }
}

