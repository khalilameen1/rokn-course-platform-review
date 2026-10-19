<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Pages\ContentWorkspace;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinancialOverview;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportPeriodRequest;
use Illuminate\Http\RedirectResponse;

class HomeController extends Controller
{
    public function index(ReportPeriodRequest $request, AdminPermissionMatrix $permissions): RedirectResponse
    {
        if (! $permissions->isAdministrator($request->user())) {
            return redirect(ContentWorkspace::canAccess()
                ? ContentWorkspace::getUrl(['page' => max(1, (int) $request->query('page', 1))])
                : Dashboard::getUrl());
        }

        $filters = $request->validated();

        return redirect(isset($filters['period'])
            ? FinancialOverview::getUrl(['filters' => ['period' => $filters['period']]])
            : Dashboard::getUrl());
    }
}
