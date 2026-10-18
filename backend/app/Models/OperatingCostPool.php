<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class OperatingCostPool extends Model
{
    use SoftDeletes;

    public const SERVICES = [
        'bunny_delivery' => 'Bunny: نقل الفيديو',
        'bunny_storage' => 'Bunny: التخزين',
        'infrastructure' => 'السيرفر وقاعدة البيانات',
        'notifications' => 'الإشعارات والرسائل',
        'email_sms' => 'البريد وSMS وOTP',
        'monitoring' => 'المراقبة والنسخ الاحتياطي',
        'software_licenses' => 'تراخيص وأدوات التشغيل والمتاجر',
        'support_operations' => 'الدعم وخدمة الطلاب',
        'tax_compliance' => 'الضرائب والامتثال المحمّلة على التشغيل',
        'other' => 'تكلفة تشغيل أخرى',
    ];

    public const DRIVERS = [
        'playback_gb' => 'حسب جيجابايت المشاهدة المقدرة',
        'playback_minutes' => 'حسب دقائق المشاهدة',
        'active_students' => 'حسب تسجيلات الكورسات خلال الفترة',
    ];

    protected $fillable = [
        'name', 'service_key', 'course_id', 'period_start', 'period_end',
        'amount', 'currency', 'fx_rate_to_egp', 'allocation_driver',
        'is_final', 'notes', 'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'amount' => 'decimal:4',
        'fx_rate_to_egp' => 'decimal:4',
        'is_final' => 'boolean',
    ];

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Exact invoice value in EGP (a USD invoice at its recorded rate); null without a rate. */
    public function amountEgp(): ?Money
    {
        if ($this->currency === 'EGP') {
            return Money::ofEgp($this->amount);
        }
        if ($this->currency === 'USD' && Money::ofEgp($this->fx_rate_to_egp)->isPositive()) {
            return Money::ofEgp($this->amount)->mul($this->fx_rate_to_egp);
        }

        return null;
    }
}
