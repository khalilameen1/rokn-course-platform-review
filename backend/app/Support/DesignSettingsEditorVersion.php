<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\DesignSetting;

final class DesignSettingsEditorVersion
{
    public static function for(DesignSetting $settings): string
    {
        // Content identity detects changes even within the same second.
        $attributes = $settings->getAttributes();
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }
}
