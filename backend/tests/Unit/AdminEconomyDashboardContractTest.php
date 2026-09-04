<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminEconomyDashboardContractTest extends TestCase
{
    public function test_package_and_reward_lists_have_explicit_bounds(): void
    {
        $read = $this->source('app/Services/AdminEconomyReadService.php');

        self::assertStringContainsString('->paginate(12)', $read);
        self::assertStringContainsString('->paginate(30)', $read);
        self::assertStringContainsString('->limit(count($rewardEvents))', $read);
    }

    public function test_create_and_edit_screens_share_one_form_contract(): void
    {
        $packageCreate = $this->source('resources/views/admin/packages/create.blade.php');
        $packageEdit = $this->source('resources/views/admin/packages/edit.blade.php');
        $rewardCreate = $this->source('resources/views/admin/coin_earning_methods/create.blade.php');
        $rewardEdit = $this->source('resources/views/admin/coin_earning_methods/edit.blade.php');

        self::assertStringContainsString("@include('admin.packages._form'", $packageCreate);
        self::assertStringContainsString("@include('admin.packages._form'", $packageEdit);
        self::assertStringContainsString("@include('admin.coin_earning_methods._form'", $rewardCreate);
        self::assertStringContainsString("@include('admin.coin_earning_methods._form'", $rewardEdit);
    }

    public function test_course_plan_business_validation_has_one_runtime_owner(): void
    {
        $request = $this->source('app/Http/Requests/Admin/CourseRequest.php');
        $plans = $this->source('app/Services/CourseAccessPlanService.php');

        self::assertStringNotContainsString('function withValidator', $request);
        self::assertStringContainsString('function syncAdminPlans', $plans);
        self::assertStringContainsString("'يجب إرسال المستويات الثلاثة كاملة", $plans);
        self::assertStringContainsString("'سعر كل مستوى يجب أن يساوي", $plans);
        self::assertStringContainsString("'الفئة ذات التكلفة المتغيرة تحتاج حدًا مدفوعًا", $plans);
    }

    public function test_permissions_keep_platform_economy_admin_only_and_course_offers_authorable(): void
    {
        $routes = $this->source('routes/web.php');

        self::assertStringContainsString(
            "Route::resource('packages', 'PackageController')->middleware('admin.only')",
            $routes
        );
        self::assertStringContainsString(
            "Route::resource('coin-earning-methods', 'CoinEarningMethodController')",
            $routes
        );
        self::assertStringContainsString(
            "Route::resource('courses', 'CourseController')->only(['create', 'store'])",
            $routes
        );
    }

    public function test_only_packages_with_an_executable_channel_reach_the_student(): void
    {
        $model = $this->source('app/Models/Package.php');
        $admin = $this->source('app/Http/Controllers/Admin/PackageController.php');
        $api = $this->source('app/Http/Controllers/API/PackageController.php');
        $purchase = $this->source('app/Http/Controllers/API/CoursePurchaseController.php');
        $upgrade = $this->source('app/Http/Controllers/API/CourseChatUpgradeController.php');
        $pricing = $this->source('app/Services/PackageChannelPricingService.php');

        self::assertStringContainsString('scopePurchasable', $model);
        self::assertStringContainsString('hasPurchasableChannel', $model);
        self::assertStringContainsString('availableChannels', $model);
        self::assertStringContainsString("'channels' => ['فعّل كاشير", $admin);
        self::assertSame(2, substr_count($api, '->purchasable()'));
        self::assertStringContainsString('->purchasable()', $purchase);
        self::assertStringContainsString('->purchasable()', $upgrade);
        self::assertStringContainsString('$channels = $package->availableChannels()', $pricing);
    }

    public function test_a_broken_task_can_be_disabled_and_preview_uses_the_public_contract(): void
    {
        $controller = $this->source('app/Http/Controllers/Admin/CoinEarningMethodController.php');
        $model = $this->source('app/Models/CoinEarningMethod.php');
        $list = $this->source('resources/views/admin/coin_earning_methods/index.blade.php');

        self::assertStringContainsString('if (!$method->is_active)', $controller);
        self::assertStringContainsString('PublicAppSettingsService::class', $model);
        self::assertStringContainsString('$method->learnerTitleAr()', $list);
        self::assertStringContainsString('$method->resolvedActionUrl()', $list);
    }

    public function test_learner_tasks_have_one_valid_catalogue_and_dashboard_order(): void
    {
        $model = $this->source('app/Models/CoinEarningMethod.php');
        $api = $this->source('app/Http/Controllers/API/CoinEarningMethodController.php');
        $engagement = $this->source('app/Http/Controllers/API/EngagementController.php');
        $admin = $this->source('app/Http/Controllers/Admin/CoinEarningMethodController.php');
        $read = $this->source('app/Services/AdminEconomyReadService.php');

        self::assertStringContainsString('scopeLearnerTask', $model);
        self::assertSame(2, substr_count($api, 'CoinEarningMethod::learnerTask()'));
        self::assertStringContainsString('->learnerTask()', $engagement);
        self::assertStringContainsString("->orderBy('sort_order')", $engagement);
        self::assertStringContainsString("'coins_amount' => ['required', 'integer', 'min:1']", $admin);
        self::assertStringContainsString("'action_key' => ['required', 'string', 'max:255', 'not_in:register']", $admin);
        self::assertStringContainsString("orWhere('action_key', '!=', 'register')", $read);
    }

    public function test_one_time_task_receipt_is_enforced_by_the_database(): void
    {
        $migration = $this->source(
            'database/migrations/2026_09_04_000018_enforce_one_coin_task_earning_per_user.php'
        );

        self::assertStringContainsString(
            "['user_id', 'coin_earning_method_id']",
            $migration
        );
        self::assertStringContainsString('coin_earning_once_per_user', $migration);
    }

    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$relative);
        self::assertIsString($source, $relative);

        return $source;
    }
}
