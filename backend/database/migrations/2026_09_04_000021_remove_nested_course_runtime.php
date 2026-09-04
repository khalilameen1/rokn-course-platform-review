<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const PARENT_INDEXES = [
        'courses_public_catalog_lookup',
        'courses_public_discovery_v2',
        'courses_catalogue_page_order_v3',
    ];

    public function up(): void
    {
        if (Schema::hasTable('course_sections')) {
            DB::table('course_sections')
                ->whereNull('deleted_at')
                ->whereIn('sectionable_type', [
                    'App\\Models\\Course',
                    'course',
                    'courses',
                ])
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        if (!Schema::hasTable('courses') || !Schema::hasColumn('courses', 'parent_id')) {
            return;
        }

        // A legacy child with its own enrollment/order remains a published,
        // unlisted course. Only inherited parent access disappears.
        DB::table('courses')->whereNotNull('parent_id')->update([
            'parent_id' => null,
            'is_catalog_visible' => false,
            'is_main_course' => false,
            'updated_at' => now(),
        ]);

        foreach (self::PARENT_INDEXES as $index) {
            if (Schema::hasIndex('courses', $index)) {
                Schema::table('courses', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }
        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('parent_id'));
    }

    public function down(): void
    {
        if (!Schema::hasTable('courses') || Schema::hasColumn('courses', 'parent_id')) {
            return;
        }

        Schema::table('courses', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_id')->nullable()->after('tenant_id');
            $table->index(
                ['is_coming_soon', 'parent_id', 'created_at'],
                'courses_public_catalog_lookup'
            );
            $table->index(
                ['parent_id', 'is_coming_soon', 'is_catalog_visible', 'created_at', 'id'],
                'courses_public_discovery_v2'
            );
            $table->index([
                'parent_id', 'is_catalog_visible', 'is_coming_soon',
                'is_main_course', 'home_sort_order', 'created_at', 'id',
            ], 'courses_catalogue_page_order_v3');
        });
    }
};
