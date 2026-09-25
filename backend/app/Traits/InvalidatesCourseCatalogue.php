<?php

declare(strict_types=1);

namespace App\Traits;

use App\Services\CourseCatalogueRevisionService;

trait InvalidatesCourseCatalogue
{
    public static function bootInvalidatesCourseCatalogue(): void
    {
        $touchCatalogue = static function ($model): void {
            if (
                method_exists($model, 'shouldInvalidateCourseCatalogue')
                && !$model->shouldInvalidateCourseCatalogue()
            ) {
                return;
            }

            app(CourseCatalogueRevisionService::class)->invalidateAfterCommit();
        };

        static::saved($touchCatalogue);
        static::deleted($touchCatalogue);

        if (in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', class_uses_recursive(static::class), true)) {
            static::restored($touchCatalogue);
        }
    }
}
