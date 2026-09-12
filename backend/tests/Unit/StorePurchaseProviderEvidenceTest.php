<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\VerifiedStorePurchase;
use App\Exceptions\StorePurchaseVerificationException;
use App\Services\LiveStorePurchaseProviderGateway;
use Google\Service\AndroidPublisher\ProductPurchaseV2;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class StorePurchaseProviderEvidenceTest extends TestCase
{
    public function test_google_receipt_returns_only_provider_environment_and_binding(): void
    {
        $receipt = $this->receipt(['testPurchaseContext' => ['fopType' => 'TEST']]);
        $verified = $this->verify($receipt);

        self::assertSame('test', $verified->environment);
        self::assertSame('bound-account', $verified->accountBinding);
        self::assertSame(1, $verified->quantity);
        self::assertSame('production', $this->verify($this->receipt())->environment);
    }

    public function test_google_pending_purchase_cannot_be_fulfilled(): void
    {
        $this->expectException(StorePurchaseVerificationException::class);
        $this->expectExceptionMessage('عملية الدفع ما زالت قيد التأكيد.');
        $this->verify($this->receipt(['purchaseStateContext' => ['purchaseState' => 'PENDING']]));
    }

    public function test_google_multi_quantity_is_rejected(): void
    {
        $this->assertRejected('store_purchase_quantity_unsupported', $this->receipt([
            'productLineItem' => [['productId' => 'rokn.coins.600', 'productOfferDetails' => ['quantity' => 2]]],
        ]));
    }

    public function test_google_multiple_line_items_cannot_be_partially_consumed(): void
    {
        $this->assertRejected('store_purchase_quantity_unsupported', $this->receipt([
            'productLineItem' => [
                ['productId' => 'rokn.coins.600', 'productOfferDetails' => ['quantity' => 1]],
                ['productId' => 'rokn.coins.900', 'productOfferDetails' => ['quantity' => 1]],
            ],
        ]));
    }

    public function test_google_receipt_must_match_the_requested_product_and_account(): void
    {
        $this->assertRejected('store_account_mismatch', $this->receipt(['obfuscatedExternalAccountId' => 'foreign-account']));
        $this->assertRejected('store_account_mismatch', $this->receipt(['obfuscatedExternalAccountId' => '']));
        $this->assertRejected('store_product_mismatch', $this->receipt([
            'productLineItem' => [['productId' => 'foreign.product']],
        ]));
    }

    private function receipt(array $overrides = []): ProductPurchaseV2
    {
        return new ProductPurchaseV2(array_replace([
            'orderId' => 'GPA.1234',
            'purchaseStateContext' => ['purchaseState' => 'PURCHASED'],
            'obfuscatedExternalAccountId' => 'bound-account',
            'productLineItem' => [[
                'productId' => 'rokn.coins.600',
                'productOfferDetails' => ['quantity' => 1, 'consumptionState' => 'CONSUMPTION_STATE_YET_TO_BE_CONSUMED'],
            ]],
        ], $overrides));
    }

    private function verify(ProductPurchaseV2 $receipt): VerifiedStorePurchase
    {
        return (new ReflectionMethod(LiveStorePurchaseProviderGateway::class, 'verifiedGooglePurchase'))
            ->invoke(new LiveStorePurchaseProviderGateway(), $receipt, 'rokn.coins.600', 'test-token', 'bound-account');
    }

    private function assertRejected(string $code, ProductPurchaseV2 $receipt): void
    {
        try {
            $this->verify($receipt);
            self::fail('Unverified receipt must not be accepted.');
        } catch (StorePurchaseVerificationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
