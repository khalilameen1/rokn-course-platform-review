<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DesignSetting;
use App\Models\Setting;
use Illuminate\Support\Arr;

final class AppSettingsEditorVersion
{
    public static function for(
        Setting $settings,
        DesignSetting $design
    ): string {
        $secretRevision = hash('sha256', json_encode([
            (string) $settings->getRawOriginal('bunny_api_key_secret'),
            (string) $settings->getRawOriginal('bunny_storage_password_secret'),
            (string) $settings->getRawOriginal('bunny_security_key_secret'),
        ], JSON_UNESCAPED_SLASHES));
        $settingValues = Arr::except($settings->getAttributes(), [
            'bunny_api_key_secret',
            'bunny_storage_password_secret',
            'bunny_security_key_secret',
            'bunny_api_key',
            'bunny_storage_password',
            'created_at',
            'updated_at',
        ]);
        $settingValues['bunny_secrets_revision'] = $secretRevision;
        $designValues = Arr::except($design->getAttributes(), [
            'created_at',
            'updated_at',
        ]);
        ksort($settingValues);
        ksort($designValues);

        return hash('sha256', json_encode(
            [$settingValues, $designValues],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }
}
