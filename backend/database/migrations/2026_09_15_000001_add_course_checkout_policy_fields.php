<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // MySQL commits each DDL independently. A retry after an interrupted
        // rollout must preserve columns already applied by the first attempt.
        if (!Schema::hasColumn('settings', 'max_course_promotion_percent')) {
            Schema::table('settings', function (Blueprint $table): void {
                // Distinct percentage; never reinterpret the legacy coin cap.
                $table->unsignedTinyInteger('max_course_promotion_percent')->default(20);
            });
        }
        if (!Schema::hasColumn('course_access_plans', 'projects_enabled')) {
            Schema::table('course_access_plans', function (Blueprint $table): void {
                // Keep existing offers. Watch-only is an authoring change.
                $table->boolean('projects_enabled')->default(true);
            });
        }
        if (!Schema::hasColumn('course_access_plans', 'delivery_cost_usd')) {
            Schema::table('course_access_plans', function (Blueprint $table): void {
                // NULL is uncosted, not free. Excludes provider budgets.
                $table->decimal('delivery_cost_usd', 12, 6)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['projects_enabled', 'delivery_cost_usd'] as $column) {
            if (Schema::hasColumn('course_access_plans', $column)) {
                Schema::table('course_access_plans', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        if (Schema::hasColumn('settings', 'max_course_promotion_percent')) {
            Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('max_course_promotion_percent'));
        }
    }
};
