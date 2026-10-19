<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CouponResource;
use App\Filament\Resources\CouponResource\Pages\CreateCoupon;
use App\Filament\Resources\CouponResource\Pages\EditCoupon;
use App\Filament\Resources\CouponResource\Pages\ListCoupons;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use App\Services\AdminCouponAuthoringService;
use App\Services\CourseStagedAuthoringService;
use App\Support\BusinessClock;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentCouponWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المالية', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_coupon_media_and_wall_time_round_trip_through_the_existing_owner(): void
    {
        $starts = BusinessClock::now()->addDay()->format('Y-m-d\TH:i');
        Livewire::test(CreateCoupon::class)->fillForm([...$this->fields(), 'starts_at' => $starts,
            'image' => UploadedFile::fake()->image('campaign.png')])->call('create')->assertHasNoFormErrors();
        $coupon = Coupon::query()->sole();
        $oldPath = $coupon->photo->path;
        Storage::disk('public')->assertExists($oldPath);
        self::assertSame(BusinessClock::localInputToUtc($starts)->getTimestamp(), $coupon->starts_at->getTimestamp());
        Livewire::test(EditCoupon::class, ['record' => $coupon->id])->assertSet('data.starts_at', $starts)
            ->fillForm(['name_ar' => 'الاسم الأحدث', 'image' => UploadedFile::fake()->image('replacement.png')])
            ->call('save')->assertHasNoFormErrors();
        $coupon->refresh()->load('photo');
        self::assertSame($starts, BusinessClock::forDateTimeInput($coupon->starts_at));
        self::assertNotSame($oldPath, $coupon->photo->path);
        Storage::disk('public')->assertExists($coupon->photo->path);
        self::assertFalse($coupon->allPhotos()->where('path', $oldPath)->exists());
    }

    public function test_schedule_rules_are_enforced_in_native_forms_and_in_the_writer_without_http(): void
    {
        $expiry = BusinessClock::now()->addDay()->format('Y-m-d');
        $invalid = [...$this->fields(), 'expiry_date' => $expiry, 'starts_at' => $expiry.'T23:59'];
        Livewire::test(CreateCoupon::class)->fillForm($invalid)->call('create')->assertHasNoFormErrors();
        $coupon = Coupon::query()->sole();
        $invalid['starts_at'] = BusinessClock::now()->addDays(2)->format('Y-m-d\T00:00');
        Livewire::test(EditCoupon::class, ['record' => $coupon->id])->fillForm($invalid)->call('save')->assertHasFormErrors(['starts_at']);
        self::assertSame($expiry.'T23:59', BusinessClock::forDateTimeInput($coupon->fresh()->starts_at));
        try {
            app(AdminCouponAuthoringService::class)->create([...$this->fields(), 'code' => 'EXPIRED',
                'expiry_date' => BusinessClock::now()->subDay()->format('Y-m-d')], (string) Str::uuid(), null, static function (): void {});
            self::fail('The owning service must reject an already-expired new campaign.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('expiry_date', $exception->errors());
        }
        self::assertSame(1, Coupon::query()->count());
    }

    public function test_normalized_unique_codes_and_discount_bounds_remain_visible_field_errors(): void
    {
        $coupon = Coupon::query()->create($this->fields());
        Livewire::test(CreateCoupon::class)->fillForm([...$this->fields(), 'code' => '  ROKN10  '])
            ->call('create')->assertHasFormErrors(['code' => 'unique']);
        Livewire::test(CreateCoupon::class)->fillForm([...$this->fields(), 'code' => 'OTHER', 'balance' => 101])
            ->call('create')->assertHasFormErrors(['balance' => 'max']);
        Livewire::test(EditCoupon::class, ['record' => $coupon->id])->fillForm(['balance' => 20])->call('save')->assertHasNoFormErrors();
        self::assertSame(20, $coupon->fresh()->balance);
    }

    public function test_redemption_history_blocks_changed_discount_but_still_allows_deactivation_and_soft_delete(): void
    {
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس', 'price' => 100,
            'is_coming_soon' => true, 'is_catalog_visible' => false]);
        $coupon = Coupon::query()->create($this->fields());
        $order = Order::query()->create(['user_id' => auth('web')->id(), 'course_id' => $course->id,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS, 'order_ref' => 'COUPON-NATIVE-1',
            'amount' => 100, 'final_amount' => 90, 'total_coins' => 90, 'status' => Order::STATUS_APPROVED]);
        $redemption = CouponRedemption::query()->create(['coupon_id' => $coupon->id, 'user_id' => auth('web')->id(),
            'course_id' => $course->id, 'order_id' => $order->id, 'coupon_code' => $coupon->code,
            'discount_percentage' => 10, 'discount_coins' => 10, 'redeemed_at' => now()]);
        Livewire::test(EditCoupon::class, ['record' => $coupon->id])->fillForm(['balance' => 30])->call('save')->assertHasFormErrors(['code']);
        self::assertSame(10, $coupon->fresh()->balance);
        Livewire::test(EditCoupon::class, ['record' => $coupon->id])->fillForm(['active' => false])->call('save')->assertHasNoFormErrors();
        Livewire::test(ListCoupons::class)->callTableAction('remove', $coupon->fresh());
        self::assertTrue($coupon->fresh()->trashed());
        self::assertSame('ROKN10', $redemption->fresh()->coupon_code);
        self::assertTrue(Order::query()->whereKey($order->id)->exists());
    }

    public function test_stale_edits_and_confirmations_cannot_overwrite_newer_campaigns(): void
    {
        $coupon = Coupon::query()->create($this->fields());
        $editor = Livewire::test(EditCoupon::class, ['record' => $coupon->id]);
        $list = Livewire::test(ListCoupons::class)->mountTableAction('remove', $coupon);
        $coupon->update(['name_ar' => 'الأحدث']);
        $editor->fillForm(['name_ar' => 'قديم'])->call('save');
        self::assertSame('الأحدث', $coupon->fresh()->name_ar);
        $list->callMountedTableAction();
        self::assertFalse($coupon->fresh()->trashed());
    }

    public function test_retained_invalid_revision_target_can_be_disabled_but_never_reactivated(): void
    {
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس أصلي', 'price' => 100,
            'authoring_version' => 1, 'is_coming_soon' => false, 'is_catalog_visible' => true]);
        $revision = app(CourseStagedAuthoringService::class)->draftFor($course);
        $coupon = Coupon::query()->create([...$this->fields(), 'course_id' => $revision->id]);
        Livewire::test(EditCoupon::class, ['record' => $coupon->id])->fillForm(['active' => false])->call('save')->assertHasNoFormErrors();
        self::assertFalse($coupon->fresh()->active);
        Livewire::test(EditCoupon::class, ['record' => $coupon->id])->fillForm(['active' => true])->call('save')->assertHasFormErrors(['course_id']);
        self::assertFalse($coupon->fresh()->active);
    }

    public function test_bookmarks_and_permissions_keep_the_financial_workspace_restricted(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $coupon = Coupon::query()->create($this->fields());
        $this->get(route('admin.coupons.index'))->assertRedirect(CouponResource::getUrl());
        $this->get(route('admin.coupons.create'))->assertRedirect(CouponResource::getUrl('create'));
        $this->get(route('admin.coupons.edit', $coupon))->assertRedirect(CouponResource::getUrl('edit', ['record' => $coupon->id]));
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(ListCoupons::class)->assertForbidden();
        Livewire::test(CreateCoupon::class)->assertForbidden();
        Livewire::test(EditCoupon::class, ['record' => $coupon->id])->assertForbidden();
    }

    private function fields(): array
    {
        return ['name_ar' => 'خصم رُكن', 'name_en' => 'Rokn offer', 'code' => 'ROKN10', 'course_id' => null,
            'balance' => 10, 'max_redemptions' => null, 'expiry_date' => BusinessClock::now()->addMonth()->format('Y-m-d'), 'active' => true];
    }
}
