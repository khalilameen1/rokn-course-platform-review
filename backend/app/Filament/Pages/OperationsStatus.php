<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use Filament\Pages\Page;

final class OperationsStatus extends Page
{
    protected static ?string $title = 'صحة التشغيل';

    protected static string|\UnitEnum|null $navigationGroup = 'التشغيل';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'system-status';

    protected string $view = 'filament.pages.operations-status';

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.product-operations.index', 'GET');
    }
}
