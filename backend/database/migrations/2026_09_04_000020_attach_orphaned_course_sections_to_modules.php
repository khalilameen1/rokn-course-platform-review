<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('course_modules') || !Schema::hasTable('course_sections')) {
            return;
        }

        DB::transaction(function (): void {
            $courseIds = DB::table('course_sections')
                ->whereNull('module_id')
                ->orderBy('course_id')
                ->distinct()
                ->pluck('course_id');

            foreach ($courseIds as $courseId) {
                $sections = DB::table('course_sections')
                    ->where('course_id', $courseId)
                    ->whereNull('module_id')
                    ->orderBy('order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'sectionable_type']);
                if ($sections->isEmpty()) {
                    continue;
                }

                $nextModuleOrder = (int) DB::table('course_modules')
                    ->where('course_id', $courseId)
                    ->lockForUpdate()
                    ->max('order');
                $moduleNumber = 0;
                $moduleId = null;
                $sectionOrder = 0;

                foreach ($sections as $section) {
                    if ($moduleId === null) {
                        $moduleNumber++;
                        $nextModuleOrder++;
                        $label = $moduleNumber === 1
                            ? 'محتوى الكورس'
                            : 'محتوى الكورس '.$moduleNumber;
                        $moduleId = DB::table('course_modules')->insertGetId([
                            'course_id' => $courseId,
                            'title' => $label,
                            'title_ar' => $label,
                            'title_en' => null,
                            'description' => null,
                            'description_ar' => null,
                            'description_en' => null,
                            'order' => $nextModuleOrder,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $sectionOrder = 0;
                    }

                    DB::table('course_sections')->where('id', $section->id)->update([
                        'module_id' => $moduleId,
                        'order' => ++$sectionOrder,
                        'updated_at' => now(),
                    ]);

                    if (ltrim((string) $section->sectionable_type, '\\') === 'App\\Models\\Project') {
                        $moduleId = null;
                    }
                }
            }
        }, 3);

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropForeign(['module_id']);
        });
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->unsignedBigInteger('module_id')->nullable(false)->change();
            $table->foreign('module_id')->references('id')->on('course_modules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('course_sections') || !Schema::hasColumn('course_sections', 'module_id')) {
            return;
        }

        Schema::table('course_sections', function (Blueprint $table): void {
            $table->dropForeign(['module_id']);
        });
        Schema::table('course_sections', function (Blueprint $table): void {
            $table->unsignedBigInteger('module_id')->nullable()->change();
            $table->foreign('module_id')->references('id')->on('course_modules')->nullOnDelete();
        });
    }
};
