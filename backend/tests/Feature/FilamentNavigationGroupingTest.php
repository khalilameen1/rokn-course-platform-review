<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Auth\EditStaffProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FilamentNavigationGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_and_specialized_workspaces_share_ordered_groups_and_unique_destinations(): void
    {
        $this->operator('admin');
        $navigation = Filament::getNavigation();
        self::assertSame([null, 'المحتوى', 'الطلبة', 'المراجعة', 'التجارة', 'التطبيق', 'التشغيل', 'الإدارة'],
            array_values(array_map(fn ($group) => $group->getLabel(), $navigation)));
        $destinations = [];
        $items = [];
        foreach ($navigation as $group) {
            $items[$group->getLabel() ?? 'root'] = collect($group->getItems())->map(fn ($item) => $item->getLabel())->values()->all();
            foreach ($group->getItems() as $item) {
                $destinations[] = $item->getUrl();
            }
        }
        self::assertSame(['لوحة رُكن'], $items['root']);
        self::assertSame(['مساحة المحتوى', 'الكورسات', 'صفوف الرئيسية', 'المسارات', 'المحاضرون'], $items['المحتوى']);
        self::assertSame(['مشاريع الطلاب', 'مراجعة البورتفوليو', 'رسائل الدعم', 'رسائل التواصل'], $items['المراجعة']);
        self::assertSame(['الأداء المالي', 'الطلبات', 'مطابقة المدفوعات', 'حزم المتاجر', 'الخصومات', 'المصروفات', 'اقتصاديات التشغيل', 'أكواد المنح'], $items['التجارة']);
        self::assertSame(['الإشعارات', 'قوالب الإشعارات', 'صور وهوية التطبيق', 'الشارات', 'المكافآت التلقائية', 'مهام العملات', 'إعدادات المكافآت'], $items['التطبيق']);
        self::assertSame(count($destinations), count(array_unique($destinations)));
    }

    public function test_grouping_does_not_expose_finance_students_or_operations_to_a_content_editor(): void
    {
        $this->operator('moderator');
        $navigation = Filament::getNavigation();
        $groups = array_map(fn ($group) => $group->getLabel(), $navigation);
        self::assertContains('المحتوى', $groups);
        self::assertNotContains('التجارة', $groups);
        self::assertNotContains('الطلبة', $groups);
        self::assertNotContains('التشغيل', $groups);
        $labels = [];
        foreach ($navigation as $group) {
            foreach ($group->getItems() as $item) {
                $labels[] = $item->getLabel();
            }
        }
        self::assertContains('الكورسات', $labels);
        self::assertSame(EditStaffProfile::getUrl(), Filament::getProfileUrl());
        self::assertNotContains('الأدوار', $labels);
        self::assertNotContains('صلاحيات الفريق', $labels);
    }

    private function operator(string $role): void
    {
        $this->actingAs(User::query()->forceCreate(['role' => $role, 'name' => 'Operator', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }
}
