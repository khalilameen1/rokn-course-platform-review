<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A stable, client-visible reason why a course quote, purchase, upgrade or
 * checkout step was refused. Same shape as PortfolioOperationException and
 * CourseCodeUnavailable: callers branch on the reason constant, never on
 * prose. The message is the reason itself, so existing API error codes stay
 * byte-for-byte identical for the mobile app.
 */
final class CourseCheckoutRejected extends \DomainException
{
    public const ACCOUNT_INACTIVE = 'account_inactive';

    public const CHECKOUT_ALREADY_PENDING = 'checkout_already_pending';

    public const CHECKOUT_CHANNEL_INVALID = 'checkout_channel_invalid';

    public const CHECKOUT_FEATURE_UNAVAILABLE = 'checkout_feature_unavailable';

    public const CHECKOUT_FUNDING_AMOUNT_INVALID = 'checkout_funding_amount_invalid';

    public const CHECKOUT_FUNDING_MISMATCH = 'checkout_funding_mismatch';

    public const CHECKOUT_FUNDING_MODE_INVALID = 'checkout_funding_mode_invalid';

    public const CHECKOUT_IDEMPOTENCY_CONFLICT = 'checkout_idempotency_conflict';

    public const CHECKOUT_PACKAGE_REQUIRED = 'checkout_package_required';

    public const CHECKOUT_PACKAGE_UNAVAILABLE = 'checkout_package_unavailable';

    public const COUPON_ALREADY_USED = 'coupon_already_used';

    public const COUPON_INVALID = 'coupon_invalid';

    public const COUPON_NOT_APPLICABLE = 'coupon_not_applicable';

    public const COUPON_QUOTA_REACHED = 'coupon_quota_reached';

    public const COURSE_ACCESS_CHANGED = 'course_access_changed';

    public const COURSE_ACCESS_REQUIRED = 'course_access_required';

    public const COURSE_ACCESS_UNDER_REVIEW = 'course_access_under_review';

    public const COURSE_NOT_AVAILABLE = 'course_not_available';

    public const COURSE_PRICE_CHANGED = 'course_price_changed';

    public const COURSE_PURCHASE_NOT_EFFECTIVE = 'course_purchase_not_effective';

    public const COURSE_TERMS_CHANGED = 'course_terms_changed';

    public const FULL_TRACK_UPGRADE_NOT_AVAILABLE = 'full_track_upgrade_not_available';

    public const FULL_TRACK_UPGRADE_NOT_PRICED = 'full_track_upgrade_not_priced';

    public const FULL_TRACK_UPGRADE_PAID_FLOOR_UNFUNDED = 'full_track_upgrade_paid_floor_unfunded';

    public const FINANCIAL_PROVENANCE_UNAVAILABLE = 'financial_provenance_unavailable';

    /** Any other domain refusal (model invariant, ledger guard). Its prose is never a code. */
    public const UNCLASSIFIED = 'checkout_rejected';

    public const COUPON_REJECTIONS = [
        self::COUPON_INVALID,
        self::COUPON_ALREADY_USED,
        self::COUPON_NOT_APPLICABLE,
        self::COUPON_QUOTA_REACHED,
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    /** The stable code for any domain refusal raised inside a checkout step. */
    public static function codeFor(\DomainException $exception): string
    {
        return match (true) {
            $exception instanceof self => $exception->reason,
            $exception instanceof FinancialProvenanceException => self::FINANCIAL_PROVENANCE_UNAVAILABLE,
            default => self::UNCLASSIFIED,
        };
    }
}
