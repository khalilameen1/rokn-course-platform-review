<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\PackageChannelPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PackageController extends Controller
{
    public function __construct(private readonly PackageChannelPricingService $pricing)
    {
    }

    public function index(): JsonResponse
    {
        $discountPercent = $this->pricing->directDiscountPercent();
        $load = fn () => Package::query()
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->where('coins', '>', 0)
            ->purchasable()
            ->orderBy('sort_order')
            ->orderBy('coins')
            ->orderBy('id')
            ->get()
            ->map(fn (Package $package): array => $this->pricing->packagePayload($package, $discountPercent));
        try {
            $packages = Cache::remember('public-packages:v2', 60, $load);
        } catch (Throwable) {
            $packages = $load();
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل باقات العملات',
            'data' => $packages,
        ]);
    }

    public function show(int|string $id): JsonResponse
    {
        $package = Package::query()
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->where('coins', '>', 0)
            ->purchasable()
            ->find($id);

        if (!$package) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'الباقة غير متاحة',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل الباقة',
            'data' => $this->pricing->packagePayload($package),
        ]);
    }

}
