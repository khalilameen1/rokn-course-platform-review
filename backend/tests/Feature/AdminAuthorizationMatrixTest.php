<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminPermissionMatrix;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AdminAuthorizationMatrixTest extends TestCase
{
    public function test_moderator_permissions_are_an_explicit_fail_closed_allow_list(): void
    {
        $matrix = app(AdminPermissionMatrix::class);

        self::assertTrue($matrix->allows('moderator', 'admin.courses.index', 'GET'));
        self::assertTrue($matrix->allows('moderator', 'admin.courses.draft.start', 'POST'));
        self::assertTrue($matrix->allows('moderator', 'admin.courses.update', 'PATCH'));
        self::assertTrue($matrix->allows('moderator', 'admin.courses.sections.update', 'PATCH'));
        self::assertTrue($matrix->allows('moderator', 'admin.classifications.index', 'GET'));
        self::assertTrue($matrix->allows('moderator', 'admin.classifications.update', 'PATCH'));
        self::assertFalse($matrix->allows('moderator', 'admin.classifications.destroy', 'DELETE'));
        self::assertTrue($matrix->allows('moderator', 'admin.admin_data', 'GET'));
        self::assertTrue($matrix->allows('moderator', 'admin.update_admin_data', 'POST'));

        self::assertTrue($matrix->allows('moderator', 'admin.project-submissions.reject', 'POST'));
        self::assertFalse($matrix->allows('moderator', 'admin.settings', 'GET'));
        self::assertFalse($matrix->allows('moderator', 'admin.payment-reconciliation-findings.index', 'GET'));
        self::assertFalse($matrix->allows('moderator', 'admin.payment-reconciliation-findings.resolve', 'PATCH'));
        self::assertFalse($matrix->allows('moderator', 'admin.payment-reconciliation-findings.ignore', 'PATCH'));
        self::assertFalse($matrix->allows('moderator', 'admin.payment-reconciliation-findings.reopen', 'PATCH'));
        self::assertFalse($matrix->allows('moderator', 'admin.future-route', 'GET'));
        self::assertFalse($matrix->allows('moderator', 'admin.project-submissions.future-action', 'POST'));
        self::assertFalse($matrix->allows('moderator', null, 'GET'));
        self::assertFalse($matrix->allows('client', 'admin.courses.index', 'GET'));

        self::assertTrue($matrix->allows('admin', 'admin.future-route', 'DELETE'));
        self::assertTrue($matrix->allowsCapability('admin', AdminPermissionMatrix::CONTENT_CURATION));
        self::assertTrue($matrix->allowsCapability('moderator', AdminPermissionMatrix::CONTENT_CURATION));
        self::assertFalse($matrix->allowsCapability('client', AdminPermissionMatrix::CONTENT_CURATION));
        self::assertFalse($matrix->allowsCapability('moderator', AdminPermissionMatrix::ACCOUNT_CREDENTIALS));
        self::assertFalse($matrix->allowsCapability('admin', 'unknown.capability'));
    }

    public function test_sensitive_routes_require_an_administrator_and_all_dashboard_routes_require_mfa(): void
    {
        foreach ([
            'admin.settings',
            'admin.orders.index',
            'admin.courses.destroy',
            'admin.student-progress.index',
            'admin.users.index',
            'admin.product-operations.features.update',
            'admin.payment-reconciliation-findings.index',
            'admin.payment-reconciliation-findings.resolve',
            'admin.payment-reconciliation-findings.ignore',
            'admin.payment-reconciliation-findings.reopen',
            'admin.moderators.index',
            'admin.operating-costs.index',
            'admin.operating-costs.store',
            'admin.operating-costs.report',
            'admin.operating-costs.report.export',
            'admin.courses.commercial-report.export',
            'admin.notifications.retry',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, "Missing expected route {$name}");
            self::assertContains('admin.only', $route->gatherMiddleware(), "{$name} must require admin.only");
            self::assertContains('admin.mfa', $route->gatherMiddleware(), "{$name} must require admin MFA");
        }

        $moderatorRoute = Route::getRoutes()->getByName('admin.courses.update');
        self::assertNotNull($moderatorRoute);
        self::assertNotContains('admin.only', $moderatorRoute->gatherMiddleware());
        self::assertContains('admin.mfa', $moderatorRoute->gatherMiddleware());

        $startDraftRoute = Route::getRoutes()->getByName('admin.courses.draft.start');
        self::assertNotNull($startDraftRoute);
        self::assertNotContains('admin.only', $startDraftRoute->gatherMiddleware());
        self::assertContains('admin.mfa', $startDraftRoute->gatherMiddleware());

        $contentReviewRoute = Route::getRoutes()->getByName('admin.project-submissions.index');
        self::assertNotNull($contentReviewRoute);
        self::assertNotContains('admin.only', $contentReviewRoute->gatherMiddleware());
        self::assertContains('admin.mfa', $contentReviewRoute->gatherMiddleware());
    }

    public function test_every_moderator_matrix_entry_names_a_real_route_and_method(): void
    {
        $matrix = app(AdminPermissionMatrix::class);

        foreach ($matrix->moderatorRules() as $name => $allowedMethods) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, "Stale moderator permission: {$name}");
            $routeMethods = array_values(array_diff($route->methods(), ['HEAD']));

            foreach ($allowedMethods as $method) {
                self::assertContains($method, $routeMethods, "{$name} does not support {$method}");
            }
            self::assertNotContains(
                'admin.only',
                $route->gatherMiddleware(),
                "Contradictory moderator permission: {$name} is also administrator-only"
            );
        }
    }

    public function test_every_dashboard_route_is_either_explicitly_moderated_or_administrator_only(): void
    {
        $matrix = app(AdminPermissionMatrix::class);
        $mfaBootstrapRoutes = [
            'admin.mfa.setup',
            'admin.mfa.setup.confirm',
            'admin.mfa.challenge',
            'admin.mfa.challenge.verify',
        ];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if (!is_string($name) || !str_starts_with($name, 'admin.')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            self::assertContains('admin', $middleware, "{$name} must require dashboard authentication");
            if (!in_array($name, $mfaBootstrapRoutes, true)) {
                self::assertContains('admin.mfa', $middleware, "{$name} must require dashboard MFA");
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                self::assertTrue(
                    in_array('admin.only', $middleware, true)
                        || $matrix->allows('moderator', $name, $method),
                    "{$method} {$name} is neither administrator-only nor in the moderator allow-list"
                );
            }
        }
    }

    public function test_moderator_navigation_does_not_render_administrator_only_links(): void
    {
        $moderator = new User(['name' => 'Content Moderator']);
        $moderator->role = 'moderator';
        $this->app['auth']->guard()->setUser($moderator);

        $html = view('admin.includes.aside')->render();

        self::assertStringContainsString(route('admin.courses.index'), $html);
        self::assertStringContainsString('مساحة المحتوى', $html);
        self::assertStringContainsString('صناعة الكورس', $html);
        self::assertStringContainsString(route('admin.teachers.index'), $html);
        self::assertStringContainsString(route('admin.classifications.index'), $html);
        self::assertStringContainsString('صفوف الرئيسية', $html);
        self::assertStringNotContainsString(route('admin.levels.index'), $html);
        self::assertStringNotContainsString(route('admin.paths.index'), $html);
        self::assertStringNotContainsString(route('admin.settings'), $html);
        self::assertStringNotContainsString(route('admin.orders.index'), $html);
        self::assertStringNotContainsString(route('admin.payment-reconciliation-findings.index'), $html);
        self::assertStringNotContainsString(route('admin.users.index'), $html);
        self::assertStringNotContainsString(route('admin.student-progress.index'), $html);
        self::assertStringNotContainsString(route('admin.feedback.index'), $html);
        self::assertStringNotContainsString(route('admin.contacts.index'), $html);
        self::assertStringNotContainsString(route('admin.operating-costs.index'), $html);
        self::assertStringNotContainsString(route('admin.notifications.index'), $html);
        self::assertStringContainsString(route('admin.project-submissions.index'), $html);
    }

    public function test_administrator_navigation_normalizes_legacy_role_case(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->boolean('read')->default(false);
        });
        $administrator = new User(['name' => 'Rokn Owner']);
        $administrator->role = 'Admin';
        $this->app['auth']->guard()->setUser($administrator);

        try {
            $html = view('admin.includes.aside')->render();

            self::assertStringContainsString(route('admin.dashboard'), $html);
            self::assertStringContainsString(route('admin.settings'), $html);
            self::assertStringContainsString(route('admin.orders.index'), $html);
            self::assertStringContainsString(route('admin.users.index'), $html);
            self::assertStringNotContainsString('مساحة المحتوى', $html);
        } finally {
            Schema::dropIfExists('contacts');
        }
    }
}
