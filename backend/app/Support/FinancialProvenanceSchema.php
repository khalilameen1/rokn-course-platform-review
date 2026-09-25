<?php

declare(strict_types=1);

namespace App\Support;

/** Shared readiness check for provenance writes and entitlement-hold reads. */
final class FinancialProvenanceSchema
{
    public static function available(): bool
    {
        return DatabaseCapabilities::hasTable('wallet_credit_lots')
            && DatabaseCapabilities::hasTable('wallet_debit_allocations')
            && DatabaseCapabilities::hasTable('financial_entitlement_holds');
    }
}
