<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class StudentEditorVersion
{
    public static function for(User $user): string
    {
        return AdminEditorVersion::for($user, [
            'name', 'email', 'phone', 'profile_revision', 'email_verified_at',
        ]);
    }

    public static function device(User $user): string
    {
        return AdminEditorVersion::for($user, ['locked_device_id', 'profile_revision', 'deleted_at']);
    }
}
