<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StorePurchaseProviderGateway;
use App\Exceptions\StorePurchaseVerificationException;
use App\Models\StorePurchase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

final readonly class StorePurchaseFinalizationService
{
    public function __construct(
        private StorePurchaseProviderGateway $gateway,
        private StoreBillingAccountIdentity $identities
    ) {
    }

    /** The committed receipt doubles as a durable queue; never call inside a credit transaction. */
    public function attempt(int $purchaseId): bool
    {
        try {
            // A short database lease prevents device replays and scheduler runs
            // issuing concurrent calls. Process death leaves a due retry, not
            // an in-memory job that can disappear after credit commits.
            $claimed = $this->due()->whereKey($purchaseId)->update([
                'finalization_retry_at' => now()->addMinutes(2),
            ]);
            if (!$claimed) {
                return StorePurchase::query()->whereKey($purchaseId)->whereNotNull('finalized_at')->exists();
            }
            $purchase = StorePurchase::query()->findOrFail($purchaseId);
            $binding = (string) data_get($purchase->provider_payload, 'account_binding', '');
            if ($binding === '') {
                // Legacy rows predate the persisted binding; the gateway still
                // checks it against provider state before any consume request.
                $binding = $this->identities->google((int) $purchase->user_id);
            }
            $this->gateway->consumeGoogle($purchase->product_id, $purchase->purchase_token, $binding);
            $purchase->forceFill(['finalized_at' => now(), 'finalization_retry_at' => null])->save();

            return true;
        } catch (\Throwable $exception) {
            // Never turn an already-committed credit into a failed checkout.
            // Do not log provider exceptions that can contain receipt tokens.
            Log::warning('Google Play finalization deferred', [
                'store_purchase_id' => $purchaseId,
                'error_code' => $exception instanceof StorePurchaseVerificationException
                    ? $exception->errorCode : 'store_finalization_unavailable',
            ]);

            return false;
        }
    }

    public function due(): Builder
    {
        return StorePurchase::query()
            ->where('provider', StorePurchase::PROVIDER_GOOGLE)
            ->where('status', 'credited')
            ->whereNotNull('verified_at')
            ->whereNull('finalized_at')
            ->where(function (Builder $query): void {
                $query->whereNull('finalization_retry_at')->orWhere('finalization_retry_at', '<=', now());
            })
            ->whereHas('order', fn (Builder $orders) => $orders->financiallyEffective());
    }
}
