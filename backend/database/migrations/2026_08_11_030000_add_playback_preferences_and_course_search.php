<?php

use App\Services\ArabicSearchNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (!Schema::hasColumn('users', 'autoplay_next_enabled')) {
                    $table->boolean('autoplay_next_enabled')->default(true);
                }
                if (!Schema::hasColumn('users', 'video_quality_preference')) {
                    $table->string('video_quality_preference', 16)->default('auto');
                }
                if (!Schema::hasColumn('users', 'video_fit_mode')) {
                    $table->string('video_fit_mode', 12)->default('cover');
                }
                if (!Schema::hasColumn('users', 'playback_speed')) {
                    $table->decimal('playback_speed', 3, 2)->default(1.00);
                }
            });
        }

        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table): void {
                if (!Schema::hasColumn('courses', 'search_keywords_ar')) {
                    $table->text('search_keywords_ar')->nullable();
                }
                if (!Schema::hasColumn('courses', 'search_keywords_en')) {
                    $table->text('search_keywords_en')->nullable();
                }
            });

            $normalizer = app(ArabicSearchNormalizer::class);
            DB::table('courses')->orderBy('id')->chunkById(200, function ($courses) use ($normalizer): void {
                foreach ($courses as $course) {
                    $terms = implode(' ', array_filter([
                        $course->name_ar ?? null,
                        $course->name_en ?? null,
                        $course->description_ar ?? null,
                        $course->description_en ?? null,
                        $course->search_keywords_ar ?? null,
                        $course->search_keywords_en ?? null,
                    ]));
                    if (Schema::hasColumn('courses', 'search_terms_normalized')) {
                        DB::table('courses')->where('id', $course->id)->update([
                            'search_terms_normalized' => $normalizer->normalize($terms),
                        ]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table): void {
                $columns = array_values(array_filter(
                    ['search_keywords_ar', 'search_keywords_en'],
                    fn (string $column): bool => Schema::hasColumn('courses', $column)
                ));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    'autoplay_next_enabled',
                    'video_quality_preference',
                    'video_fit_mode',
                    'playback_speed',
                ], fn (string $column): bool => Schema::hasColumn('users', $column)));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
