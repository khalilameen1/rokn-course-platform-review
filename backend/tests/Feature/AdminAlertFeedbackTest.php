<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Contact;
use App\Models\FeedbackReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
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
    public function test_admin_page_shows_each_redirect_flash_once_through_the_shell(string $page): void
    {
        $url = $this->pageUrl($page);

        foreach (['success', 'error', 'warning'] as $key) {
            $this->flushSession();
            $this->actingAsAdministrator();
            $message = "رسالة {$key} للاختبار";
            $content = (string) $this->withSession([$key => $message])->get($url)->assertOk()->getContent();

            self::assertSame(1, substr_count($content, $message), "{$page} rendered {$key} flash more than once");
        }

        $this->flushSession();
        $this->actingAsAdministrator();
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['name' => 'خطأ تحقق للاختبار']));
        $content = (string) $this->withSession(['errors' => $errors])->get($url)->assertOk()->getContent();
        self::assertSame(1, substr_count($content, 'حدثت أخطاء في النموذج'), "{$page} rendered a second error summary");
        self::assertSame(1, substr_count($content, 'خطأ تحقق للاختبار'), "{$page} rendered validation errors twice");
    }

    public function test_shared_moderator_form_leaves_validation_to_its_admin_shell(): void
    {
        $this->actingAsAdministrator();
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['name_ar' => 'اسم المشرف مطلوب']));

        $content = (string) $this->withSession(['errors' => $errors])
            ->get(route('admin.moderators.create'))
            ->assertOk()
            ->assertSee('name="name_ar"', false)
            ->getContent();

        self::assertSame(1, substr_count($content, 'حدثت أخطاء في النموذج'));
        self::assertSame(1, substr_count($content, 'اسم المشرف مطلوب'));
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
            'support inbox' => ['admin.feedback.index'],
            'support case' => ['admin.feedback.show'],
            'contact message' => ['admin.contacts.show'],
            'payment reconciliation' => ['admin.payment-reconciliation-findings.index'],
            'notification create' => ['admin.notifications.create'],
            'students' => ['admin.users.index'],
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
