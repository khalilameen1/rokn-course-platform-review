<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\OperatingCostPool;
use App\Models\Setting;

final class OperatingCostEditorVersion
{
    public static function for(OperatingCostPool $pool): string
    {
        return AdminEditorVersion::for($pool, [
            'name', 'service_key', 'course_id', 'period_start', 'period_end',
            'amount', 'currency', 'fx_rate_to_egp', 'allocation_driver',
            'is_final', 'notes',
        ]);
    }

    public static function exchangeRate(Setting $settings): string
    {
        return AdminEditorVersion::for($settings, ['openrouter_usd_to_egp_rate']);
    }
}
