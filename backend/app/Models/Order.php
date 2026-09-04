<?php

namespace App\Models;

use App\Support\CourseAccessPlanSnapshot;
use App\Support\PaymentEvidencePath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\URL;
use App\Models\PaymentMethod;

class Order extends Model
{
    use HasFactory,  SoftDeletes;

    protected $fillable = [
        'user_id',
        'course_id',
        'access_plan_id',
        'access_plan_snapshot',
        'parent_order_id',
        'package_id',
        'package_coins',
        'course_code_id',
        'coupon_id',
        'coupon_code',
        'payment_method',
        'payment_method_id',
        'payment_screenshot',
        'order_ref',
        'checkout_request_key',
        'wallet_transaction_id',
        'checkout_expires_at',
        'transaction_id',
        'payment_gateway_response',
        'amount',
        'discount_amount',
        'final_amount',
        'gateway_gross_amount',
        'gateway_fee_amount',
        'gateway_net_amount',
        'gateway_currency',
        'gateway_settlement_status',
        'gateway_settled_at',
        'total_coins',
        'paid_coins',
        'reward_coins',
        'status',
        'financial_status',
        'notes',
        'approved_at',
        'reversed_at',
        'reversal_reason',
        'recovered_coins',
        'unrecovered_coins',
        'approved_by',
        'is_premium_user'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'gateway_gross_amount' => 'decimal:2',
        'gateway_fee_amount' => 'decimal:2',
        'gateway_net_amount' => 'decimal:2',
        'gateway_settled_at' => 'datetime',
        'total_coins' => 'integer',
        'paid_coins' => 'integer',
        'reward_coins' => 'integer',
        'package_coins' => 'integer',
        'approved_at' => 'datetime',
        'checkout_expires_at' => 'datetime',
        'reversed_at' => 'datetime',
        'recovered_coins' => 'integer',
        'unrecovered_coins' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'payment_gateway_response' => 'array',
        'access_plan_snapshot' => 'array',
        'parent_order_id' => 'integer',
        'is_premium_user' => 'boolean',
        'wallet_transaction_id' => 'integer',
    ];

    public function getPaymentScreenshotUrlAttribute(): ?string
    {
        if (
            !$this->exists
            || PaymentEvidencePath::from($this->getRawOriginal('payment_screenshot')) === null
        ) {
            return null;
        }

        return URL::temporarySignedRoute(
            'payment.evidence',
            now()->addMinutes(5),
            ['order' => $this->getKey()]
        );
    }


    // Payment methods
    const PAYMENT_METHOD_ONLINE = 'online';
    const PAYMENT_METHOD_COURSE_CODE = 'course_code';
    const PAYMENT_METHOD_WALLET = 'wallet';
    const PAYMENT_METHOD_WALLET_COINS = 'wallet_coins';
    const PAYMENT_METHOD_KASHIER = 'kashier';
    const PAYMENT_METHOD_GOOGLE_PLAY = 'google_play';
    const PAYMENT_METHOD_APP_STORE = 'app_store';

    public const KASHIER_CHECKOUT_TTL_MINUTES = 30;

    public function requiresProviderVerification(): bool
    {
        return $this->package_id !== null && in_array($this->payment_method, [
            self::PAYMENT_METHOD_KASHIER,
            self::PAYMENT_METHOD_GOOGLE_PLAY,
            self::PAYMENT_METHOD_APP_STORE,
        ], true);
    }

    // Order statuses
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_FAILED = 'cancelled'; // Mapped to 'cancelled' to match orders table enum in MySQL

    public const FINANCIAL_PENDING = 'pending';
    public const FINANCIAL_SETTLED = 'settled';
    public const FINANCIAL_REJECTED = 'rejected';
    public const FINANCIAL_CANCELLED = 'cancelled';
    public const FINANCIAL_REFUNDED = 'refunded';
    public const FINANCIAL_CHARGEBACK = 'chargeback';
    public const FINANCIAL_REVERSED = 'reversed';
    public const FINANCIAL_PARTIALLY_RECOVERED = 'partially_recovered';
    public const FINANCIAL_REVIEW_REQUIRED = 'review_required';

    /**
     * Get the user that owns the order.
     */
    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Get the course for this order.
     */
    public function course()
    {
        // Financial history must remain readable after a course is retired.
        return $this->belongsTo(Course::class)->withTrashed();
    }

    /**
     * Get the course code used for this order.
     */
    public function courseCode()
    {
        return $this->belongsTo(CourseCode::class);
    }

    /**
     * Get the payment method used for this order.
     */
    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * Get the coupon used for this order.
     */
    public function coupon()
    {
        return $this->belongsTo(Coupon::class)->withTrashed();
    }

    /**
     * Get the admin who approved the order.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the bill for this order.
     */
    public function bill()
    {
        return $this->hasOne(Bill::class);
    }

    /**
     * Get the package being purchased in this order.
     */
    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function storePurchase()
    {
        return $this->hasOne(StorePurchase::class);
    }

    public function walletTransaction()
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function accessPlan()
    {
        return $this->belongsTo(CourseAccessPlan::class, 'access_plan_id');
    }

    public function parentOrder()
    {
        return $this->belongsTo(self::class, 'parent_order_id');
    }

