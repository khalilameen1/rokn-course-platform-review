<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class OrderCouponCodeSchemaUpgradeTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('orders');
        parent::tearDown();
    }

    public function test_upgrade_restores_nullable_coupon_code_without_losing_existing_orders(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('access_plan_id');
            $table->string('status');
        });
        DB::table('orders')->insert([
            'id' => 17,
            'user_id' => 6,
            'course_id' => 3,
            'access_plan_id' => 12,
            'status' => 'pending',
        ]);

        $migrationPath = database_path(
            'migrations/2026_09_05_000001_restore_coupon_code_to_orders.php'
        );

        (require $migrationPath)->up();
        (require $migrationPath)->up();

        self::assertTrue(Schema::hasColumn('orders', 'coupon_code'));
        self::assertSame(1, DB::table('orders')->count());
        self::assertNull(DB::table('orders')->where('id', 17)->value('coupon_code'));

        DB::table('orders')->where('id', 17)->update(['coupon_code' => 'ROKN50']);
        self::assertSame('ROKN50', DB::table('orders')->where('id', 17)->value('coupon_code'));
    }
}
