<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Package;

final class PackageEditorVersion
{
    public static function for(Package $package): string
    {
        return AdminEditorVersion::for($package, [
            'name_ar', 'name_en', 'price', 'coins', 'is_active', 'direct_enabled',
            'sort_order', 'google_product_id', 'apple_product_id', 'google_enabled', 'apple_enabled',
        ]);
    }
}
