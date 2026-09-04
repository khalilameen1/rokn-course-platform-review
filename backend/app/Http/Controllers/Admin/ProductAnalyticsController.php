<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\PaymentChannelReportService;
use App\Services\ProductAnalyticsService;
use App\Support\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProductAnalyticsController extends Controller
{
    public function index(
        Request $request,
        ProductAnalyticsService $analytics,
        PaymentChannelReportService $payments
    ) {
        $filters = $request->validate([
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'days' => ['nullable', Rule::in([7, 14, 30, 60, 90, 180, 365])],
        ]);
        $courseId = isset($filters['course_id']) ? (int) $filters['course_id'] : null;
        $days = (int) ($filters['days'] ?? 30);
        $from = BusinessClock::now()->subDays($days)->startOfDay()->utc();
        $to = BusinessClock::utcNow();

        return view('admin.product_analytics', [
            'analytics' => $analytics->overview($courseId, $days),
            'paymentChannelReport' => $payments->summary($from, $to),
            'courses' => Course::query()
                ->orderBy('name_ar')
                ->get(['id', 'name_ar', 'name_en']),
            'filters' => ['course_id' => $courseId, 'days' => $days],
        ]);
    }
}
