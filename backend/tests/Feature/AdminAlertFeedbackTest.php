<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ModeratorResource;
use App\Filament\Resources\ModeratorResource\Pages\CreateModerator;
use App\Filament\Resources\NotificationCampaignResource;
use App\Filament\Resources\NotificationTemplateResource;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Contact;
use App\Models\FeedbackReport;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminAlertFeedbackTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('feedbackProvider')]
    public function test_admin_shell_renders_redirect_feedback(
        string $key,
        string $message,
        string $heading
    ): void {
        Route::middleware('web')->get('/_test/admin-alert-feedback', static fn () => view(
            'admin.includes.alert'
        ));

        $response = $this->withSession([$key => $message])
            ->get('/_test/admin-alert-feedback');

        $response->assertOk()
            ->assertSeeText($heading)
            ->assertSeeText($message);
        self::assertSame(1, substr_count(strip_tags($response->getContent()), $message));
    }

    #[DataProvider('shellOwnedFlashPages')]
    public function test_retired_notification_shell_pages_redirect_to_native_workspaces(string $page): void
    {
        $url = $this->pageUrl($page);
        $this->get($url)->assertRedirect($page === 'admin.notifications.create'
            ? NotificationCampaignResource::getUrl('create') : NotificationTemplateResource::getUrl());
    }

    public function test_moderator_form_uses_native_field_validation(): void
    {
        $this->actingAsAdministrator();
        $this->get(route('admin.moderators.create'))->assertRedirect(ModeratorResource::getUrl('create'));
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        Livewire::test(CreateModerator::class)
            ->fillForm(['name_ar' => '', 'email' => '', 'password' => ''])->call('create')
            ->assertHasFormErrors(['name_ar' => 'required', 'email' => 'required', 'password' => 'required']);
    }

    public function test_validation_errors_remain_authoritative_over_success_flash(): void
    {
        Route::middleware('web')->get('/_test/admin-alert-validation', static fn () => view(
            'admin.includes.alert'
        ));

        $errors = (new ViewErrorBag)->put(
            'default',
            new MessageBag(['name' => 'الاسم مطلوب'])
        );
        $response = $this->withSession([
            'success' => 'تم الحفظ',
            'errors' => $errors,
        ])
            ->get('/_test/admin-alert-validation');

        $response->assertOk()
            ->assertSeeText('حدثت أخطاء في النموذج')
            ->assertSeeText('الاسم مطلوب')
            ->assertDontSeeText('تم الحفظ');
    }

    /** @return array<string, array{string, string, string}> */
    public static function feedbackProvider(): array
    {
        return [
            'error' => ['error', 'تعذر تنفيذ العملية', 'خطأ'],
            'warning' => ['warning', 'الفيديو يعمل ويحتاج مراجعة', 'يحتاج متابعة'],
            'success' => ['success', 'تم حفظ التغيير', 'تم بنجاح'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function shellOwnedFlashPages(): array
    {
        return [
            'notification templates' => ['admin.admin_notifications.index'],
            'notification create' => ['admin.notifications.create'],
        ];
    }

    private function pageUrl(string $page): string
    {
        $this->actingAsAdministrator();

        return match ($page) {
            'admin.feedback.show' => route($page, FeedbackReport::query()->create([
                'public_id' => (string) Str::ulid(),
                'category' => 'bug',
                'status' => 'new',
                'priority' => 'normal',
                'message' => 'يتوقف التطبيق عند فتح صفحة الحساب',
                'version' => 1,
            ])),
            'admin.contacts.show' => route($page, Contact::query()->create([
                'name' => 'Learner',
                'email' => 'contact-flash@rokn.test',
                'phone' => '201000000000',
                'message' => 'رسالة تواصل',
                'read' => false,
            ])),
            default => route($page),
        };
    }

    private function actingAsAdministrator(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $admin = User::query()->where('email', 'alert-admin@rokn.test')->first()
            ?? User::query()->forceCreate([
                'name' => 'Alert Admin',
                'email' => 'alert-admin@rokn.test',
                'role' => 'admin',
                'active' => true,
            ]);
        $this->actingAs($admin, 'web');
    }
}
