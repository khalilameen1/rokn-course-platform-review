<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\VerifiedStorePurchase;

interface StorePurchaseProviderGateway
{
    public function verify(
        string $provider,
        string $productId,
        string $purchaseToken,
        ?string $transactionId,
        string $expectedAccountBinding
    ): VerifiedStorePurchase;

    /** Inspect a Google-authenticated receipt before resolving its Rokn owner. */
    public function verifyGoogleNotification(string $productId, string $purchaseToken): VerifiedStorePurchase;

    /** Idempotently consume an already-fulfilled Google consumable. */
    public function consumeGoogle(string $productId, string $purchaseToken, string $expectedAccountBinding): void;
}
