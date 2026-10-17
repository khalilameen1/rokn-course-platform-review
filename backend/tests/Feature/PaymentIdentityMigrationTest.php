<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PaymentIdentityMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('orders');
        parent::tearDown();
    }

    public function test_coupon_codes_are_normalized_once_and_uniquely_indexed(): void
    {
        $this->createCoupons();
        DB::table('coupons')->insert([
            ['id' => 1, 'code' => " summer\u{200F}\u{0662}\u{0660} "],
            ['id' => 2, 'code' => 'WINTER'],
            ['id' => 3, 'code' => null],
            ['id' => 4, 'code' => '   '],
        ]);

        $migration = $this->migration('2026_10_17_200000_index_normalized_coupon_codes.php');
        $migration->up();
        $migration->up();

        self::assertSame(
            [1 => 'SUMMER20', 2 => 'WINTER', 3 => null, 4 => null],
            DB::table('coupons')->orderBy('id')->pluck('code', 'id')->all()
        );
        self::assertTrue(Schema::hasIndex('coupons', 'coupons_code_unique'));

        $migration->down();
        self::assertFalse(Schema::hasIndex('coupons', 'coupons_code_unique'));
    }

    public function test_coupon_normalization_refuses_to_merge_two_campaigns(): void
    {
        $this->createCoupons();
        DB::table('coupons')->insert([['id' => 1, 'code' => 'spring'], ['id' => 2, 'code' => 'SPRING']]);

        try {
            $this->migration('2026_10_17_200000_index_normalized_coupon_codes.php')->up();
            self::fail('Colliding coupon codes must stop the migration.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('#1 and #2', $exception->getMessage());
        }
        self::assertSame(['spring', 'SPRING'], DB::table('coupons')->orderBy('id')->pluck('code')->all());
        self::assertFalse(Schema::hasIndex('coupons', 'coupons_code_unique'));
    }

    public function test_existing_upgrade_orders_get_an_explicit_purpose(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('parent_order_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
        });
        DB::table('orders')->insert([
            ['id' => 1, 'course_id' => 5, 'package_id' => null, 'payment_method' => 'wallet_coins', 'notes' => 'Wallet course purchase'],
            ['id' => 2, 'course_id' => 5, 'package_id' => null, 'payment_method' => 'wallet_coins', 'notes' => 'Course access-plan upgrade from order #1'],
            ['id' => 3, 'course_id' => 5, 'package_id' => null, 'payment_method' => 'wallet_coins', 'notes' => 'Course access-plan upgrade from order #0'],
            ['id' => 4, 'course_id' => null, 'package_id' => 9, 'payment_method' => 'kashier', 'notes' => 'Course access-plan upgrade from order #1'],
        ]);

        $migration = $this->migration('2026_10_17_200100_add_purpose_to_orders.php');
        $migration->up();
        $migration->up();

        self::assertSame(
            [1 => null, 2 => 'course_plan_upgrade', 3 => 'course_plan_upgrade', 4 => null],
            DB::table('orders')->orderBy('id')->pluck('purpose', 'id')->all()
        );

        $migration->down();
        self::assertFalse(Schema::hasColumn('orders', 'purpose'));
    }

    private function createCoupons(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->nullable();
            $table->softDeletes();
        });
    }

    private function migration(string $file): object
    {
        return require database_path('migrations/' . $file);
    }
}
