<?php

declare(strict_types=1);

namespace App\Filament\Navigation;

use App\Auth\AdminPermissionMatrix;
use Filament\Navigation\NavigationItem;

/** Existing specialized workspaces remain reachable throughout incremental migration. */
final class LegacyWorkspaces
{
    /** @return list<NavigationItem> */
    public static function items(): array
    {
        $links = [
            ['الكورسات', 'المحتوى', 'admin.courses.index', 10],
            ['تشغيل المنتج', 'التشغيل', 'admin.product-operations.index', 10],
            ['تحليلات المنتج', 'التشغيل', 'admin.product-analytics.index', 20],
            ['فريق العمل', 'الإدارة', 'admin.moderators.index', 20],
            ['بيانات حسابي', 'الإدارة', 'admin.admin_data', 50],
        ];

        return array_map(static fn (array $link): NavigationItem => NavigationItem::make($link[0])
            ->group($link[1])->sort($link[3])->url(fn (): string => route($link[2]))
            ->visible(fn (): bool => app(AdminPermissionMatrix::class)->allows(auth('web')->user(), $link[2], 'GET')), $links);
    }
}
