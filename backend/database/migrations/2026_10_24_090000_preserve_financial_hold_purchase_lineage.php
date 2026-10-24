<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_entitlement_holds', function (Blueprint $table): void {
            $table->unsignedBigInteger('enrollment_order_id')->nullable();
            $table->unsignedBigInteger('plan_restore_order_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('financial_entitlement_holds', function (Blueprint $table): void {
            $table->dropColumn(['enrollment_order_id', 'plan_restore_order_id']);
        });
    }
};
