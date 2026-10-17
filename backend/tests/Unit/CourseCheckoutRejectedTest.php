<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\CourseCheckoutRejected;
use App\Exceptions\FinancialProvenanceException;
use App\Services\OrderLifecycleService;
use PHPUnit\Framework\TestCase;

final class CourseCheckoutRejectedTest extends TestCase
{
    public function test_checkout_codes_come_from_the_typed_reason_never_from_prose(): void
    {
        $typed = new CourseCheckoutRejected(CourseCheckoutRejected::COUPON_INVALID);

        self::assertInstanceOf(\DomainException::class, $typed);
        // Callers that still read the message see the same API code.
        self::assertSame('coupon_invalid', $typed->getMessage());
        self::assertSame('coupon_invalid', CourseCheckoutRejected::codeFor($typed));
        self::assertSame(
            'financial_provenance_unavailable',
            CourseCheckoutRejected::codeFor(new FinancialProvenanceException('Financial provenance is not ready.'))
        );
        self::assertSame(
            'checkout_rejected',
            CourseCheckoutRejected::codeFor(new \DomainException('Course access is on hold while its source payment is under financial review.'))
        );
    }

    public function test_order_lifecycle_has_no_replay_reconciliation_entry_points(): void
    {
        // reconcile() re-ran approve()->fulfillCourse() and could reactivate
        // an enrollment under a financial hold; reconcileBill() was unused.
        foreach (['reconcile', 'reconcileBill', 'expectedBillStatus'] as $method) {
            self::assertFalse(method_exists(OrderLifecycleService::class, $method), $method);
        }
    }
}
