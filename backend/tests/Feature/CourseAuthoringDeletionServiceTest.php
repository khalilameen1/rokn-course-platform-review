<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;
use App\Services\CourseAuthoringDeletionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CourseAuthoringDeletionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('list_id');
            $table->string('bunny_video_id')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->timestamps();
        });
        Schema::create('bunny_video_cleanup_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('video_guid', 64)->unique();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->string('reason', 48);
            $table->timestamp('eligible_after');
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('remote_deleted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('requires_review')->default(true);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
            $table->foreign('lesson_id')->references('id')->on('lessons')->nullOnDelete();
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('module_id');
            $table->string('section_type');
            $table->string('sectionable_type');
            $table->unsignedBigInteger('sectionable_id');
            $table->unsignedInteger('order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('project_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->text('submission_text')->nullable();
            $table->string('submission_file')->nullable();
            $table->json('submission_metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('project_feedback_threads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('submission_id');
            $table->unsignedBigInteger('project_id');
            $table->timestamps();
            $table->foreign('submission_id')->references('id')->on('project_submissions')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
        Schema::create('project_feedback_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->timestamps();
            $table->foreign('thread_id')->references('id')->on('project_feedback_threads')->cascadeOnDelete();
        });
        Schema::create('ai_input_attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('storage_disk')->nullable();
            $table->string('storage_path')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'ai_input_attachments', 'project_feedback_messages', 'project_feedback_threads',
            'project_submissions', 'course_sections', 'projects',
            'bunny_video_cleanup_candidates', 'lessons',
            'course_modules',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_deleting_a_module_removes_learning_content_and_project_transient_state(): void
    {
        $moduleId = DB::table('course_modules')->insertGetId([
            'course_id' => 8,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lessonId = DB::table('lessons')->insertGetId([
            'list_id' => 8,
            'bunny_video_id' => '11111111-1111-1111-1111-111111111111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $projectId = DB::table('projects')->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lessonSectionId = $this->section($moduleId, Lesson::class, $lessonId, 1);
        $projectSectionId = $this->section($moduleId, Project::class, $projectId, 2);
        $submissionId = DB::table('project_submissions')->insertGetId([
            'project_id' => $projectId,
            'submission_text' => 'temporary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $threadId = DB::table('project_feedback_threads')->insertGetId([
            'submission_id' => $submissionId,
            'project_id' => $projectId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('project_feedback_messages')->insert([
            'thread_id' => $threadId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deletedIds = DB::transaction(fn (): array => app(CourseAuthoringDeletionService::class)
            ->deleteModule(CourseModule::query()->findOrFail($moduleId)));

        self::assertSame([$lessonSectionId, $projectSectionId], $deletedIds);
        self::assertFalse(CourseModule::query()->whereKey($moduleId)->exists());
        self::assertTrue(CourseSection::withTrashed()->whereKey($lessonSectionId)->firstOrFail()->trashed());
        self::assertTrue(CourseSection::withTrashed()->whereKey($projectSectionId)->firstOrFail()->trashed());
        self::assertFalse(Lesson::query()->whereKey($lessonId)->exists());
        self::assertDatabaseHas('bunny_video_cleanup_candidates', [
            'video_guid' => '11111111-1111-1111-1111-111111111111',
            'lesson_id' => null,
            'reason' => 'section_deleted',
        ]);
        self::assertFalse(Project::query()->whereKey($projectId)->exists());
        self::assertSame(0, DB::table('project_submissions')->count());
        self::assertSame(0, DB::table('project_feedback_threads')->count());
        self::assertSame(0, DB::table('project_feedback_messages')->count());
    }

    private function section(int $moduleId, string $type, int $contentId, int $order): int
    {
        return DB::table('course_sections')->insertGetId([
            'course_id' => 8,
            'module_id' => $moduleId,
            'section_type' => $type === Project::class ? 'project' : 'lesson',
            'sectionable_type' => $type,
            'sectionable_id' => $contentId,
            'order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
