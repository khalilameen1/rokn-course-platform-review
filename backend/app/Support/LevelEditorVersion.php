<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Level;

final class LevelEditorVersion
{
    public static function for(Level $level): string
    {
        $level->loadMissing('photo');

        return hash('sha256', json_encode([
            $level->name_ar, $level->name_en,
            $level->description_ar, $level->description_en,
            $level->order, $level->badge_image, $level->photo?->path,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
