<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class TeacherEditorVersion
{
    public static function for(User $teacher): string
    {
        return hash('sha256', json_encode([
            $teacher->name_ar, $teacher->name_en, $teacher->email, $teacher->phone,
            $teacher->job_title, $teacher->bio_ar, $teacher->bio_en, (bool) $teacher->active,
            $teacher->photo?->path,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
