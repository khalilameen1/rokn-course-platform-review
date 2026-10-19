<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\ProductAnalytics;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductAnalyticsIndexRequest;
use App\Services\AdminProductAnalyticsReadService;
use Illuminate\Http\RedirectResponse;

final class ProductAnalyticsController extends Controller
{
    public function index(ProductAnalyticsIndexRequest $request, AdminProductAnalyticsReadService $reports): RedirectResponse
    {
        $filters = $request->validated();

        return redirect(ProductAnalytics::getUrl(['filters' => [
            'course_id' => $reports->canonicalCourseId(isset($filters['course_id']) ? (int) $filters['course_id'] : null),
            'period' => $filters['period'] ?? '30d',
        ]]));
    }
}
