<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use App\Services\ApiResponseService;
use App\Support\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class VisitorController extends Controller
{
    public function __construct(private readonly ApiResponseService $responses) {}

    public function getStats(Request $request): JsonResponse
    {
        $businessNow = BusinessClock::now();
        [$today, $tomorrow] = BusinessClock::localDayRangeUtc($businessNow->format('Y-m-d'));
        $thisWeek = $businessNow->startOfWeek()->utc();
        $thisMonth = $businessNow->startOfMonth()->utc();

        $stats = [
            'total_visitors' => Visitor::count(),
            'today_visitors' => Visitor::where('visited_at', '>=', $today)->where('visited_at', '<', $tomorrow)->count(),
            'this_week_visitors' => Visitor::where('visited_at', '>=', $thisWeek)->count(),
            'this_month_visitors' => Visitor::where('visited_at', '>=', $thisMonth)->count(),
            'unique_visitors_today' => Visitor::where('visited_at', '>=', $today)
                ->where('visited_at', '<', $tomorrow)
                ->distinct('ip_address')
                ->count('ip_address'),
            'browser_stats' => Visitor::select('browser', DB::raw('count(*) as count'))
                ->groupBy('browser')
                ->orderBy('count', 'desc')
                ->get(),
            'os_stats' => Visitor::select('operating_system', DB::raw('count(*) as count'))
                ->groupBy('operating_system')
                ->orderBy('count', 'desc')
                ->get(),
            'device_stats' => Visitor::select('device_type', DB::raw('count(*) as count'))
                ->groupBy('device_type')
                ->orderBy('count', 'desc')
                ->get(),
        ];

        return $this->responses->success($stats, 'Visitor statistics retrieved successfully');
    }

    public function getRecentVisitors(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
        ]);
        $visitors = Visitor::query()
            ->select([
                'id',
                'browser',
                'operating_system',
                'device_type',
                'visited_at',
            ])
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get();

        return $this->responses->success($visitors, 'Recent visitor activity retrieved successfully');
    }
}
