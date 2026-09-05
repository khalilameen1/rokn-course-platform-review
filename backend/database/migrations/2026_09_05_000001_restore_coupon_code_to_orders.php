<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders') || Schema::hasColumn('orders', 'coupon_code')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('coupon_code')->nullable();
        });
    }

    public function down(): void
    {
        // Coupon identity is part of the immutable order audit trail. A rollback
        // must not discard it from databases where the column already existed.
    }
};
