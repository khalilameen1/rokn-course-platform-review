<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CourseCheckoutRejected;
use App\Support\UnicodeText;

use App\Models\Coupon;
use App\Models\CouponRedemption;

final class CourseCouponService
{
    /**
     * @return array{coupon:?Coupon,code:?string,percentage:int,discount:int,final:int}
     */
    public function quote(
        int $userId,
        int $courseId,
        int $coursePrice,
        int $minimumPaidCoins,
        ?string $couponCode,
        bool $lockForUpdate = false
    ): array {
        $price = max(0, $coursePrice);
        $normalized = $this->normalize($couponCode);
        if ($normalized === null) {
            return [
                'coupon' => null,
                'code' => null,
                'percentage' => 0,
                'discount' => 0,
                'final' => $price,
            ];
        }

        // Same contract as course codes: the model stores the normalized
        // identifier under a unique index, so this is one indexed row lookup
        // (and, when locking, one locked row instead of a scanned range).
        $query = Coupon::query()->where('code', $normalized);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $coupon = $query->first();
        if (!$coupon || !$coupon->isAvailableAt(now())) {
            throw new CourseCheckoutRejected(CourseCheckoutRejected::COUPON_INVALID);
        }
        if ($coupon->course_id !== null && (int) $coupon->course_id !== $courseId) {
            throw new CourseCheckoutRejected(CourseCheckoutRejected::COUPON_NOT_APPLICABLE);
        }

        $alreadyUsed = CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->where('user_id', $userId)
            ->exists();
        if ($alreadyUsed) {
            throw new CourseCheckoutRejected(CourseCheckoutRejected::COUPON_ALREADY_USED);
        }
        if (
            $coupon->max_redemptions !== null
            && CouponRedemption::query()->where('coupon_id', $coupon->id)->count()
                >= (int) $coupon->max_redemptions
        ) {
            throw new CourseCheckoutRejected(CourseCheckoutRejected::COUPON_QUOTA_REACHED);
        }

        $percentage = max(1, min(100, (int) $coupon->balance));
        $calculatedDiscount = intdiv($price * $percentage, 100);
        // A coupon can reduce content margin, never the paid floor that funds
        // the variable-cost capabilities of the selected plan.
        $promotion = app(CoursePromotionPolicy::class)->allowance($userId, $courseId, $price);
        $maximumDiscount = min($promotion['remaining'], max(0, $price - min($price, max(0, $minimumPaidCoins))));
        $discount = min($calculatedDiscount, $maximumDiscount);
        if ($discount <= 0) {
            throw new CourseCheckoutRejected(CourseCheckoutRejected::COUPON_NOT_APPLICABLE);
        }

        return [
            'coupon' => $coupon,
            'code' => $normalized,
            'percentage' => $percentage,
            'discount' => $discount,
            'final' => $price - $discount,
        ];
    }

    public function normalize(?string $code): ?string
    {
        $normalized = UnicodeText::identifier($code);

        return $normalized !== '' ? $normalized : null;
    }
}
