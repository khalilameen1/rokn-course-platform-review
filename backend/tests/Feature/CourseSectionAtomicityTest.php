<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CourseSectionController;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Project;
use App\Services\CourseSectionContentService;
use App\Services\CourseSectionOrderingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CourseSectionAtomicityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->text('search_title_normalized')->nullable();
            $table->text('search_terms_normalized')->nullable();
            $table->boolean('is_coming_soon')->default(true);
            $table->unsignedBigInteger('authoring_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->timestamps();
        });
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('video_link')->nullable();
            $table->string('video_source_type');
            $table->string('bunny_video_id')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('file_link1')->nullable();
            $table->string('file_link2')->nullable();
            $table->unsignedBigInteger('list_id');
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_opened')->default(false);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->timestamps();
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->text('requirements_text')->nullable();
            $table->unsignedInteger('passing_score')->default(50);
            $table->boolean('is_graduation_project')->default(false);
            $table->timestamps();
        });
        Schema::create('lesson_media_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lesson_id')->unique();
            $table->string('provider');
            $table->string('provider_media_id');
            $table->string('status');
            $table->string('protocol')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('available_qualities')->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->string('integrity_status')->default('unknown');
            $table->json('integrity_issues')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->timestamps();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('title_ar');
            // Non-null on purpose: one test forces the section insert to fail
            // after the lesson insert, proving that both roll back together.
            $table->string('title_en');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('section_type');
            $table->string('sectionable_type');
            $table->unsignedBigInteger('sectionable_id');
            $table->unsignedInteger('order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('student_section_progress', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_section_id');
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('student_section_progress');
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('lesson_media_states');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_modules');
        Schema::dropIfExists('courses');
        parent::tearDown();
    }

    public function test_reorder_keeps_the_optional_crossing_project_last_in_its_module(): void
    {
        $course = Course::create(['name_ar' => 'اختبار', 'is_coming_soon' => true]);
        $module = CourseModule::create(['course_id' => $course->id]);
        $lesson = CourseSection::create([
            'title_ar' => 'مقطع',
            'title_en' => 'Clip',
            'course_id' => $course->id,
            'module_id' => $module->id,
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => 10,
            'order' => 1,
        ]);
        $project = CourseSection::create([
            'title_ar' => 'مشروع عبور',
            'title_en' => 'Crossing project',
            'course_id' => $course->id,
            'module_id' => $module->id,
            'section_type' => 'project',
            'sectionable_type' => Project::class,
            'sectionable_id' => 20,
            'order' => 2,
        ]);
        $request = Request::create('/dashboard/courses/1/sections/reorder', 'POST', [
            'authoring_version' => 1,
            'sections' => [
                ['id' => $project->id, 'order' => 1, 'module_id' => $module->id],
                ['id' => $lesson->id, 'order' => 2, 'module_id' => $module->id],
            ],
        ]);

        $response = app(CourseSectionController::class)->reorder($request, $course);

        self::assertTrue($response->getData(true)['success']);
        self::assertSame(1, (int) $lesson->fresh()->order);
        self::assertSame(2, (int) $project->fresh()->order);
    }

    public function test_insert_position_is_scoped_to_its_module_and_shifts_existing_sections(): void
    {
        $course = Course::create(['name_ar' => 'اختبار', 'is_coming_soon' => true]);
        $module = CourseModule::create(['course_id' => $course->id]);
        $otherModule = CourseModule::create(['course_id' => $course->id]);
        $first = $this->section($course, $module, 11, 1);
        $second = $this->section($course, $module, 12, 2);
        $unrelated = $this->section($course, $otherModule, 13, 50);
        $inserted = $this->section($course, $module, 14, 2);

        app(CourseSectionOrderingService::class)->place(
            $course,
            $inserted,
            null,
            2
        );

        self::assertSame(
            [$first->id, $inserted->id, $second->id],
            CourseSection::query()
                ->where('module_id', $module->id)
                ->orderBy('order')
                ->pluck('id')
                ->all()
        );
        self::assertSame(50, (int) $unrelated->fresh()->order);
    }

    public function test_moving_a_lesson_keeps_the_crossing_project_last_without_changing_identity(): void
    {
        $course = Course::create(['name_ar' => 'اختبار', 'is_coming_soon' => true]);
        $module = CourseModule::create(['course_id' => $course->id]);
        $first = $this->section($course, $module, 21, 1);
        $moving = $this->section($course, $module, 22, 2);
        $project = CourseSection::create([
            'title_ar' => 'مشروع عبور',
            'title_en' => 'Crossing project',
            'course_id' => $course->id,
            'module_id' => $module->id,
            'section_type' => 'project',
            'sectionable_type' => Project::class,
            'sectionable_id' => 23,
            'order' => 3,
        ]);

        app(CourseSectionOrderingService::class)->place(
            $course,
            $moving,
            $module->id,
            1
        );

        self::assertSame(
            [$moving->id, $first->id, $project->id],
            CourseSection::query()
                ->where('module_id', $module->id)
                ->orderBy('order')
                ->pluck('id')
                ->all()
        );
        self::assertSame(22, (int) $moving->fresh()->sectionable_id);
    }

    public function test_placement_rechecks_the_single_crossing_project_rule_under_the_course_lock(): void
    {
        $course = Course::create(['name_ar' => 'اختبار', 'is_coming_soon' => true]);
        $module = CourseModule::create(['course_id' => $course->id]);
        CourseSection::create([
            'title_ar' => 'مشروع أول',
            'title_en' => 'First project',
            'course_id' => $course->id,
            'module_id' => $module->id,
            'section_type' => 'project',
            'sectionable_type' => Project::class,
            'sectionable_id' => 31,
            'order' => 1,
        ]);
        $duplicate = CourseSection::create([
            'title_ar' => 'مشروع ثان',
            'title_en' => 'Second project',
            'course_id' => $course->id,
            'module_id' => $module->id,
            'section_type' => 'project',
            'sectionable_type' => Project::class,
            'sectionable_id' => 32,
            'order' => 2,
        ]);

        $this->expectException(ValidationException::class);
        app(CourseSectionOrderingService::class)->place(
            $course,
            $duplicate,
            null,
            2
        );
    }

    public function test_video_replacement_preserves_lesson_section_and_learning_progress_identity(): void
    {
        $course = Course::create(['name_ar' => 'اختبار', 'is_coming_soon' => true]);
        $module = CourseModule::create(['course_id' => $course->id]);
        $lesson = Lesson::query()->create([
            'title_ar' => 'المقطع',
            'title_en' => 'Lesson',
            'description_ar' => '',
            'description_en' => '',
            'video_source_type' => 'bunny',
            'bunny_video_id' => 'old-generation',
            'thumbnail_path' => 'lessons/old.webp',
            'list_id' => $course->id,
            'priority' => 1,
            'is_opened' => false,
        ]);
        LessonMediaState::query()->create([
            'lesson_id' => $lesson->id,
        ] + LessonMediaState::resetForGeneration('old-generation'));
        $section = CourseSection::create([
            'title_ar' => 'المقطع',
            'title_en' => 'Lesson',
            'course_id' => $course->id,
            'module_id' => $module->id,
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => 1,
        ]);
        DB::table('student_section_progress')->insert([
            'user_id' => 91,
            'course_section_id' => $section->id,
            'is_completed' => true,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $request = Request::create('/dashboard/courses/1/sections/1', 'PUT', [
            'section_type' => 'lesson',
            'title_ar' => 'المقطع بعد التعديل',
            'title_en' => 'Updated lesson',
            'lesson_description_ar' => 'وصف',
            'lesson_description_en' => 'Caption',
            'lesson_duration_minutes' => 3,
        ]);

        $updated = app(CourseSectionContentService::class)->update(
            $request,
            $course,
            $section,
            1,
            'new-generation',
            'lessons/new.webp',
            'old-generation',
            'lessons/old.webp'
        );

        self::assertSame($lesson->id, $updated->id);
        self::assertSame($lesson->id, $section->fresh()->sectionable_id);
        self::assertSame('new-generation', $lesson->fresh()->bunny_video_id);
        self::assertSame(
            'new-generation',
            LessonMediaState::query()->where('lesson_id', $lesson->id)->value('provider_media_id')
        );
        self::assertDatabaseHas('student_section_progress', [
            'user_id' => 91,
            'course_section_id' => $section->id,
            'is_completed' => true,
        ]);
    }

    public function test_authoring_eager_load_orders_sections_without_querying_project_order(): void
    {
        $course = Course::create(['name_ar' => 'اختبار', 'is_coming_soon' => true]);
        $module = CourseModule::create(['course_id' => $course->id]);
        $project = Project::create([
            'requirements_text' => 'تسليم المشروع',
        ]);
        CourseSection::create([
            'title_ar' => 'مشروع عبور',
            'title_en' => 'Crossing project',
            'course_id' => $course->id,
            'module_id' => $module->id,
            'section_type' => 'project',
            'sectionable_type' => Project::class,
            'sectionable_id' => $project->id,
            'order' => 1,
        ]);

        $loaded = $course->modules()
            ->with(['sections' => fn ($query) => $query
                ->with('sectionable')
                ->orderBy('order')
                ->orderBy('id')])
            ->firstOrFail();

        self::assertTrue($loaded->sections->first()->sectionable->is($project));
    }

    private function section(
        Course $course,
        CourseModule $module,
        int $lessonId,
        int $order
    ): CourseSection {
        return CourseSection::create([
            'title_ar' => 'مقطع '.$lessonId,
            'title_en' => 'Clip '.$lessonId,
            'course_id' => $course->id,
            'module_id' => $module->id,
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lessonId,
            'order' => $order,
        ]);
    }

}
