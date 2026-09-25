<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\PackageController;
use App\Models\Order;
use App\Models\Package;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminPackageAuthoringService;
use App\Support\PackageEditorVersion;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminPackageAuthoringOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        config(['cache.default' => 'array']);
        Http::preventStrayRequests();
        foreach ([PackageController::class, AdminAuthoringCreateIntentService::class] as $adapter) {
            $this->app->bind($adapter, static function (): never {
                throw new \LogicException('Product authoring cannot resolve an HTTP adapter.');
            });
        }
    }

    public function test_create_and_receipt_commit_together_and_ignore_transport_fields(): void
    {
        Cache::forever('public-packages:v2', ['old']);
        $package = app(AdminPackageAuthoringService::class)->create($this->fields() + [
            'editor_version' => 'not-a-model-field', 'authoring_request_id' => (string) Str::uuid(),
        ], static function (Package $package): void {
            self::assertSame(1, DB::transactionLevel());
            self::assertTrue($package->exists);
            self::assertSame(['old'], Cache::get('public-packages:v2'));
            DB::table('admin_singleton_locks')->insert(['lock_key' => 'package-receipt']);
        });
        self::assertSame(100, $package->fresh()->sort_order);
        self::assertSame('120.00', $package->fresh()->price);
        self::assertNull(Cache::get('public-packages:v2'));
        self::assertTrue(DB::table('admin_singleton_locks')->where('lock_key', 'package-receipt')->exists());
        Http::assertNothingSent();
    }

    public function test_receipt_failure_rolls_back_product_and_does_not_invalidate_committed_cache(): void
    {
        Cache::forever('public-packages:v2', ['old']);
        try {
            app(AdminPackageAuthoringService::class)->create($this->fields(), static function (): never {
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'failed-package-receipt']);
                throw new \RuntimeException('receipt failed');
            });
            self::fail('A failed receipt must not leave a product.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt failed', $error->getMessage());
        }
        self::assertSame(0, Package::query()->count());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'failed-package-receipt')->exists());
        self::assertSame(['old'], Cache::get('public-packages:v2'));
    }

    public function test_both_create_and_update_require_an_executable_channel_only_when_active(): void
    {
        $writer = app(AdminPackageAuthoringService::class);
        $noChannel = array_replace($this->fields(), ['direct_enabled' => false]);
        try {
            $writer->create($noChannel, static function (): never { self::fail('No receipt for an unpurchasable active product.'); });
            self::fail('An active product needs a payment channel.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('channels', $error->errors());
        }
        $package = $writer->create(array_replace($noChannel, ['is_active' => false]), static function (): void {});
        self::assertFalse($package->is_active);
        foreach ([['is_active' => true], ['is_active' => true, 'google_enabled' => true]] as $activation) {
            try {
                $writer->update($package->id, $activation, PackageEditorVersion::for($package->fresh()));
                self::fail('A store channel without a product ID cannot activate the package.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('channels', $error->errors());
            }
        }
        self::assertFalse($package->fresh()->is_active);
        $writer->update($package->id, ['is_active' => true, 'google_enabled' => true, 'google_product_id' => 'rokn.100'],
            PackageEditorVersion::for($package->fresh()));
        self::assertTrue($package->fresh()->is_active);
        self::assertTrue($package->fresh()->hasPurchasableChannel());
    }

    public function test_stale_pricing_and_deletion_cannot_override_a_newer_editor(): void
    {
        $writer = app(AdminPackageAuthoringService::class);
        $package = $writer->create($this->fields(), static function (): void {});
        $stale = PackageEditorVersion::for($package);
        $writer->update($package->id, ['price' => 150], $stale);
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') $writer->update($package->id, ['price' => 90], $stale);
                else $writer->deleteIfUnused($package->id, $stale);
                self::fail('Stale financial editors cannot write.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('editor_version', $error->errors());
            }
        }
        self::assertSame('150.00', $package->fresh()->price);
    }

    public function test_issued_store_identity_and_coin_amount_are_immutable_but_disabling_is_allowed(): void
    {
        $writer = app(AdminPackageAuthoringService::class);
        $package = $writer->create(array_replace($this->fields(), [
            'google_enabled' => true, 'google_product_id' => 'rokn.100',
            'apple_enabled' => true, 'apple_product_id' => 'rokn.ios.100',
        ]), static function (): void {});
        foreach ([['coins' => 200], ['google_product_id' => 'rokn.200'], ['apple_product_id' => null]] as $change) {
            try {
                $writer->update($package->id, $change + ['name_ar' => 'لا تحفظ'], PackageEditorVersion::for($package->fresh()));
                self::fail('An issued store contract cannot change.');
            } catch (\DomainException) {
                self::assertSame('منتج ركن', $package->fresh()->name_ar);
            }
        }
        $writer->update($package->id, [
            'is_active' => false, 'direct_enabled' => false, 'google_enabled' => false, 'apple_enabled' => false,
        ], PackageEditorVersion::for($package->fresh()));
        self::assertFalse($package->fresh()->is_active);
        self::assertSame('rokn.100', $package->fresh()->google_product_id);
        self::assertSame(100, $package->fresh()->coins);
        self::assertFalse($writer->deleteIfUnused($package->id, PackageEditorVersion::for($package->fresh())));
    }

    public function test_order_and_store_receipt_history_prevent_deletion_even_without_an_issued_sku(): void
    {
        $writer = app(AdminPackageAuthoringService::class);
        $package = $writer->create($this->fields(), static function (): void {});
        $user = User::query()->forceCreate(['name_ar' => 'طالب', 'email' => 'package-history@rokn.test', 'role' => 'client']);
        $order = Order::query()->create([
            'user_id' => $user->id, 'package_id' => $package->id,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => 120, 'final_amount' => 120, 'total_coins' => 100,
            'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING,
        ]);
        self::assertFalse($writer->deleteIfUnused($package->id, PackageEditorVersion::for($package)));
        $storePackage = $writer->create($this->fields(), static function (): void {});
        // Legacy store evidence can retain its product link even when the
        // associated order has no package link. Neither record may be erased.
        $storeOrder = Order::query()->create([
            'user_id' => $user->id, 'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => 120, 'final_amount' => 120, 'total_coins' => 100,
            'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING,
        ]);
        StorePurchase::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'package_id' => $storePackage->id,
            'order_id' => $storeOrder->id, 'provider' => StorePurchase::PROVIDER_GOOGLE,
            'product_id' => 'historical-product', 'external_transaction_id' => 'test-history',
            'purchase_token_hash' => hash('sha256', 'test-only'), 'purchase_token' => 'test-only',
            'environment' => 'test', 'status' => 'verified', 'verified_at' => now(),
        ]);
        self::assertFalse($writer->deleteIfUnused($storePackage->id, PackageEditorVersion::for($storePackage)));
        $unused = $writer->create($this->fields(), static function (): void {});
        self::assertTrue($writer->deleteIfUnused($unused->id, PackageEditorVersion::for($unused)));
        self::assertNull($unused->fresh());
    }

    public function test_post_commit_cache_and_reporting_failures_cannot_make_product_creation_fail(): void
    {
        Cache::shouldReceive('forget')->with('public-packages:v2')->once()
            ->andThrow(new \RuntimeException('cache unavailable'));
        $handler = \Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->andThrow(new \RuntimeException('reporter unavailable'));
        $this->app->instance(ExceptionHandler::class, $handler);
        $package = app(AdminPackageAuthoringService::class)->create($this->fields(), static function (): void {
            DB::table('admin_singleton_locks')->insert(['lock_key' => 'durable-package-receipt']);
        });
        self::assertSame(0, DB::transactionLevel());
        self::assertNotNull($package->fresh());
        self::assertSame(1, Package::query()->count());
        self::assertTrue(DB::table('admin_singleton_locks')->where('lock_key', 'durable-package-receipt')->exists());
    }

    private function fields(): array
    {
        return [
            'name_ar' => 'منتج ركن', 'name_en' => 'Rokn product', 'price' => 120, 'coins' => 100,
            'is_active' => true, 'direct_enabled' => true, 'google_enabled' => false, 'apple_enabled' => false,
            'google_product_id' => null, 'apple_product_id' => null,
        ];
    }
}
