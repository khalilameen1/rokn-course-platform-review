<?php

namespace App\Services;

use App\Jobs\SendStudentNotification;
use App\Models\Course;
use App\Models\Lesson;
use App\Support\RoknAppLink;
use Illuminate\Support\Str;

class NotificationService
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

        return app(NotificationCampaignService::class)->queue(
            'new_course_lesson',
            [],
            Lesson::class,
            $lesson->id,
            $copy['title_ar'],
            $copy['title_en'],
            $copy['message_ar'],
            $copy['message_en'],
            $link,
            [],
            'lesson-published:' . $lesson->id,
            (int) $course->id,
            SendStudentNotification::AUDIENCE_ENROLLED,
            $copy['image_url'] ?? null,
            $copy['action_label_ar'] ?? null,
            $copy['action_label_en'] ?? null
        );
    }

    /**
     * Send a generic notification to students.
     *
     * @param string $type
     * @param array $userIds Explicit user IDs for a small broadcast; selectors belong in $data
     * @param array $data
     * @return bool
     */
    public static function notifyGeneric(string $type, array $userIds, array $data): bool
    {
        $titleAr = $data['title_ar'] ?? 'إشعار جديد';
        $titleEn = $data['title_en'] ?? 'New Notification';
        $messageAr = $data['message_ar'] ?? '';
        $messageEn = $data['message_en'] ?? '';
        $link = $data['link'] ?? null;
        $notifiableType = $data['notifiable_type'] ?? null;
        $notifiableId = $data['notifiable_id'] ?? null;
        $excludeUserIds = $data['exclude_user_ids'] ?? [];
        $deliveryKey = (string) ($data['delivery_key'] ?? Str::uuid());
        $courseId = isset($data['course_id']) ? (int) $data['course_id'] : null;
        $audience = (string) ($data['audience'] ?? SendStudentNotification::AUDIENCE_ALL);

        return app(NotificationCampaignService::class)->queue(
            $type,
            $userIds,
            $notifiableType,
            $notifiableId,
            $titleAr,
            $titleEn,
            $messageAr,
            $messageEn,
            $link,
            $excludeUserIds,
            $deliveryKey,
            $courseId,
            $audience,
            $data['image_url'] ?? null,
            $data['action_label_ar'] ?? null,
            $data['action_label_en'] ?? null,
            $data['scheduled_at'] ?? null,
            isset($data['authored_by']) ? (int) $data['authored_by'] : null
        );
    }

    /**
     * Send notification for course update.
     *
     * @param Course $course
     * @param string $updateType
     * @return bool
     */
    public static function notifyCourseUpdate(
        Course $course,
        string $updateType = 'general',
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

        return app(NotificationCampaignService::class)->queue(
            'course_update',
            [],
            Course::class,
            $course->id,
            $copy['title_ar'],
            $copy['title_en'],
            $copy['message_ar'],
            $copy['message_en'],
            $link,
            [],
            $deliveryKey ?: (string) Str::uuid(),
            (int) $course->id,
            SendStudentNotification::AUDIENCE_ENROLLED,
            $copy['image_url'] ?? null,
            $copy['action_label_ar'] ?? null,
            $copy['action_label_en'] ?? null
        );
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

        return app(NotificationCampaignService::class)->queue(
            'new_course',
            [],
            Course::class,
            (int) $course->id,
            $copy['title_ar'],
            $copy['title_en'],
            $copy['message_ar'],
            $copy['message_en'],
            RoknAppLink::course((int) $course->id),
            [],
            $deliveryKey,
            (int) $course->id,
            SendStudentNotification::AUDIENCE_NOT_ENROLLED,
            $copy['image_url'] ?? null,
            $copy['action_label_ar'] ?? null,
            $copy['action_label_en'] ?? null
        );
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

