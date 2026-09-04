<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('coin_earning_methods')) {
            if (!Schema::hasColumn('coin_earning_methods', 'sort_order')) {
                Schema::table('coin_earning_methods', function (Blueprint $table): void {
                    $table->unsignedSmallInteger('sort_order')->default(100)->after('total_claim_limit');
                });
            }
            if (!Schema::hasIndex('coin_earning_methods', 'coin_methods_active_order')) {
                Schema::table('coin_earning_methods', function (Blueprint $table): void {
                    $table->index(['is_active', 'sort_order'], 'coin_methods_active_order');
                });
            }
        }

        if (Schema::hasTable('packages')) {
            if (!Schema::hasColumn('packages', 'sort_order')) {
                Schema::table('packages', function (Blueprint $table): void {
                    $table->unsignedSmallInteger('sort_order')->default(100)->after('coins');
                });
            }
            if (!Schema::hasIndex('packages', 'packages_active_order')) {
                Schema::table('packages', function (Blueprint $table): void {
                    $table->index(['is_active', 'sort_order'], 'packages_active_order');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coin_earning_methods')) {
            if (Schema::hasIndex('coin_earning_methods', 'coin_methods_active_order')) {
                Schema::table('coin_earning_methods', function (Blueprint $table): void {
                    $table->dropIndex('coin_methods_active_order');
                });
            }
            if (Schema::hasColumn('coin_earning_methods', 'sort_order')) {
                Schema::table('coin_earning_methods', function (Blueprint $table): void {
                    $table->dropColumn('sort_order');
                });
            }
        }

        if (Schema::hasTable('packages')) {
            if (Schema::hasIndex('packages', 'packages_active_order')) {
                Schema::table('packages', function (Blueprint $table): void {
                    $table->dropIndex('packages_active_order');
                });
            }
            if (Schema::hasColumn('packages', 'sort_order')) {
                Schema::table('packages', function (Blueprint $table): void {
                    $table->dropColumn('sort_order');
                });
            }
        }
    }
};
