<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Before/after values for an allow-listed set of commercial and permission
 * fields (prices, plan terms, coin amounts, coupon limits, roles), modelled
 * on spatie/laravel-activitylog's properties.old / properties.attributes.
 * Nullable: older rows and requests that touched no listed field keep NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_audit_logs') || Schema::hasColumn('admin_audit_logs', 'changes')) {
            return;
        }
        Schema::table('admin_audit_logs', function (Blueprint $table): void {
            $table->json('changes')->nullable()->after('request_fields');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('admin_audit_logs') && Schema::hasColumn('admin_audit_logs', 'changes')) {
            Schema::table('admin_audit_logs', function (Blueprint $table): void {
                $table->dropColumn('changes');
            });
        }
    }
};
