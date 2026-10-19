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
            ['الكورسات', 'المحتوى', 'admin.courses.index'],
            ['مشاريع الطلاب', 'المراجعة', 'admin.project-submissions.index'],
            ['مراجعة البورتفوليو', 'المراجعة', 'admin.portfolio-reviews.index'],
            ['أكواد المنح', 'التجارة', 'admin.course-codes.index'],
            ['الإشعارات', 'التطبيق', 'admin.notifications.index'],
            ['صور وهوية التطبيق', 'التطبيق', 'admin.design-settings.index'],
            ['تشغيل المنتج', 'التشغيل', 'admin.product-operations.index'],
            ['تحليلات المنتج', 'التشغيل', 'admin.product-analytics.index'],
            ['الإعدادات', 'الإدارة', 'admin.settings'],
            ['فريق العمل', 'الإدارة', 'admin.moderators.index'],
            ['بيانات حسابي', 'الإدارة', 'admin.admin_data'],
        ];

        return array_map(static fn (array $link): NavigationItem => NavigationItem::make($link[0])
            ->group($link[1])->url(fn (): string => route($link[2]))
            ->visible(fn (): bool => app(AdminPermissionMatrix::class)->allows(auth('web')->user(), $link[2], 'GET')), $links);
    }
}
