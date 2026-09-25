<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PaymentReconciliationFinding;

final class PaymentFindingEditorVersion
{
    public static function for(PaymentReconciliationFinding $finding): string
    {
        return AdminEditorVersion::for($finding, [
            'state', 'attempts', 'last_seen_at', 'local_status', 'local_financial_status',
            'provider_status', 'provider_transaction_id', 'evidence',
        ]);
    }
}
