<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminAlertFeedbackTest extends TestCase
{
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

    #[DataProvider('shellOwnedFlashViews')]
    public function test_admin_page_delegates_redirect_flash_to_the_shell(string $view): void
    {
        $source = file_get_contents(resource_path("views/admin/{$view}.blade.php"));

        self::assertIsString($source);
        self::assertStringContainsString("@extends('admin.layouts.app')", $source);
        self::assertStringNotContainsString("session('success')", $source);
        self::assertStringNotContainsString("session('error')", $source);
        self::assertStringNotContainsString("session('warning')", $source);
        self::assertStringNotContainsString('$errors->any()', $source);
    }

    public function test_shared_moderator_form_leaves_validation_to_its_admin_shell(): void
    {
        $source = file_get_contents(resource_path('views/admin/moderators/_form.blade.php'));

        self::assertIsString($source);
        self::assertStringNotContainsString('$errors->any()', $source);
        self::assertStringContainsString('name="name_ar"', $source);
    }

    public function test_validation_errors_remain_authoritative_over_success_flash(): void
    {
        Route::middleware('web')->get('/_test/admin-alert-validation', static fn () => view(
            'admin.includes.alert'
        ));

        $errors = (new \Illuminate\Support\ViewErrorBag())->put(
            'default',
            new \Illuminate\Support\MessageBag(['name' => 'الاسم مطلوب'])
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
    public static function shellOwnedFlashViews(): array
    {
        return [
            'notification templates' => ['admin_notifications/index'],
            'support inbox' => ['feedback/index'],
            'support case' => ['feedback/show'],
            'contact message' => ['contacts/show'],
            'grade create' => ['grades/create'],
            'grade edit' => ['grades/edit'],
            'payment reconciliation' => ['payment-reconciliation-findings/index'],
            'notification create' => ['notifications/create'],
            'students' => ['users/index'],
            'operating costs' => ['operating-costs/index'],
        ];
    }
}
