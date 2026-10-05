<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

final class CourseCheckout extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['terms' => 'array', 'expires_at' => 'immutable_datetime',
        'authorized_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    /** Quote expiry prevents starting a new payment, not settling an issued one. */
    public function hasCommittedFunding(): bool
    {
        return $this->authorized_at !== null && $this->funding_order_id !== null;
    }

    public function quoteHasExpired(): bool
    {
        return !$this->hasCommittedFunding() && $this->expires_at->isPast();
    }

    public function canResumePaymentWith(Order $order): bool
    {
        return $this->status === 'pending_payment' && $this->channel === 'direct'
            && $this->hasCommittedFunding()
            && (int) $this->funding_order_id === (int) $order->id
            && (int) $this->user_id === (int) $order->user_id
            && $order->payment_method === Order::PAYMENT_METHOD_KASHIER
            && $order->status === Order::STATUS_PENDING
            && $order->financial_status === Order::FINANCIAL_PENDING
            && !$order->isCheckoutExpired();
    }

    public function scopeAwaitingSettlement(Builder $query): Builder
    {
        return $query->where('status', 'pending_payment')->where(function (Builder $pending): void {
            $pending->where('expires_at', '>', now())
                ->orWhere(function (Builder $bound): void {
                    $bound->whereNotNull('authorized_at')->whereNotNull('funding_order_id');
                });
        });
    }

    protected static function booted(): void
    {
        static::updating(function (self $checkout): void {
            foreach (['public_id', 'user_id', 'course_id', 'terms', 'terms_hash', 'channel', 'package_id', 'expires_at'] as $field) {
                if ($checkout->isDirty($field)) throw new \LogicException('Checkout terms are immutable');
            }
        });
    }
}
