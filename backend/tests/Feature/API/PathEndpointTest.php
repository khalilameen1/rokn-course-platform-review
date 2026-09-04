<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feature tests covering Educational Path API endpoints:
 * listing available learning paths, viewing path details, and user enrolled paths.
 */
class PathEndpointTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('badge_image')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::table('courses', function (Blueprint $table): void {
            $table->unsignedBigInteger('path_id')->nullable();
            $table->unsignedBigInteger('level_id')->nullable();
        });

        Schema::create('classification_path', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('path_id');
            $table->unsignedBigInteger('classification_id');
            $table->timestamps();
        });

        // Path discovery intentionally uses the same complete public-course
        // contract as home/search; make this fixture a publishable card.
        DB::table('courses')->where('id', $this->courseId)->update([
            'image' => 'test-course.jpg',
            'path_id' => $this->pathId,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('classification_path');
        Schema::dropIfExists('levels');

        parent::tearDown();
    }

    public function test_can_list_paths(): void
    {
        $levelId = DB::table('levels')->insertGetId([
            'name_ar' => 'مبتدئ',
            'name_en' => 'Beginner',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('courses')->where('id', $this->courseId)->update([
            'path_id' => $this->pathId,
            'level_id' => $levelId,
        ]);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/v1/paths')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم تحميل المسارات')
            ->assertJsonPath('data.0.title', 'Test Path')
            ->assertJsonPath('data.0.levels.0.id', $levelId)
            ->assertJsonPath('data.0.levels.0.name_en', 'Beginner');
    }

    public function test_path_lists_only_levels_backed_by_reachable_public_courses(): void
    {
        $beginnerId = DB::table('levels')->insertGetId([
            'name_ar' => 'مبتدئ',
            'name_en' => 'Beginner',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $expertId = DB::table('levels')->insertGetId([
            'name_ar' => 'خبير',
            'name_en' => 'Expert',
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('courses')->where('id', $this->courseId)->update([
            'path_id' => $this->pathId,
            'level_id' => $beginnerId,
        ]);

        $response = $this->getJson('/api/v1/paths')->assertOk();

        self::assertSame(
            [$beginnerId],
            collect($response->json('data.0.levels'))->pluck('id')->all()
        );
    }

    public function test_can_view_path_details(): void
    {
        $this->getJson("/api/v1/paths/{$this->pathId}")
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم تحميل المسار')
            ->assertJsonPath('data.id', $this->pathId);
    }

    public function test_authenticated_user_can_view_user_paths(): void
    {
        $currentLevelId = DB::table('levels')->insertGetId([
            'name_ar' => 'مبتدئ',
            'name_en' => 'Junior',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nextLevelId = DB::table('levels')->insertGetId([
            'name_ar' => 'متوسط',
            'name_en' => 'Mid-level',
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('courses')->where('id', $this->courseId)->update([
            'path_id' => $this->pathId,
            'level_id' => $currentLevelId,
        ]);
        $nextCourse = (array) DB::table('courses')->where('id', $this->courseId)->first();
        unset($nextCourse['id']);
        $nextCourse['name_ar'] = 'كورس المستوى التالي';
        $nextCourse['name_en'] = 'Next level course';
        $nextCourse['level_id'] = $nextLevelId;
        $nextCourse['created_at'] = now();
        $nextCourse['updated_at'] = now();
        $nextCourseId = DB::table('courses')->insertGetId($nextCourse);
        // A published course is reachable only when it has an active access
        // plan. Copy the base plan so this fixture represents a genuine next
        // level rather than a catalogue-invalid course.
        $nextPlan = (array) DB::table('course_access_plans')
            ->where('course_id', $this->courseId)
            ->where('code', 'basic')
            ->first();
        unset($nextPlan['id']);
        $nextPlan['course_id'] = $nextCourseId;
        $nextPlan['created_at'] = now();
        $nextPlan['updated_at'] = now();
        DB::table('course_access_plans')->insert($nextPlan);
        DB::table('course_sections')->insert([
            'course_id' => $nextCourseId,
            'title_ar' => 'محتوى المستوى التالي',
            'title_en' => 'Next level content',
            'sort_order' => 1,
            'is_free' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondSectionId = DB::table('course_sections')->insertGetId([
            'course_id' => $this->courseId,
            'module_id' => $this->moduleId,
            'title_ar' => 'قسم 2',
            'title_en' => 'Section 2',
            'section_type' => 'lesson',
            'sectionable_type' => \App\Models\Lesson::class,
            'sectionable_id' => 10,
            'order' => 2,
            'sort_order' => 2,
            'is_free' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('student_section_progress')->insert([
            'user_id' => $this->user->id,
            'course_section_id' => $secondSectionId,
            'is_completed' => true,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/user/paths')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم تحميل تقدمك في المسارات')
            ->assertJsonPath('data.0.current_level.id', $currentLevelId);

        self::assertSame(
            $nextLevelId,
            $response->json('data.0.next_level.id'),
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );
        self::assertSame($nextLevelId, $response->json('data.0.levels.0.id'));
        self::assertSame(50, $response->json('data.0.required_progress_percentage'));

        self::assertNotContains(
            $currentLevelId,
            collect($response->json('data.0.levels'))->pluck('id')->all()
        );
    }
}
