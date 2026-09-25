<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class ModeratorEditorVersion
{
    public static function for(User $moderator): string
    {
        return AdminEditorVersion::for($moderator, [
            'name_ar', 'name_en', 'email', 'phone', 'password', 'active',
            'profile_revision', 'email_verified_at',
        ]);
    }
}
