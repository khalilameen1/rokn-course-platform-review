<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ArtworkSettings;
use App\Filament\Resources\ContactResource;
use App\Filament\Resources\ContactResource\Pages\ListContacts;
use App\Filament\Resources\FeedbackResource;
use App\Filament\Resources\FeedbackResource\Pages\ListFeedback;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\RendersAdminPages;
use Tests\TestCase;

final class AdminShellNavigationContractTest extends TestCase
{
    use RefreshDatabase;
    use RendersAdminPages;

    public function test_each_daily_task_has_one_legacy_shell_entry(): void
    {
        $admin = $this->dashboardUser('admin');
        $html = $this->adminGet($admin, route('admin.privacy'))->assertOk()->getContent();
        $dom = $this->dom($html);
        self::assertSame(1, $dom->query('//aside//a[@href="'.route('admin.settings').'"]')->length);
        self::assertSame(0, $dom->query('//header//a[@href="'.route('admin.settings').'"]')->length);
        self::assertSame(0, $dom->query('//aside//a[@href="'.route('admin.admin_data').'"]')->length);
        self::assertSame(1, $dom->query('//header//a[@href="'.route('admin.admin_data').'"]')->length);
        self::assertSame(1, $dom->query('//aside//a[@href="'.route('admin.feedback.index').'"]')->length);
        self::assertSame(0, $dom->query('//aside//a[@href="'.route('admin.contacts.index').'"]')->length);
        self::assertStringNotContainsString('اتصل بنا', $html);
    }

    public function test_native_support_sources_link_to_each_other_without_duplicate_inboxes(): void
    {
        $this->actingAs($this->dashboardUser('admin'), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        Livewire::test(ListFeedback::class)->assertSee('رسائل الموقع وطلبات الحذف')->assertSee(ContactResource::getUrl());
        Livewire::test(ListContacts::class)->assertSee('رسائل التطبيق')->assertSee(FeedbackResource::getUrl());
    }

    public function test_hosting_provider_placeholder_is_not_shipped_with_the_application(): void
    {
        self::assertFileDoesNotExist(base_path('default.html'));
    }

    public function test_retired_student_platform_dashboard_has_no_route_or_surface(): void
    {
        self::assertFalse(Route::has('admin.student-platform'));
        self::assertFileDoesNotExist(resource_path('views/admin/settings/student-platform.blade.php'));
        self::assertFileDoesNotExist(public_path('admin/assets/css/settings-student-platform.css'));
    }

    public function test_identity_editor_does_not_expose_unused_powered_by_fields(): void
    {
        $html = $this->adminGet($this->dashboardUser('admin'), ArtworkSettings::getUrl())->assertOk()->getContent();
        self::assertSame(0, $this->dom($html)->query('//*[@name="powered_by_titles" or @name="powered_by_urls"]')->length);
    }
}
