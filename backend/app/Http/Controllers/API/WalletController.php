<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\WalletQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WalletController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly WalletQueryService $wallet
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $this->responses->success(
            $this->wallet->summary($user),
            'تم تحميل رصيدك'
        );
    }

    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        /** @var User $user */
        $user = auth('api')->user();

        return $this->responses->success(
            $this->wallet->transactions($user, (int) ($validated['per_page'] ?? 20)),
            'تم تحميل حركات الرصيد'
        );
    }
}
