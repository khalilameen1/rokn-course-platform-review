<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppVersion;

final class AppVersionEditorVersion
{
    public static function for(AppVersion $version): string
    {
        return AdminEditorVersion::for($version, [
            'platform', 'distribution_channel', 'version_name', 'version_code',
            'build_number', 'is_force_update', 'is_active', 'update_message_ar',
            'update_message_en', 'download_url', 'release_notes_ar', 'release_notes_en',
        ]);
    }

}
