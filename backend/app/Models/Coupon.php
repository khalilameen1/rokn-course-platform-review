<?php

namespace App\Models;

use App\Support\BusinessClock;
use App\Support\UnicodeText;
use App\Traits\HasPhoto;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use HasPhoto, SoftDeletes;

    protected $fillable = [
        'name_ar', 'name_en', 'code', 'course_id', 'starts_at', 'balance',
        'max_redemptions', 'expiry_date', 'active',
        'authoring_request_id',
    ];

    protected $casts = [
        'balance' => 'integer',
        'max_redemptions' => 'integer',
        'starts_at' => 'datetime',
        'expiry_date' => 'datetime',
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (Coupon $coupon): void {
            $used = $coupon->redemptions()->count();
            if ($used > 0 && $coupon->isDirty(['code', 'course_id', 'balance', 'starts_at'])) {
                throw new \DomainException(
                    'بدأ استخدام هذه الحملة. أوقفها وأنشئ كودًا جديدًا لتغيير نطاق الخصم أو نسبته.'
                );
            }
            if (
                $coupon->max_redemptions !== null
                && (int) $coupon->max_redemptions < $used
            ) {
                throw new \DomainException('حد الاستخدام لا يمكن أن يقل عن الاستخدام الفعلي.');
            }
        });
    }

    public function setCodeAttribute($value): void
    {
        // Checkout matches this exact value through the unique `code` index.
        $normalized = UnicodeText::identifier($value);
        $this->attributes['code'] = $normalized !== '' ? $normalized : null;
    }

    public function customers()
    {
        return $this->belongsToMany(User::class, 'coupon_redemptions')
            ->withPivot(['course_id', 'order_id', 'coupon_code', 'discount_percentage', 'discount_coins', 'redeemed_at'])
            ->withTimestamps();
    }

    public function redemptions()
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function isAvailableAt(?\DateTimeInterface $at = null): bool
    {
        $moment = $at
            ? CarbonImmutable::instance($at)->utc()
            : BusinessClock::utcNow();
        $expiryDate = trim((string) $this->getRawOriginal('expiry_date'));
        $expiresAt = $expiryDate === ''
            ? null
            : BusinessClock::localDate(substr($expiryDate, 0, 10))->addDay()->startOfDay()->utc();

        return (bool) $this->active
            && (int) $this->balance > 0
            && (int) $this->balance <= 100
            && (! $this->starts_at || $this->starts_at->lessThanOrEqualTo($moment))
            && ($expiresAt === null || $moment->lessThan($expiresAt));
    }
}
