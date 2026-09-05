<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CoinEarningMethodController;
use App\Http\Controllers\Admin\AdminNotificationsController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\CourseCodeController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Requests\Admin\AdminNotificationRequest;
use App\Models\AdminNotification;
use App\Models\RewardRule;
use App\Models\Package;
use App\Models\CourseCode;
use App\Models\Coupon;
use App\Models\Setting;
use App\Models\DesignSetting;
use App\Support\AdminSingletonLock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

final class AdminMutableConfigurationConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('reward_rules');
        Schema::dropIfExists('photos');
        Schema::dropIfExists('admin_notifications');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('course_code_usages');
        Schema::dropIfExists('course_codes');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('admin_singleton_locks');
        Schema::dropIfExists('settings');
        Schema::create('reward_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 64)->unique();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->unsignedInteger('coins_amount');
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->unsignedInteger('daily_cap')->nullable();
            $table->unsignedInteger('rolling_30_day_cap')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });
        Schema::create('admin_notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('system_key', 80)->nullable()->unique();
            $table->string('surface', 32)->default('announcement');
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->string('description_ar');
            $table->string('description_en')->nullable();
            $table->string('action_label_ar')->nullable();
            $table->string('action_label_en')->nullable();
            $table->string('secondary_action_label_ar')->nullable();
            $table->string('secondary_action_label_en')->nullable();
            $table->string('link')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_dismissible')->default(true);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedSmallInteger('cooldown_hours')->default(72);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->uuid('authoring_request_id')->nullable();
            $table->timestamps();
        });
        Schema::create('photos', function (Blueprint $table): void {
            $table->id();
            $table->string('path');
            $table->string('type');
            $table->unsignedBigInteger('photoable_id');
            $table->string('photoable_type');
            $table->timestamps();
        });
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('coins');
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->boolean('direct_enabled')->default(true);
            $table->string('google_product_id')->nullable()->unique();
            $table->string('apple_product_id')->nullable()->unique();
            $table->boolean('google_enabled')->default(false);
            $table->boolean('apple_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('course_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('type')->default('course');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->json('lesson_ids')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_grant')->default(false);
            $table->text('description')->nullable();
            $table->json('allowed_email_domains')->nullable();
            $table->timestamps();
        });
        Schema::create('course_code_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_code_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('code')->unique();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->unsignedInteger('balance');
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->boolean('active')->default(true);
            $table->uuid('authoring_request_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('admin_singleton_locks', function (Blueprint $table): void {
            $table->string('lock_key', 80)->primary();
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('reward_balance_cap')->default(1200);
            $table->unsignedInteger('recommended_provider_bonus_coins')->default(0);
            $table->timestamps();
        });
        Setting::query()->create([
            'reward_balance_cap' => 1200,
            'recommended_provider_bonus_coins' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('reward_rules');
        Schema::dropIfExists('photos');
        Schema::dropIfExists('admin_notifications');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('course_code_usages');
        Schema::dropIfExists('course_codes');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('admin_singleton_locks');
        Schema::dropIfExists('settings');
        parent::tearDown();
    }

    public function test_stale_reward_rule_form_cannot_overwrite_a_newer_admin_edit(): void
    {
        $rule = RewardRule::query()->create([
            'event_key' => 'course_completed',
            'title_ar' => 'إنهاء كورس',
            'title_en' => 'Course completion',
            'coins_amount' => 100,
            'interval_count' => 1,
            'rolling_30_day_cap' => 300,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $controller = app(CoinEarningMethodController::class);
        $staleVersion = $this->editorVersion($controller, $rule);

        $rule->update(['coins_amount' => 180, 'title_ar' => 'إكمال الكورس']);

        try {
            $controller->updateRewardRule(
                $this->updateRequest($staleVersion, 120),
                $rule
            );
            self::fail('A stale dashboard form must not overwrite a newer reward contract.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('editor_version', $exception->errors());
        }

        $rule->refresh();
        self::assertSame(180, $rule->coins_amount);
        self::assertSame('إكمال الكورس', $rule->title_ar);

        $controller->updateRewardRule(
            $this->updateRequest($this->editorVersion($controller, $rule), 220),
            $rule
        );

        self::assertSame(220, $rule->fresh()->coins_amount);
    }

    public function test_stale_reward_rule_delete_cannot_remove_a_newer_edit(): void
    {
        $rule = RewardRule::query()->create([
            'event_key' => 'welcome_bonus',
            'title_ar' => 'هدية التسجيل',
            'coins_amount' => 20,
            'interval_count' => 1,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $controller = app(CoinEarningMethodController::class);
        $staleVersion = $this->editorVersion($controller, $rule);
        $rule->update(['coins_amount' => 30]);

        try {
            $controller->destroyRewardRule(
                Request::create('/dashboard/reward-rules/'.$rule->id, 'DELETE', [
                    'editor_version' => $staleVersion,
                ]),
                $rule
            );
            self::fail('A stale dashboard card must not delete a newer reward contract.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('editor_version', $exception->errors());
        }

        self::assertDatabaseHas('reward_rules', [
            'id' => $rule->id,
            'coins_amount' => 30,
        ]);
    }

    public function test_stale_notification_template_form_cannot_replace_newer_copy(): void
    {
        $notification = AdminNotification::query()->create([
            'system_key' => 'course_completed',
            'surface' => 'transactional',
            'title_ar' => 'أتممت الكورس',
            'title_en' => 'Course completed',
            'description_ar' => 'شهادتك جاهزة',
            'description_en' => 'Your certificate is ready',
            'is_active' => true,
            'is_dismissible' => true,
            'priority' => 20,
            'cooldown_hours' => 0,
        ]);
        $controller = app(AdminNotificationsController::class);
        $version = new ReflectionMethod($controller, 'editorVersion');
        $version->setAccessible(true);
        $staleVersion = (string) $version->invoke($controller, $notification);

        $notification->update([
            'title_ar' => 'أنجزت الكورس',
            'description_ar' => 'اكتمل إنجازك وأصبحت الشهادة جاهزة',
        ]);

        $request = AdminNotificationRequest::create(
            '/dashboard/admin_notifications/'.$notification->id,
            'PATCH',
            [
                'system_key' => 'course_completed',
                'surface' => 'transactional',
                'title_ar' => 'اكتمل الكورس',
                'title_en' => 'Course completed',
                'description_ar' => 'افتح شهادتك',
                'description_en' => 'Open your certificate',
                'is_active' => '1',
                'is_dismissible' => '1',
                'priority' => 20,
                'cooldown_hours' => 0,
                'editor_version' => $staleVersion,
            ]
        );
        $route = new Route(['PATCH'], '/dashboard/admin_notifications/{admin_notification}', fn () => null);
        $route->bind($request);
        $route->setParameter('admin_notification', $notification);
        $request->setRouteResolver(static fn () => $route);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->validateResolved();

        try {
            $controller->update($request, $notification);
            self::fail('A stale notification editor must not replace newer copy.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('editor_version', $exception->errors());
        }

        $notification->refresh();
        self::assertSame('أنجزت الكورس', $notification->title_ar);
        self::assertSame('اكتمل إنجازك وأصبحت الشهادة جاهزة', $notification->description_ar);
    }

    public function test_stale_package_form_cannot_overwrite_live_pricing(): void
    {
        $package = Package::query()->create([
            'name_ar' => 'باقة 100',
            'name_en' => '100 coins',
            'price' => 120,
            'coins' => 100,
            'is_active' => true,
            'direct_enabled' => true,
            'google_enabled' => false,
            'apple_enabled' => false,
        ]);
        $controller = app(PackageController::class);
        $version = new ReflectionMethod($controller, 'editorVersion');
        $version->setAccessible(true);
        $staleVersion = (string) $version->invoke($controller, $package);

        $package->update(['price' => 150]);

        try {
            $controller->update(Request::create('/dashboard/packages/'.$package->id, 'PUT', [
                'name_ar' => 'باقة 100',
                'name_en' => '100 coins',
                'price' => 130,
                'coins' => 100,
                'is_active' => '1',
                'direct_enabled' => '1',
                'google_enabled' => '0',
                'apple_enabled' => '0',
                'editor_version' => $staleVersion,
            ]), $package);
            self::fail('A stale package editor must not replace newer live pricing.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('editor_version', $exception->errors());
        }

        self::assertSame('150.00', $package->fresh()->price);
    }

    public function test_stale_bulk_course_code_action_cannot_override_newer_state(): void
    {
        $code = CourseCode::query()->create([
            'code' => 'ORG-ONE',
            'name' => 'دفعة الجامعة',
            'type' => 'course',
            'max_uses' => 100,
            'is_active' => true,
        ]);
        $controller = app(CourseCodeController::class);
        $version = new ReflectionMethod($controller, 'editorVersion');
        $version->setAccessible(true);
        $staleVersion = (string) $version->invoke($controller, $code);
        DB::table('course_codes')->where('id', $code->id)->update(['name' => 'دفعة الجامعة الجديدة']);

        try {
            $controller->bulkAction(Request::create('/dashboard/course-codes/bulk-action', 'POST', [
                'action' => 'deactivate',
                'selected_codes' => [$code->id],
                'editor_versions' => [$code->id => $staleVersion],
            ]));
            self::fail('A stale bulk action must not override a newer course-code contract.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('editor_versions', $exception->errors());
        }

        self::assertTrue((bool) $code->fresh()->is_active);
        self::assertSame('دفعة الجامعة الجديدة', $code->fresh()->name);
    }

    public function test_coupon_editor_version_changes_when_its_image_changes(): void
    {
        $coupon = Coupon::query()->create([
            'name_ar' => 'خصم ترحيبي',
            'code' => 'WELCOME10',
            'balance' => 10,
            'expiry_date' => now()->addMonth(),
            'active' => true,
        ]);
        $controller = app(\App\Http\Controllers\Admin\CouponController::class);
        $version = new ReflectionMethod($controller, 'editorVersion');
        $version->setAccessible(true);
        $before = (string) $version->invoke($controller, $coupon);

        $coupon->allPhotos()->create(['path' => 'coupons/new.webp', 'type' => 'featured']);
        $coupon->unsetRelation('photo');
        $after = (string) $version->invoke($controller, $coupon);

        self::assertNotSame($before, $after);
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Http/Controllers/Admin/CouponController.php'
        );
        self::assertStringContainsString('storeTrackedUpload(', $source);
        self::assertStringContainsString("->lockForUpdate()->get()", $source);
        self::assertStringNotContainsString('$coupon->replaceImage(', $source);
    }

    public function test_bunny_secret_ciphertext_participates_in_settings_revision(): void
    {
        $controller = app(SettingsController::class);
        $version = new ReflectionMethod($controller, 'settingsEditorVersion');
        $version->setAccessible(true);
        $design = new DesignSetting();
        $first = new Setting();
        $first->setRawAttributes(['bunny_api_key_secret' => 'cipher-one'], true);
        $second = new Setting();
        $second->setRawAttributes(['bunny_api_key_secret' => 'cipher-two'], true);

        self::assertNotSame(
            $version->invoke($controller, $first, $design),
            $version->invoke($controller, $second, $design)
        );
    }

    public function test_singleton_lock_row_is_stable_and_dead_toggle_route_is_absent(): void
    {
        DB::transaction(function (): void {
            AdminSingletonLock::acquire('settings');
            AdminSingletonLock::acquire('settings');
        });

        self::assertSame(1, DB::table('admin_singleton_locks')->where('lock_key', 'settings')->count());
        self::assertNull(RouteFacade::getRoutes()->getByName('admin.coin-earning-methods.toggle-status'));
    }

    private function updateRequest(string $editorVersion, int $coins): Request
    {
        return Request::create('/dashboard/reward-rules/1', 'PUT', [
            'event_key' => 'course_completed',
            'title_ar' => 'إكمال الكورس',
            'title_en' => 'Course completion',
            'coins_amount' => $coins,
            'interval_count' => 1,
            'rolling_30_day_cap' => 300,
            'sort_order' => 10,
            'is_active' => '1',
            'editor_version' => $editorVersion,
        ]);
    }

    private function editorVersion(
        CoinEarningMethodController $controller,
        RewardRule $rule
    ): string {
        $method = new ReflectionMethod($controller, 'rewardRuleEditorVersion');
        $method->setAccessible(true);

        return (string) $method->invoke($controller, $rule);
    }
}
