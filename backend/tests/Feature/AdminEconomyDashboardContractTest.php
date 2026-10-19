<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CoinEarningMethodResource;
use App\Filament\Resources\CoinEarningMethodResource\Pages\CreateCoinEarningMethod;
use App\Filament\Resources\CoinEarningMethodResource\Pages\EditCoinEarningMethod;
use App\Filament\Resources\CoinEarningMethodResource\Pages\ListCoinEarningMethods;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\PackageResource\Pages\CreatePackage;
use App\Filament\Resources\PackageResource\Pages\EditPackage;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\RewardRuleResource\Pages\ListRewardRules;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\CoinEarningMethod;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\DesignSetting;
use App\Models\Package;
use App\Models\RewardRule;
use App\Models\User;
use App\Services\CoursePlanAuthoringService;
use App\Services\PublicAppSettingsService;
use App\Support\RewardConfigurationVersion;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class AdminEconomyDashboardContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->admin = $this->dashboardUser('admin');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_package_and_reward_lists_have_explicit_bounds(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->package(['name_ar' => 'باقة '.$i]);
        }
        for ($i = 0; $i < 13; $i++) {
            $this->method(['action_key' => 'bounded_task_'.$i, 'title_ar' => 'مهمة '.$i]);
        }

        $this->actingAs($this->admin, 'web')->get(route('admin.packages.index'))->assertRedirect(PackageResource::getUrl());
        $packages = Livewire::test(ListPackages::class)->instance()->getTableRecords();
        self::assertSame(30, $packages->perPage());
        self::assertCount(30, $packages->items());

        $methods = Livewire::test(ListCoinEarningMethods::class)->set('tableRecordsPerPage', 10)->instance()->getTableRecords();
        self::assertSame(10, $methods->perPage());
        self::assertCount(10, $methods->items());
        self::assertGreaterThan(10, $methods->total());
        $rules = Livewire::test(ListRewardRules::class)->instance()->getTableRecords();
        self::assertLessThanOrEqual(count(RewardRule::EVENTS), $rules->total());
        self::assertSame([], $rules->pluck('event_key')->diff(array_keys(RewardRule::EVENTS))->values()->all());
    }

    public function test_create_and_edit_screens_share_one_form_contract(): void
    {
        $package = $this->package([]);
        $method = $this->method(['action_key' => 'shared_form_task']);

        $this->actingAs($this->admin, 'web');
        $create = Livewire::test(CreatePackage::class);
        $edit = Livewire::test(EditPackage::class, ['record' => $package->id]);
        foreach (PackageResource::draftFields() as $field) {
            $create->assertFormFieldExists($field);
            $edit->assertFormFieldExists($field);
        }

        $create = Livewire::test(CreateCoinEarningMethod::class);
        $edit = Livewire::test(EditCoinEarningMethod::class, ['record' => $method->id]);
        foreach (CoinEarningMethodResource::draftFields() as $field) {
            $create->assertFormFieldExists($field);
            $edit->assertFormFieldExists($field);
        }
    }

    public function test_course_plan_business_validation_has_one_runtime_owner(): void
    {
        // The variable-cost minimum-paid rule is proven in
        // CoursePlanOwnershipTest::test_invalid_last_tier_rolls_back_the_entire_offer_edit_and_keeps_the_receipt.
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس الخطط', 'price' => 900,
            'authoring_version' => 1, 'is_coming_soon' => true, 'is_catalog_visible' => false,
        ]);
        $authoring = app(CoursePlanAuthoringService::class);
        $authoring->createDefaults($course);
        $input = $course->accessPlans()->get()->mapWithKeys(fn (CourseAccessPlan $plan): array => [
            $plan->code => [
                'name_ar' => $plan->name_ar, 'name_en' => $plan->name_en,
                'price_coins' => $plan->price_coins, 'minimum_paid_coins' => $plan->minimum_paid_coins,
                'is_active' => true, 'certificate_enabled' => $plan->certificate_enabled,
            ],
        ])->all();
        $before = $course->accessPlans()->orderBy('id')->get()->toArray();

        $missingTier = $input;
        unset($missingTier[CourseAccessPlan::MENTOR]);
        $descending = $input;
        $descending[CourseAccessPlan::GUIDED]['price_coins'] = $input[CourseAccessPlan::BASIC]['price_coins'] - 1;
        foreach ([
            'يجب إرسال المستويات الثلاثة كاملة في عملية حفظ واحدة.' => $missingTier,
            'سعر كل مستوى يجب أن يساوي أو يزيد عن المستوى الذي قبله.' => $descending,
        ] as $message => $plans) {
            try {
                $authoring->syncAdminPlans($course, $plans);
                self::fail('Invalid course plans must be refused: '.$message);
            } catch (ValidationException $exception) {
                self::assertSame(['access_plans' => [$message]], $exception->errors());
            }
        }
        self::assertSame($before, $course->accessPlans()->orderBy('id')->get()->toArray());
    }

    public function test_permissions_keep_platform_economy_admin_only_and_course_offers_authorable(): void
    {
        // Moderators opening admin.courses.create is exercised in
        // AdminDailyPagesRuntimeTest::test_moderator_daily_course_pages_render_without_financial_data.
        $moderator = $this->dashboardUser('moderator');
        foreach ([PackageResource::getUrl(), PackageResource::getUrl('create'), CoinEarningMethodResource::getUrl(), CoinEarningMethodResource::getUrl('create')] as $url) {
            // Each identity gets its own session; a pinned admin session
            // would otherwise log the moderator out instead of refusing.
            $this->flushSession();
            $this->actingAs($this->admin, 'web')->get($url)->assertOk();
            $this->flushSession();
            $this->actingAs($moderator, 'web')->get($url)->assertForbidden();
        }
        foreach (['admin.packages.store', 'admin.coin-earning-methods.store'] as $write) {
            self::assertContains('admin.only', Route::getRoutes()->getByName($write)->gatherMiddleware(), $write);
        }
        self::assertNotContains('admin.only', Route::getRoutes()->getByName('admin.courses.store')->gatherMiddleware());
    }

    public function test_only_packages_with_an_executable_channel_reach_the_student(): void
    {
        // Admin activation without a channel is refused in
        // AdminPackageAuthoringOwnershipTest::test_both_create_and_update_require_an_executable_channel_only_when_active;
        // checkout recommendations are filtered in
        // CourseRewardContributionCapTest::test_an_unfunded_purchase_debits_nothing_and_offers_only_purchasable_packages.
        $direct = $this->package(['name_ar' => 'باقة مباشرة', 'direct_enabled' => true]);
        $google = $this->package([
            'name_ar' => 'باقة جوجل', 'direct_enabled' => false,
            'google_enabled' => true, 'google_product_id' => 'rokn.coins.google',
        ]);
        $googleWithoutSku = $this->package(['name_ar' => 'جوجل بلا منتج', 'direct_enabled' => false, 'google_enabled' => true]);
        $channelless = $this->package(['name_ar' => 'باقة بلا قناة', 'direct_enabled' => false]);
        Cache::flush();

        $catalogue = collect($this->getJson('/api/v1/packages')->assertOk()->json('data'))->keyBy('id');
        self::assertEqualsCanonicalizing([$direct->id, $google->id], $catalogue->keys()->all());
        self::assertSame(['direct' => true, 'google' => false, 'apple' => false], $catalogue[$direct->id]['channels']);
        self::assertSame(['direct' => false, 'google' => true, 'apple' => false], $catalogue[$google->id]['channels']);
        self::assertNull($catalogue[$google->id]['direct_price']);
        self::assertSame('rokn.coins.google', $catalogue[$google->id]['store_products']['google']);

        $this->getJson('/api/v1/packages/'.$google->id)->assertOk()->assertJsonPath('data.channels.google', true);
        foreach ([$googleWithoutSku, $channelless] as $hidden) {
            $this->getJson('/api/v1/packages/'.$hidden->id)->assertNotFound();
        }
    }

    public function test_a_broken_task_can_be_disabled_and_preview_uses_the_public_contract(): void
    {
        $broken = $this->method([
            'action_key' => 'custom_external_task', 'title_ar' => 'مهمة برابط معطل',
            'requires_external_visit' => true, 'action_url' => null,
        ]);
        $form = fn (bool $active): array => [
            'title_ar' => 'مهمة برابط معطل', 'title_en' => 'Broken link task', 'coins_amount' => 10,
            'action_key' => 'custom_external_task', 'requires_external_visit' => '1',
            'is_active' => $active ? '1' : '0',
            'editor_version' => RewardConfigurationVersion::method($broken->fresh()),
        ];

        $this->actingAs($this->admin, 'web')
            ->from(route('admin.coin-earning-methods.edit', $broken))
            ->put(route('admin.coin-earning-methods.update', $broken), $form(true))
            ->assertSessionHasErrors('action_url');
        self::assertTrue($broken->fresh()->is_active);
        $this->flushSession();
        $this->actingAs($this->admin, 'web')
            ->put(route('admin.coin-earning-methods.update', $broken), $form(false))
            ->assertRedirect(route('admin.coin-earning-methods.index'))
            ->assertSessionHasNoErrors();
        self::assertFalse($broken->fresh()->is_active);

        // A social task without its own URL previews the account configured in
        // the public app settings, with the learner-facing title.
        DesignSetting::getDefaultSettings()->forceFill(['instagram_url' => 'https://instagram.com/rokn.app'])->save();
        PublicAppSettingsService::invalidate();
        $social = $this->method([
            'action_key' => 'follow_instagram', 'title_ar' => 'افتح حساب ركن على Instagram',
            'requires_external_visit' => true, 'action_url' => null, 'sort_order' => 0,
        ]);
        Livewire::test(ListCoinEarningMethods::class)->searchTable('follow_instagram')->assertSee('تابعنا على إنستجرام');
        Livewire::test(EditCoinEarningMethod::class, ['record' => $social->id])->assertSee('https://instagram.com/rokn.app');
    }

    public function test_learner_tasks_have_one_valid_catalogue_and_dashboard_order(): void
    {
        // The learner catalogue order and exclusion of broken or automatic
        // rows are exercised over HTTP in
        // EngagementExperienceTest::test_wallet_and_next_offer_share_dashboard_order_and_skip_broken_legacy_tasks
        // and RewardsProjectionTest::test_registration_and_welcome_are_not_manual_tasks.
        foreach ([['coins_amount' => 0], ['action_key' => 'register'], ['action_key' => 'welcome_bonus']] as $invalid) {
            $this->flushSession();
            $field = array_key_first($invalid);
            $this->actingAs($this->admin, 'web')
                ->from(route('admin.coin-earning-methods.create'))
                ->post(route('admin.coin-earning-methods.store'), [
                    'authoring_request_id' => (string) Str::uuid(),
                    'title_ar' => 'مهمة مرفوضة', 'title_en' => 'Rejected task', 'coins_amount' => 10,
                    'action_key' => 'valid_task_key', 'requires_external_visit' => '0', 'is_active' => '1',
                    ...$invalid,
                ])
                ->assertSessionHasErrors($field);
        }
        self::assertFalse(CoinEarningMethod::query()->where('title_ar', 'مهمة مرفوضة')->exists());

        foreach (CoinEarningMethod::AUTOMATIC_ACTION_KEYS as $automatic) {
            CoinEarningMethod::query()->firstOrCreate(['action_key' => $automatic], [
                'title_ar' => 'تلقائي', 'title_en' => 'Automatic', 'coins_amount' => 50,
                'is_active' => true, 'requires_external_visit' => false,
            ]);
        }
        $legacy = $this->method(['action_key' => null, 'title_ar' => 'صف قديم بلا مفتاح']);
        $listed = collect(Livewire::test(ListCoinEarningMethods::class)->instance()->getTableRecords()->items());
        self::assertSame([], $listed->pluck('action_key')->intersect(CoinEarningMethod::AUTOMATIC_ACTION_KEYS)->values()->all());
        self::assertContains($legacy->id, $listed->pluck('id')->all());
    }

    // The one-time task receipt index is proven by inserting a duplicate in
    // EngagementExperienceTest::test_task_audit_receipt_is_unique_per_user_and_method.

    /** @param array<string, mixed> $overrides */
    private function package(array $overrides): Package
    {
        return Package::query()->create([
            'name_ar' => 'باقة العملات', 'name_en' => 'Coins package', 'price' => 100, 'coins' => 500,
            'is_active' => true, 'direct_enabled' => true, ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function method(array $overrides): CoinEarningMethod
    {
        return CoinEarningMethod::query()->create([
            'title_ar' => 'مهمة', 'title_en' => 'Task', 'coins_amount' => 10,
            'action_key' => 'economy_task_'.Str::random(6), 'requires_external_visit' => false,
            'verification_delay_seconds' => 0, 'sort_order' => 50, 'is_active' => true, 'is_repeatable' => false,
            ...$overrides,
        ]);
    }

    private function dashboardUser(string $role): User
    {
        return User::query()->forceCreate([
            'name' => ucfirst($role).' Economy', 'email' => $role.'-economy@rokn.test', 'role' => $role, 'active' => true,
        ]);
    }
}
