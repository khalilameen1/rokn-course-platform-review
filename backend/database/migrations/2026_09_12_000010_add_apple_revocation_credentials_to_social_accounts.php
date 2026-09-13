<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('social_accounts', 'apple_refresh_token')) {
            Schema::table('social_accounts', function (Blueprint $table): void {
                $table->text('apple_refresh_token')->nullable();
            });
        }
        if (!Schema::hasColumn('social_accounts', 'apple_client_id')) {
            Schema::table('social_accounts', function (Blueprint $table): void {
                $table->string('apple_client_id', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn(['apple_refresh_token', 'apple_client_id']);
        });
    }
};
