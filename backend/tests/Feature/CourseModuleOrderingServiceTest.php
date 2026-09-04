<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Services\CourseModuleOrderingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CourseModuleOrderingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('title_ar')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('course_modules');
        Schema::dropIfExists('courses');
        parent::tearDown();
    }

    public function test_insert_and_move_use_the_requested_position_not_the_newer_id(): void
    {
        DB::table('courses')->insert([
            'id' => 1,
            'name_ar' => 'كورس',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $course = Course::query()->findOrFail(1);
        $first = CourseModule::query()->create(['course_id' => 1, 'title_ar' => 'الأولى', 'order' => 1]);
        $second = CourseModule::query()->create(['course_id' => 1, 'title_ar' => 'الثانية', 'order' => 2]);
        $inserted = CourseModule::query()->create(['course_id' => 1, 'title_ar' => 'الجديدة', 'order' => 1]);
        $ordering = app(CourseModuleOrderingService::class);

        DB::transaction(fn () => $ordering->place($course, $inserted, 1));
        self::assertSame(
            [$inserted->id, $first->id, $second->id],
            CourseModule::query()->orderBy('order')->pluck('id')->all()
        );

        DB::transaction(fn () => $ordering->place($course, $second, 1));
        self::assertSame(
            [$second->id, $inserted->id, $first->id],
            CourseModule::query()->orderBy('order')->pluck('id')->all()
        );
    }
}
