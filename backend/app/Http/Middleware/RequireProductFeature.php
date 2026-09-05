<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ProductFeatureFlagService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireProductFeature
{
    public function __construct(private ProductFeatureFlagService $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if ($feature === 'checkout' && (bool) config('operations.disaster_recovery_mode', false)) {
            return response()->json([
                'status' => 503,
                'success' => false,
                'code' => 'recovery_in_progress',
                'feature' => $feature,
                'message' => "الدفع غير متاح الآن\nيمكنك متابعة محتواك الحالي",
            ], 503, ['Retry-After' => '300']);
        }

        $subject = $this->features->subjectForRequest($request);
        if (!$this->features->enabled($feature, $subject)) {
            return response()->json([
                'status' => 503,
                'success' => false,
                'code' => 'feature_temporarily_unavailable',
                'feature' => $feature,
                'message' => 'This feature is temporarily unavailable.',
            ], 503);
        }

        return $next($request);
    }
}
