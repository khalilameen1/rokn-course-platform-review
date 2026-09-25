<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UserProgressStatisticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('student_section_progress', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_section_id');
            $table->boolean('is_completed')->default(false);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('student_section_progress');
        parent::tearDown();
    }

    #[DataProvider('progressStates')]
    public function test_legacy_statistics_count_all_accessed_rows_without_narrowing_the_denominator(
        array $states,
        int $completed,
        int|float $rate
    ): void {
        foreach ($states as $index => $done) {
            DB::table('student_section_progress')->insert([
                'user_id' => 7, 'course_section_id' => $index + 1, 'is_completed' => $done,
            ]);
        }
        DB::table('student_section_progress')->insert([
            'user_id' => 8, 'course_section_id' => 1, 'is_completed' => true,
        ]);
        $user = (new User())->forceFill(['id' => 7]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $statistics = $user->lesson_progress_statistics;
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertSame(count($states), $statistics['total_lessons_accessed']);
        self::assertSame($completed, $statistics['completed_lessons']);
        self::assertEquals($rate, $statistics['completion_rate']);
        self::assertCount(1, $queries, 'Statistics should be one aggregate, not repeated queries and row hydration.');
        self::assertSame($completed, $user->completed_lessons_count);
    }

    public static function progressStates(): array
    {
        return [
            'none accessed' => [[], 0, 0],
            'none completed' => [[false, false], 0, 0],
            'partial completion' => [[true, false, false], 1, 33.33],
            'all completed' => [[true, true], 2, 100],
        ];
    }
}
