<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;

final class CategoryEditorVersion
{
    public static function for(Category $category): string
    {
        return hash('sha256', json_encode([
            $category->name_ar, $category->name_en, $category->type,
            $category->description_ar, $category->description_en, $category->photo?->path,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
