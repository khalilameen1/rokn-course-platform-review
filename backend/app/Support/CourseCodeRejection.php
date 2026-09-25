<?php

declare(strict_types=1);

namespace App\Support;

/** One vocabulary for code preview and redemption, not arbitrary database errors. */
enum CourseCodeRejection: string
{
    case NOT_FOUND = 'not_found';
    case COURSE_MISMATCH = 'course_mismatch';
    case LEGACY_RETIRED = 'legacy_retired';
    case COURSE_UNAVAILABLE = 'course_unavailable';
    case GRANT_ALREADY_CLAIMED = 'grant_already_claimed';
    case EMAIL_NOT_ELIGIBLE = 'email_not_eligible';
    case EXPIRED = 'expired';
    case NOT_STARTED = 'not_started';
    case DISABLED = 'disabled';
    case EXHAUSTED = 'exhausted';
    case ALREADY_USED = 'already_used';

    public function message(): string
    {
        return match ($this) {
            self::NOT_FOUND => 'الكود غير صحيح',
            self::COURSE_MISMATCH => 'هذا الكود مخصص لكورس آخر ولم يتم استخدامه',
            self::LEGACY_RETIRED => 'هذا النوع القديم من الأكواد لم يعد متاحًا',
            self::COURSE_UNAVAILABLE => 'هذا الكورس غير متاح للفتح الآن',
            self::GRANT_ALREADY_CLAIMED => 'استخدمت منحتك التعليمية في كورس آخر بالفعل',
            self::EMAIL_NOT_ELIGIBLE => 'هذا الكود متاح لبريد الجهة التعليمية المحددة فقط',
            self::EXPIRED => 'الكود منتهي الصلاحية',
            self::NOT_STARTED => 'الكود لم يبدأ بعد',
            self::DISABLED => 'الكود معطل',
            self::EXHAUSTED => 'تم استنفاذ جميع مرات الاستخدام للكود',
            self::ALREADY_USED => 'لقد استخدمت هذا الكود من قبل',
        };
    }
}
