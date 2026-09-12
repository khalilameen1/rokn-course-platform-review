<?php

declare(strict_types=1);

namespace App\Data;

final readonly class VerifiedStorePurchase
{
    /** @param array<string, scalar|null> $auditPayload */
    public function __construct(
        public string $provider,
        public string $productId,
        public string $externalTransactionId,
        public string $environment,
        public ?string $currency = null,
        public ?float $grossAmount = null,
        public array $auditPayload = [],
        public int $quantity = 1,
        public ?string $accountBinding = null
    ) {
    }
}
