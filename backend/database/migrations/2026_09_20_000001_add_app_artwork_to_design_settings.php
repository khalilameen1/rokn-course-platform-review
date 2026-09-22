<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('design_settings', function (Blueprint $table): void {
            $table->string('coin_image_url', 2048)->nullable();
            $table->string('coin_stack_image_url', 2048)->nullable();
            $table->string('badge_junior_image_url', 2048)->nullable();
            $table->string('badge_mid_image_url', 2048)->nullable();
            $table->string('badge_senior_image_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('design_settings', function (Blueprint $table): void {
            $table->dropColumn(['coin_image_url', 'coin_stack_image_url', 'badge_junior_image_url', 'badge_mid_image_url', 'badge_senior_image_url']);
        });
    }
};
