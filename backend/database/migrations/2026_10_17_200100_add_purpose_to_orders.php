<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An access-plan upgrade order is identified by an explicit, write-once
 * column instead of a free-text note. Existing (pre-launch test) upgrade
 * orders are recognised by the exact note the upgrade action has written.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }
        if (!Schema::hasColumn('orders', 'purpose')) {
            Schema::table('orders', fn (Blueprint $table) =>
                $table->string('purpose', 40)->nullable()->after('parent_order_id')
            );
        }

        DB::table('orders')
            ->whereNull('purpose')
            ->whereNotNull('course_id')
            ->whereNull('package_id')
            ->where('payment_method', 'wallet_coins')
            ->where('notes', 'like', 'Course access-plan upgrade from order #%')
            ->update(['purpose' => 'course_plan_upgrade']);
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'purpose')) {
            Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('purpose'));
        }
    }
};
