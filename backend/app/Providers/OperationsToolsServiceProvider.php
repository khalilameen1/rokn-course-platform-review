<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\AdminPermissionMatrix;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

final class OperationsToolsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Do not inherit either package's development-environment bypass.
        Gate::define('viewPulse', fn (?User $user): bool => $user?->active && app(AdminPermissionMatrix::class)->allows($user, 'admin.product-operations.index', 'GET'));
        Horizon::auth(fn ($request): bool => $request->user()?->active && app(AdminPermissionMatrix::class)->allows($request->user(), 'admin.product-operations.index', 'GET'));
    }
}
