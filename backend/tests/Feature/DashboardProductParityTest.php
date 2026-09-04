<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DashboardProductParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('classifications');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('settings');
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('tokens_number')->nullable();
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
        Schema::create('classifications', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('classifications');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('settings');
        parent::tearDown();
    }

    public function test_home_merchandising_preserves_existing_rows_and_keeps_new_rows_opt_in(): void
    {
        $existingId = DB::table('classifications')->insertGetId([
            'name_ar' => 'الأكثر مشاهدة',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_07_000031_add_home_merchandising_controls.php');
        $migration->up();

        self::assertSame(1, (int) DB::table('classifications')->where('id', $existingId)->value('show_on_home'));

        $newId = DB::table('classifications')->insertGetId([
            'name_ar' => 'صف جديد',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::assertSame(0, (int) DB::table('classifications')->where('id', $newId)->value('show_on_home'));

    }

    public function test_dashboard_product_controls_migration_is_reversible_and_defaults_chat_on(): void
    {
        $migration = require database_path('migrations/2026_08_07_000020_add_dashboard_product_controls.php');
        $migration->up();

        foreach ([
            'ai_daily_user_limit',
            'ai_global_daily_request_limit',
            'ai_global_daily_token_budget',
            'ai_global_monthly_token_budget',
        ] as $column) {
            self::assertTrue(Schema::hasColumn('settings', $column));
        }
        self::assertTrue(Schema::hasColumn('courses', 'ai_chat_enabled'));

        $courseId = DB::table('courses')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        self::assertSame(1, (int) DB::table('courses')->where('id', $courseId)->value('ai_chat_enabled'));

        $migration->down();
        self::assertFalse(Schema::hasColumn('courses', 'ai_chat_enabled'));
        self::assertFalse(Schema::hasColumn('settings', 'ai_daily_user_limit'));
    }

    public function test_sensitive_product_and_notification_routes_are_admin_only(): void
    {
        foreach ([
            'admin.product-operations.index',
            'admin.notifications.index',
            'admin.notifications.store',
            'admin.notifications.retry',
            'admin.settings.bunny-cleanup.approve',
            'admin.settings.bunny-cleanup.approve-batch',
        ] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertContains('admin.only', $route->gatherMiddleware(), $name);
        }
    }

    public function test_retired_standalone_learning_dashboards_have_no_routes(): void
    {
        foreach ([
            'admin.lessons.index',
            'admin.lessons.create',
            'admin.lessons.store',
            'admin.lessons.show',
            'admin.lessons.edit',
            'admin.lessons.update',
            'admin.lessons.destroy',
        ] as $name) {
            self::assertNull(app('router')->getRoutes()->getByName($name), $name);
        }
    }

    public function test_dashboard_mutations_never_use_get_and_are_audited(): void
    {
        $expectations = [
            'admin.teachers.deactive' => ['PATCH'],
            'admin.users.deactive' => ['PATCH'],
            'admin.contacts.read' => ['POST'],
        ];

        foreach ($expectations as $name => $methods) {
            $route = app('router')->getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertSame($methods, $route->methods(), $name);
            self::assertContains('web', $route->gatherMiddleware(), $name);
            self::assertContains('admin', $route->gatherMiddleware(), $name);
            self::assertContains('admin.audit', $route->gatherMiddleware(), $name);
        }

        foreach (['admin.users.deactive', 'admin.contacts.read'] as $name) {
            self::assertContains(
                'admin.only',
                app('router')->getRoutes()->getByName($name)->gatherMiddleware(),
                $name
            );
        }
    }

    public function test_legacy_numeric_profile_route_cannot_enumerate_accounts_and_share_links_are_unlisted(): void
    {
        $first = $this->get('/profile/1');
        $missing = $this->get('/profile/999999999');

        $first->assertNotFound();
        $missing->assertNotFound();
        self::assertSame($first->getContent(), $missing->getContent());

        $private = new User([
            'portfolio_slug' => 'private-student',
        ]);
        self::assertSame(
            rtrim((string) config('public_links.base_url'), '/').'/@private-student',
            $private->profile_deeplink
        );

        $public = new User([
            'portfolio_slug' => 'published-student',
        ]);
        self::assertSame(
            rtrim((string) config('public_links.base_url'), '/').'/@published-student',
            $public->profile_deeplink
        );
    }
}
