<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\StudentNotification;
use App\Support\RoknAppLink;

final class StudentNotificationPresentationService
{
    public function learnerArabicText(mixed $value, string $fallback): string
    {
        $text = $this->learnerText($value, $fallback);
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text) !== 1) {
            return $fallback;
        }

        $text = preg_replace('/\r\n?/', "\n", $text) ?? $fallback;
        $text = preg_replace('/[,،;؛:]+/u', "\n", $text) ?? $fallback;
        $text = preg_replace('/([^\d])\.(?=\s|$)/u', '${1}' . "\n", $text) ?? $fallback;
        $lines = array_values(array_filter(array_map(
            static fn (string $line): string => trim((string) preg_replace('/\s+/u', ' ', $line)),
            explode("\n", $text)
        )));

        return mb_substr(implode("\n", array_slice($lines, 0, 3)), 0, 240) ?: $fallback;
    }

    public function learnerText(mixed $value, string $fallback): string
    {
        $text = trim(strip_tags((string) $value));
        $diagnostic = preg_match(
            '/(?:https?:\/\/|www\.|sqlstate|exception|stack\s*trace|openrouter|bunny(?:cdn)?|kashier|firebase|oauth|pkce|google\s*play|app\s*store|storekit|billingclient|authorization(?:signature|expire)?|access[_ -]?key|api[_ -]?key)/iu',
            $text
        ) === 1 || preg_match('/\b[A-Z][A-Z0-9]*_[A-Z0-9_]+\b/u', $text) === 1;

        return $text === '' || $diagnostic ? $fallback : mb_substr($text, 0, 240);
    }

    /** @return array{notification_type:string,course_id:?int,image_url:?string,action_label_ar:string,action_label_en:string,link:string} */
    public function for(StudentNotification $notification): array
    {
        $course = $this->courseFor($notification);
        $courseBound = in_array($notification->notifiable_type, [Course::class, Lesson::class], true);
        $courseUnavailable = $courseBound
            && (
                !$course
                || !$course->isPublishedForLearning()
            );
        if ($courseUnavailable) {
            $course = null;
        }
        $courseId = $course ? (int) $course->id : null;
        $type = (string) $notification->notification_type;
        [$fallbackActionAr, $fallbackActionEn] = $courseUnavailable
            ? ['افتح ركن', 'Open Rokn']
            : $this->actions($type);
        $actionAr = $courseUnavailable
            ? $fallbackActionAr
            : (trim((string) $notification->action_label_ar) ?: $fallbackActionAr);
        $actionEn = $courseUnavailable
            ? $fallbackActionEn
            : (trim((string) $notification->action_label_en) ?: $fallbackActionEn);
        $explicitImage = $this->safeImageUrl($notification->image_url);
        $explicitLink = !$courseUnavailable && trim((string) $notification->link) !== ''
            ? $notification->link
            : null;
        if ($courseUnavailable) {
            $explicitLink = null;
        }

        return [
            'notification_type' => $type,
            'course_id' => $courseId,
            'image_url' => $explicitImage ?: $this->safeImageUrl($course?->image),
            'action_label_ar' => $actionAr,
            'action_label_en' => $actionEn,
            'link' => $this->safeLink($explicitLink, $type, $courseId),
        ];
    }

    private function courseFor(StudentNotification $notification): ?Course
    {
        if ($notification->relationLoaded('notifiable')) {
            $notifiable = $notification->notifiable;
            if ($notifiable instanceof Course) {
                return $notifiable;
            }
            if ($notifiable instanceof Lesson) {
                return $notifiable->relationLoaded('course')
                    ? $notifiable->course
                    : $notifiable->course()->first();
            }

            // Eager loading already proved that the referenced model is gone
            // (or is not course-bound). Do not issue the same lookup again for
            // every historical inbox row.
            return null;
        }
        if ($notification->notifiable_type === Course::class && $notification->notifiable_id) {
            return Course::query()->find((int) $notification->notifiable_id);
        }
        if ($notification->notifiable_type === Lesson::class && $notification->notifiable_id) {
            return Lesson::query()
                ->with('course')
                ->find((int) $notification->notifiable_id)?->course;
        }

        return null;
    }

    /** @return array{string,string} */
    private function actions(string $type): array
    {
        return match ($type) {
            'coins_claimed', 'package_purchased', 'coin_reward', 'whatsapp_connected' => ['افتح المحفظة', 'View balance'],
            'coin_offer' => ['افتح العرض', 'View offer'],
            'learning_nudge', 'continue_course', 'course_enrolled', 'institutional_grant' => ['أكمل من مكانك', 'Continue learning'],
            'course_promotion' => ['تفاصيل الكورس', 'View course'],
            'new_course' => ['افتح الكورس', 'View new course'],
            'new_course_lesson' => ['شاهد الآن', 'Watch now'],
            'course_update' => ['افتح الكورس', 'View course'],
            'project_update' => ['افتح النتيجة', 'View result'],
            'certificate_ready', 'course_completed' => ['افتح الشهادة', 'View certificate'],
            'support_case_update' => ['افتح البلاغ', 'View case'],
            default => ['افتح ركن', 'Open Rokn'],
        };
    }

    private function safeLink(?string $link, string $type, ?int $courseId): string
    {
        $fallback = match (true) {
            in_array($type, ['certificate_ready', 'course_completed'], true) => 'rokn://profile/certificates',
            $type === 'project_update' => 'rokn://profile',
            default => 'rokn://home',
        };

        if (in_array($type, ['coins_claimed', 'package_purchased', 'coin_reward', 'coin_offer', 'whatsapp_connected'], true)) {
            return 'rokn://wallet';
        }
        if ($courseId !== null && $courseId > 0) {
            if (in_array($type, ['learning_nudge', 'continue_course', 'course_enrolled', 'institutional_grant'], true)) {
                return RoknAppLink::course($courseId, true) ?? $fallback;
            }
            if (in_array($type, ['course_promotion', 'new_course', 'new_course_lesson', 'course_update'], true)) {
                return RoknAppLink::course($courseId) ?? $fallback;
            }
        }

        $link = trim((string) $link);
        if ($link === '') {
            return $fallback;
        }

        return RoknAppLink::normalize($link) ?? $fallback;
    }

    private function safeImageUrl(mixed $value): ?string
    {
        $image = trim((string) $value);
        if ($image === '') {
            return null;
        }
        if (filter_var($image, FILTER_VALIDATE_URL)) {
            return str_starts_with(strtolower($image), 'https://') ? $image : null;
        }

        $base = rtrim((string) config('app.url'), '/');
        return str_starts_with(strtolower($base), 'https://')
            ? $base . '/' . ltrim($image, '/')
            : null;
    }
}
