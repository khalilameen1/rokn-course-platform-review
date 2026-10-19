<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductAnalyticsIndexRequest;
use App\Services\AdminProductAnalyticsReadService;
use Illuminate\View\View;

final class ProductAnalyticsController extends Controller
{
    public function index(ProductAnalyticsIndexRequest $request, AdminProductAnalyticsReadService $reports): View
    {
        $filters = $request->validated();

        return view('admin.product_analytics', $reports->report(
            isset($filters['course_id']) ? (int) $filters['course_id'] : null,
            $filters['period'] ?? '30d',
        ));
    }
}
