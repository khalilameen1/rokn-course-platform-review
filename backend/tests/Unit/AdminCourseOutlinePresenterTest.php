<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;
use App\Services\AdminCourseOutlinePresenter;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

final class AdminCourseOutlinePresenterTest extends TestCase
{
    public function test_initial_graph_and_mutation_nodes_share_one_editor_contract(): void
    {
        $course = $this->model(new Course(), [
            'id' => 40,
            'authoring_version' => 7,
        ]);
        $module = $this->model(new CourseModule(), [
            'id' => 50,
            'course_id' => 40,
            'title_ar' => 'الوحدة الأولى',
            'order' => 1,
        ]);
        $lesson = $this->model(new Lesson(), [
            'id' => 60,
            'list_id' => 40,
            'description_ar' => 'كابشن واضح',
            'duration_minutes' => 3,
            'is_opened' => true,
            'bunny_video_id' => 'video-guid',
        ]);
        $lessonSection = $this->model(new CourseSection(), [
            'id' => 70,
            'course_id' => 40,
            'module_id' => 50,
            'sectionable_type' => Lesson::class,
            'sectionable_id' => 60,
            'title_ar' => 'المقطع الأول',
            'order' => 1,
        ]);
        $lessonSection->setRelation('sectionable', $lesson);

        $project = $this->model(new Project(), [
            'id' => 61,
            'requirements_text_ar' => 'نفذ المطلوب',
            'submission_text_enabled' => true,
            'submission_allowed_mime_types' => ['application/pdf'],
        ]);
        $projectSection = $this->model(new CourseSection(), [
            'id' => 71,
            'course_id' => 40,
            'module_id' => 50,
            'sectionable_type' => Project::class,
            'sectionable_id' => 61,
            'title_ar' => 'مشروع العبور',
            'order' => 2,
        ]);
        $projectSection->setRelation('sectionable', $project);
        $module->setRelation('sections', new Collection([$lessonSection, $projectSection]));
        $course->setRelation('modules', new Collection([$module]));

        $presenter = app(AdminCourseOutlinePresenter::class);
        $graph = $presenter->graph($course);
        $moduleNode = $presenter->module($course, $module);
        $lessonNode = $presenter->section($course, $lessonSection);

        self::assertSame(7, $graph['authoring_version']);
        self::assertSame($moduleNode, $graph['modules'][0]);
        self::assertSame($lessonNode, $graph['modules'][0]['sections'][0]);
        self::assertSame('كابشن واضح', $lessonNode['lesson_description_ar']);
        self::assertTrue($lessonNode['has_video']);
        self::assertStringContainsString('/dashboard/courses/40/sections/70', $lessonNode['update_url']);
        self::assertSame(
            ['text', 'pdf'],
            $graph['modules'][0]['sections'][1]['project_submission_types']
        );
        self::assertArrayNotHasKey('bunny_video_id', $lessonNode);
    }

    private function model(object $model, array $attributes): object
    {
        $model->forceFill($attributes);
        $model->exists = true;

        return $model;
    }
}