    public function upgradeOrders()
    {
        return $this->hasMany(self::class, 'parent_order_id');
    }

    public function financialEvents()
    {
        return $this->hasMany(OrderFinancialEvent::class);
    }

    public function latestPaymentReconciliationFinding()
    {
        return $this->hasOne(PaymentReconciliationFinding::class)->latestOfMany('last_seen_at');
    }

    public function paidCreditLot()
    {
        return $this->hasOne(WalletCreditLot::class, 'source_order_id');
    }

    public function paidDebitAllocations()
    {
        return $this->hasMany(WalletDebitAllocation::class, 'course_order_id');
    }

    public function financialEntitlementHolds()
    {
        return $this->hasMany(FinancialEntitlementHold::class, 'source_order_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Order $order): void {
            if (!$order->exists || $order->isDirty(['access_plan_id', 'access_plan_snapshot'])) {
                CourseAccessPlanSnapshot::assertValidForPlan(
                    $order->access_plan_id !== null ? (int) $order->access_plan_id : null,
                    $order->access_plan_snapshot
                );
            }
        });

        static::updating(function (Order $order): void {
            foreach (['total_coins', 'paid_coins', 'reward_coins', 'package_coins', 'checkout_request_key', 'checkout_expires_at', 'access_plan_id', 'access_plan_snapshot', 'parent_order_id'] as $field) {
                // Allocation is written once with the course order and is an
                // accounting fact, not editable dashboard metadata.
                if ($order->getOriginal($field) !== null && $order->isDirty($field)) {
                    throw new \LogicException('Course coin allocation is immutable.');
                }
            }

            if (
                in_array($order->getOriginal('payment_method'), [
                    self::PAYMENT_METHOD_KASHIER,
                    self::PAYMENT_METHOD_GOOGLE_PLAY,
                    self::PAYMENT_METHOD_APP_STORE,
                ], true)
                && $order->getOriginal('package_id') !== null
            ) {
                foreach ([
                    'user_id',
                    'order_ref',
                    'package_id',
                    'payment_method',
                    'amount',
                    'discount_amount',
                    'final_amount',
                    'is_premium_user',
                ] as $field) {
                    if ($order->isDirty($field)) {
                        throw new \LogicException('Issued payment checkout facts are immutable.');
                    }
                }

                if ($order->getOriginal('transaction_id') !== null && $order->isDirty('transaction_id')) {
                        throw new \LogicException('Payment transaction identity is write-once.');
                }

                foreach ([
                    'gateway_gross_amount',
                    'gateway_fee_amount',
                    'gateway_net_amount',
                    'gateway_currency',
                    'gateway_settled_at',
                ] as $settlementField) {
                    if ($order->getOriginal($settlementField) !== null && $order->isDirty($settlementField)) {
                        throw new \LogicException('Payment settlement facts are write-once.');
                    }
                }
            }
        });
    }

    /**
     * Scope to find an order by its unique order reference.
     */
    public function scopeByOrderRef($query, $ref)
    {
        return $query->where('order_ref', $ref);
    }

    /**
     * Scope for pending orders.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for approved orders.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Orders that still represent delivered, paid value. Operational approval
     * alone is insufficient because refunds and chargebacks intentionally keep
     * the original approval audit trail.
     */
    public function scopeFinanciallyEffective($query)
    {
        return $query
            ->where('status', self::STATUS_APPROVED)
            ->where('financial_status', self::FINANCIAL_SETTLED)
            ->whereNull('reversed_at');
    }

    /**
     * Check if order is pending.
     */
    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if order is approved.
     */
    public function isApproved()
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isFinanciallyEffective(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->financial_status === self::FINANCIAL_SETTLED
            && $this->reversed_at === null;
    }

    public function financialStatusLabel(): string
    {
        if ($this->isFinanciallyEffective()) {
            return 'مُعتمد وفعّال';
        }

        return match ($this->financial_status) {
            self::FINANCIAL_REFUNDED => 'مسترد',
            self::FINANCIAL_CHARGEBACK => 'اعتراض بنكي',
            self::FINANCIAL_REVERSED => 'معكوس',
            self::FINANCIAL_PARTIALLY_RECOVERED => 'استرداد جزئي',
            self::FINANCIAL_REVIEW_REQUIRED => 'مراجعة مالية',
            default => 'غير فعّال ماليًا',
        };
    }

    public function isCheckoutExpired(): bool
    {
        if ($this->checkout_expires_at !== null) {
            return $this->checkout_expires_at->isPast();
        }

        // Orders issued before checkout expiry was introduced have no stored
        // deadline. Treat only legacy Kashier package intents as expired from
        // their immutable creation time; store purchases have a different
        // provider lifecycle and must not inherit this fallback.
        return $this->payment_method === self::PAYMENT_METHOD_KASHIER
            && $this->package_id !== null
            && $this->created_at !== null
            && $this->created_at->copy()
                ->addMinutes(self::KASHIER_CHECKOUT_TTL_MINUTES)
                ->isPast();
    }

    /**
     * Check if order is rejected.
     */
    public function isRejected()
    {
        return $this->status === self::STATUS_REJECTED;
    }

}
