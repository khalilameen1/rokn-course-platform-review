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

        $this->withSession([$key => $message])
            ->get('/_test/admin-alert-feedback')
            ->assertOk()
            ->assertSeeText($heading)
            ->assertSeeText($message);
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
}
