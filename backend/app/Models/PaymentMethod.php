<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Default payment method names
     */
    const DEFAULT_VODAFONE_CASH = 'فودافون كاش';

    const DEFAULT_INSTAPAY = 'انستاباي';

    /**
     * Default account details placeholder
     */
    const DEFAULT_ACCOUNT_DETAILS = 'يرجى تحديث تفاصيل الحساب';

    protected $fillable = [
        'name',
        'account_details',
        'description',
        'is_active',
        'is_default',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Scope a query to only include active payment methods.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeConfigured($query)
    {
        return $query
            ->whereNotNull('account_details')
            ->whereRaw("TRIM(account_details) <> ''")
            ->where('account_details', '<>', self::DEFAULT_ACCOUNT_DETAILS);
    }

    /**
     * Scope a query to only include default payment methods.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Check if the payment method is a default one.
     */
    public function isDefaultMethod(): bool
    {
        return $this->is_default;
    }

    /**
     * Get default payment methods data.
     */
    public static function getDefaultPaymentMethodsData(): array
    {
        return [
            [
                'name' => self::DEFAULT_VODAFONE_CASH,
                'account_details' => self::DEFAULT_ACCOUNT_DETAILS,
                'description' => 'الدفع عبر فودافون كاش',
                'is_active' => false,
                'is_default' => true,
            ],
            [
                'name' => self::DEFAULT_INSTAPAY,
                'account_details' => self::DEFAULT_ACCOUNT_DETAILS,
                'description' => 'الدفع عبر انستاباي',
                'is_active' => false,
                'is_default' => true,
            ],
        ];
    }

    /**
     * Create default payment methods.
     */
    public static function createDefaults(): void
    {
        $defaultMethods = self::getDefaultPaymentMethodsData();

        foreach ($defaultMethods as $methodData) {
            self::create($methodData);
        }
    }

    /**
     * Ensure default payment methods exist.
     */
    public static function ensureDefaultsExist(): void
    {
        $defaultMethods = self::getDefaultPaymentMethodsData();

        foreach ($defaultMethods as $methodData) {
            $exists = self::where('name', $methodData['name'])
                ->where('is_default', true)
                ->exists();

            if (! $exists) {
                self::create($methodData);
            }
        }
    }

    /**
     * Get all payment methods.
     */
    public static function getAll()
    {
        return self::all();
    }

    /**
     * Get active payment methods.
     */
    public static function getActive()
    {
        return self::where('is_active', true)->get();
    }
}
