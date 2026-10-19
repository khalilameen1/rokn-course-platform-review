<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Auth\StaffAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportPeriodRequest;
use App\Services\AdminHomeReadService;
use App\Services\ModeratorHomeReadService;
use App\Support\ReportPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(ReportPeriodRequest $request, AdminPermissionMatrix $permissions): View|RedirectResponse
    {
        if (app(StaffAccess::class)->allows($request->user(), 'content.read') !== null) {
            return redirect('/operations');
        }
        // Resolve only the authorized projection. In particular a moderator
        // request must not instantiate the financial report dependency graph.
        if (! $permissions->isAdministrator($request->user())) {
            return view('admin.home.moderator', app(ModeratorHomeReadService::class)->read(
                $request->query(),
                max(1, (int) $request->query('page', 1))
            ));
        }

        $filters = $request->validated();

        return view('admin.home.index', app(AdminHomeReadService::class)->read(
            ReportPeriod::fromKey($filters['period'] ?? '30d')
        ));
    }
}
