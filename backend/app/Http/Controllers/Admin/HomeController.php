<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Http\Controllers\Controller;
use App\Services\AdminHomeReadService;
use App\Services\ModeratorHomeReadService;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Request $request, AdminPermissionMatrix $permissions): View
    {
        // Resolve only the authorized projection. In particular a moderator
        // request must not instantiate the financial report dependency graph.
        if (!$permissions->isAdministrator($request->user()?->role)) {
            return view('admin.home.moderator', app(ModeratorHomeReadService::class)->read(
                $request->query(),
                max(1, (int) $request->query('page', 1))
            ));
        }

        $filters = $request->validate([
            'period' => ['nullable', Rule::in(array_keys(ReportPeriod::labels()))],
        ]);

        return view('admin.home.index', app(AdminHomeReadService::class)->read(
            ReportPeriod::fromKey($filters['period'] ?? '30d')
        ));
    }
}
