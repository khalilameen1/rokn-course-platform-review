<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CourseCode;

final class CourseCodeEditorVersion
{
    public static function for(CourseCode $code): string
    {
        return AdminEditorVersion::for($code, [
            'code', 'name', 'type', 'course_id', 'lesson_id', 'lesson_ids',
            'start_date', 'expiry_date', 'max_uses', 'used_count', 'is_active',
            'is_grant', 'description', 'allowed_email_domains',
        ]);
    }
}
