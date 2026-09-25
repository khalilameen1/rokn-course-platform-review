<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Coupon;

final class CouponEditorVersion
{
    public static function for(Coupon $coupon): string
    {
        $coupon->loadMissing('photo');

        return hash('sha256', json_encode([
            AdminEditorVersion::for($coupon, [
                'name_ar', 'name_en', 'code', 'course_id', 'starts_at', 'balance',
                'max_redemptions', 'expiry_date', 'active',
            ]),
            (string) ($coupon->photo?->path ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
