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
        if (
            Schema::hasTable('course_sections')
            && Schema::hasColumns('course_sections', ['section_type', 'sectionable_type'])
        ) {
            DB::table('course_sections')
                ->whereIn('section_type', ['quiz', 'question', 'exam', 'random_quiz'])
                ->orWhereIn('sectionable_type', [
                    'App\\Models\\ItemList',
                    'App\\Models\\Question',
                    'App\\Models\\RandomQuiz',
                    'App\\Models\\ExamAttempt',
                    'App\\ItemList',
                    'App\\Question',
                    'App\\RandomQuiz',
                    'App\\ExamAttempt',
                ])
                ->delete();
        }

        if (
            Schema::hasTable('photos')
            && Schema::hasColumn('photos', 'photoable_type')
        ) {
            DB::table('photos')->whereIn('photoable_type', [
                'App\\Models\\ItemList',
                'App\\Models\\Question',
                'App\\Models\\RandomQuiz',
                'App\\Models\\ExamAttempt',
                'App\\ItemList',
                'App\\Question',
                'App\\RandomQuiz',
                'App\\ExamAttempt',
            ])->delete();
        }

        foreach ([
            'exam_security_logs',
            'exam_answers',
            'exams_result_details',
            'exams_result_detailss',
            'exams_results',
            'exam_attempts',
            'questions',
            'random_quizzes',
            'quizzes',
            'exams',
            'lists',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasTable('lessons') && Schema::hasColumn('lessons', 'quiz_id')) {
            Schema::table('lessons', static function (Blueprint $table): void {
                $table->dropColumn('quiz_id');
            });
        }

        if (Schema::hasTable('courses')) {
            $columns = array_values(array_filter(
                ['questions_count', 'exam_count'],
                static fn (string $column): bool => Schema::hasColumn('courses', $column)
            ));

            if ($columns !== []) {
                Schema::table('courses', static function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }
    }

    public function down(): void
    {
        // The retired assessment data cannot be reconstructed safely.
    }
};
