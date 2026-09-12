<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\StorePurchaseVerificationException;
use App\Http\Controllers\Controller;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\StoreBillingAccountIdentity;
use App\Services\StorePurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StorePurchaseController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses)
    {
    }

    public function context(
        Request $request,
        StoreBillingAccountIdentity $identities
    ): JsonResponse {
        /** @var User $user */
        $user = auth('api')->user();

        return $this->responses->success([
            'google_obfuscated_account_id' => $identities->rememberGoogle($user),
            'apple_app_account_token' => $identities->apple($user),
        ], 'تم تجهيز الدفع من المتجر');
    }

    public function verify(Request $request, StorePurchaseService $purchases): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in([
                StorePurchase::PROVIDER_GOOGLE,
                StorePurchase::PROVIDER_APPLE,
            ])],
            'product_id' => ['required', 'string', 'max:191'],
            'purchase_token' => ['required', 'string', 'max:30000'],
            'transaction_id' => ['nullable', 'string', 'max:191'],
        ]);
        /** @var User $user */
        $user = auth('api')->user();

        try {
            $result = $purchases->verifyAndCredit(
                $user,
                $validated['provider'],
                $validated['product_id'],
                $validated['purchase_token'],
                $validated['transaction_id'] ?? null
            );
        } catch (StorePurchaseVerificationException $exception) {
            return $this->responses->error(
                $exception->getMessage(),
                $exception->httpStatus,
                null,
                ['code' => $exception->errorCode]
            );
        }

        return $this->responses->success(
            $result,
            'تمت إضافة العملات إلى رصيدك'
        );
    }
}
