<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\ApiResponseService;
use App\Services\PackageChannelPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PackageController extends Controller
{
    public function __construct(
        private readonly PackageChannelPricingService $pricing,
        private readonly ApiResponseService $responses
    ) {}

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
            // A cache outage serves the catalogue uncached instead of failing.
            $packages = $load();
        }

        return $this->responses->success($packages, 'تم تحميل باقات العملات');
    }

    public function show(int|string $id): JsonResponse
    {
        $package = Package::query()
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->where('coins', '>', 0)
            ->purchasable()
            ->find($id);

        if (! $package) {
            return $this->responses->error('الباقة غير متاحة', 404);
        }

        return $this->responses->success($this->pricing->packagePayload($package), 'تم تحميل الباقة');
    }
}
