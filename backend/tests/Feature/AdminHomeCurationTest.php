<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminHomeCurationTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->administrator = new User();
        $this->administrator->forceFill([
            'name_ar' => 'مدير المحتوى',
            'email' => 'home-curation@example.test',
            'role' => 'admin',
            'active' => true,
        ])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($this->administrator, 'web');
    }

    public function test_a_published_course_can_be_selected_in_more_than_one_home_row(): void
    {
        $course = $this->publishedCourse('كورس مشترك', 20);
        $other = $this->publishedCourse('كورس ثان', 10);

        $this->post(route('admin.classifications.store'), [
            'name_ar' => 'الأكثر مشاهدة',
            'name_en' => 'Most watched',
            'show_on_home' => '1',
            'home_order' => 20,
            'course_ids' => [$course->id, $other->id],
            'authoring_request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('admin.classifications.index'));
        $this->post(route('admin.classifications.store'), [
            'name_ar' => 'اختيارات ركن',
            'name_en' => 'Rokn picks',
            'show_on_home' => '1',
            'home_order' => 10,
            'course_ids' => [$course->id],
            'authoring_request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('admin.classifications.index'));

        $first = Classification::query()->where('name_en', 'Most watched')->firstOrFail();
        $second = Classification::query()->where('name_en', 'Rokn picks')->firstOrFail();
        self::assertEqualsCanonicalizing(
            [$course->id, $other->id],
            $first->courses()->pluck('courses.id')->all()
        );
        self::assertSame([$course->id], $second->courses()->pluck('courses.id')->all());
        self::assertSame(
            ['Rokn picks', 'Most watched'],
            Classification::query()->orderBy('home_order')->pluck('name_en')->all()
        );

        $catalogue = collect(
            $this->getJson('/api/v1/courses/list?per_page=50')
                ->assertOk()
                ->json('data.courses')
        );
        self::assertSame(
            [$other->id, $course->id],
            $catalogue->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );
        $sharedPayload = $catalogue->firstWhere('id', $course->id);
        $sharedRows = collect($sharedPayload['tags'] ?? [])->sortBy('home_order')->values();
        self::assertSame(
            ['Rokn picks', 'Most watched'],
            $sharedRows->pluck('name_en')->all()
        );
        self::assertSame([10, 20], $sharedRows->pluck('home_order')->all());
    }

    public function test_row_editor_rejects_stale_membership_and_non_public_courses(): void
    {
        $selected = $this->publishedCourse('منشور', 10);
        $newerSelection = $this->publishedCourse('منشور ثان', 20);
        $draft = $this->course('مسودة', true, false, 30);
        $row = Classification::query()->create([
            'name_ar' => 'صف',
            'name_en' => 'Row',
            'show_on_home' => true,
            'home_order' => 10,
        ]);
        $row->courses()->sync([$selected->id]);

        $edit = $this->get(route('admin.classifications.edit', $row))->assertOk();
        $editorVersion = (string) $edit->original->getData()['editorVersion'];

        $row->courses()->sync([$newerSelection->id]);
        $this->put(route('admin.classifications.update', $row), [
            'name_ar' => 'صف',
            'name_en' => 'Row',
            'show_on_home' => '1',
            'home_order' => 10,
            'course_ids' => [$selected->id],
            'editor_version' => $editorVersion,
        ])->assertSessionHasErrors('editor_version');
        self::assertSame([$newerSelection->id], $row->courses()->pluck('courses.id')->all());

        $freshEdit = $this->get(route('admin.classifications.edit', $row))->assertOk();
        $freshVersion = (string) $freshEdit->original->getData()['editorVersion'];
        $this->put(route('admin.classifications.update', $row), [
            'name_ar' => 'صف',
            'name_en' => 'Row',
            'show_on_home' => '1',
            'home_order' => 10,
            'course_ids' => [$draft->id],
            'editor_version' => $freshVersion,
        ])->assertSessionHasErrors('course_ids');
        self::assertSame([$newerSelection->id], $row->courses()->pluck('courses.id')->all());
    }

    public function test_visible_coming_soon_course_can_be_assigned_to_a_home_row_and_invalidates_cached_tags(): void
    {
        $visible = $this->course('قريبًا مختار', true, true, 10);
        $hidden = $this->course('قريبًا مخفي', true, false, 5);
        $row = Classification::query()->create([
            'name_ar' => 'قريبًا على ركن',
            'name_en' => 'Coming soon',
            'show_on_home' => true,
            'home_order' => 30,
        ]);

        $editor = $this->get(route('admin.classifications.edit', $row))->assertOk();
        $editor->assertSee('قريبًا مختار');
        $editor->assertDontSee('قريبًا مخفي');
        $editorVersion = (string) $editor->original->getData()['editorVersion'];

        $before = $this->getJson('/api/v1/courses/list?per_page=50')->assertOk();
        $beforeRevision = (int) $before->json('data.catalogue_revision');
        $beforeCourses = collect($before->json('data.courses'));
        $beforeVisiblePayload = $beforeCourses->firstWhere('id', $visible->id);
        self::assertIsArray($beforeVisiblePayload);
        self::assertSame([], $beforeVisiblePayload['tags']);
        self::assertFalse($beforeCourses->contains('id', $hidden->id));

        // This changes the pivot membership only. The row's own fields remain
        // identical, so the assertion below proves its saved/after-commit
        // invalidation also covers the curated course list.
        $this->put(route('admin.classifications.update', $row), [
            'name_ar' => 'قريبًا على ركن',
            'name_en' => 'Coming soon',
            'show_on_home' => '1',
            'home_order' => 30,
            'course_ids' => [$visible->id],
            'editor_version' => $editorVersion,
        ])->assertRedirect(route('admin.classifications.index'));

        $after = $this->getJson('/api/v1/courses/list?per_page=50')->assertOk();
        $afterRevision = (int) $after->json('data.catalogue_revision');
        $courses = collect($after->json('data.courses'));
        $visiblePayload = $courses->firstWhere('id', $visible->id);

        self::assertIsArray($visiblePayload);
        self::assertTrue($visiblePayload['is_coming_soon']);
        self::assertSame(10, $visiblePayload['home_sort_order']);
        self::assertGreaterThan($beforeRevision, $afterRevision);
        self::assertSame(['Coming soon'], collect($visiblePayload['tags'])->pluck('name_en')->all());
        self::assertFalse($courses->contains('id', $hidden->id));

        $index = $this->get(route('admin.classifications.index'))->assertOk();
        self::assertSame(
            1,
            (int) $index->original->getData()['classifications']->firstWhere('id', $row->id)->home_courses_count
        );
    }

    public function test_membership_update_locks_courses_then_classification_before_sync(): void
    {
        $requested = $this->publishedCourse('المطلوب', 10);
        $current = $this->publishedCourse('الحالي', 20);
        $row = Classification::query()->create([
            'name_ar' => 'صف متزامن',
            'name_en' => 'Concurrent row',
            'show_on_home' => true,
            'home_order' => 10,
        ]);
        $row->courses()->sync([$current->id]);
        $edit = $this->get(route('admin.classifications.edit', $row))->assertOk();
        $editorVersion = (string) $edit->original->getData()['editorVersion'];

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
        });

        $this->put(route('admin.classifications.update', $row), [
            'name_ar' => 'صف متزامن',
            'name_en' => 'Concurrent row',
            'show_on_home' => '1',
            'home_order' => 10,
            'course_ids' => [$requested->id],
            'editor_version' => $editorVersion,
        ])->assertRedirect(route('admin.classifications.index'));

        $orderedIds = collect([$current->id, $requested->id])->sort()->values()->all();
        $lockQueryIndex = collect($queries)->search(fn (array $query): bool =>
            str_contains($query['sql'], 'from "courses"')
                && str_contains($query['sql'], 'order by "id" asc')
                && str_contains($query['sql'], 'in ('.implode(', ', $orderedIds).')')
        );
        $syncDeleteIndex = collect($queries)->search(fn (array $query): bool =>
            str_starts_with($query['sql'], 'delete from "classification_course"')
        );
        $classificationLockIndex = collect($queries)->search(fn (array $query): bool =>
            str_contains($query['sql'], 'from "classifications"')
                && str_contains($query['sql'], '"classifications"."id" = ?')
        );

        self::assertNotFalse($lockQueryIndex, 'Affected course IDs were not selected in stable order.');
        self::assertNotFalse($classificationLockIndex, 'The classification was not reloaded after course locks.');
        self::assertNotFalse($syncDeleteIndex, 'The membership replacement did not run.');
        self::assertLessThan($classificationLockIndex, $lockQueryIndex);
        self::assertLessThan($syncDeleteIndex, $classificationLockIndex);
        self::assertStringContainsString(
            '->lockForUpdate()',
            (string) file_get_contents(app_path('Http/Controllers/Admin/ClassificationController.php'))
        );
        self::assertSame([$requested->id], $row->courses()->pluck('courses.id')->all());
    }

    public function test_row_save_preserves_hidden_draft_membership_and_counts_only_canonical_courses(): void
    {
        $canonical = $this->publishedCourse('كورس له مسودة', 10);
        $row = Classification::query()->create([
            'name_ar' => 'صف محفوظ',
            'name_en' => 'Preserved row',
            'show_on_home' => true,
            'home_order' => 10,
        ]);
        $row->courses()->attach($canonical->id);
        $draft = app(CourseStagedAuthoringService::class)->draftFor($canonical);
        self::assertTrue(CourseAuthoringRevision::query()
            ->where('canonical_course_id', $canonical->id)
            ->where('revision_course_id', $draft->id)
            ->exists());
        self::assertTrue(DB::table('classification_course')
            ->where('classification_id', $row->id)
            ->where('course_id', $draft->id)
            ->exists());

        $edit = $this->get(route('admin.classifications.edit', $row))->assertOk();
        self::assertSame(
            [$canonical->id],
            $edit->original->getData()['selectedCourseIds']
        );
        $editorVersion = (string) $edit->original->getData()['editorVersion'];
        $this->put(route('admin.classifications.update', $row), [
            'name_ar' => 'صف محفوظ',
            'name_en' => 'Preserved row',
            'show_on_home' => '1',
            'home_order' => 10,
            'course_ids' => [$canonical->id],
            'editor_version' => $editorVersion,
        ])->assertRedirect(route('admin.classifications.index'));

        self::assertTrue(DB::table('classification_course')
            ->where('classification_id', $row->id)
            ->where('course_id', $draft->id)
            ->exists());
        self::assertSame(
            1,
            (int) $this->get(route('admin.classifications.index'))
                ->assertOk()
                ->original
                ->getData()['classifications']
                ->firstWhere('id', $row->id)
                ->home_courses_count
        );
    }

    public function test_row_save_preserves_hidden_canonical_membership_that_the_editor_cannot_select(): void
    {
        $visible = $this->publishedCourse('ظاهر في الاختيارات', 10);
        $hidden = $this->course('منشور مخفي مؤقتًا', false, false, 20);
        $row = Classification::query()->create([
            'name_ar' => 'صف يحتفظ بالتصنيف',
            'name_en' => 'Preserved taxonomy',
            'show_on_home' => true,
            'home_order' => 10,
        ]);
        $row->courses()->sync([$visible->id, $hidden->id]);

        $edit = $this->get(route('admin.classifications.edit', $row))->assertOk();
        $edit->assertSee('ظاهر في الاختيارات');
        $edit->assertDontSee('منشور مخفي مؤقتًا');
        self::assertSame([$visible->id], $edit->original->getData()['selectedCourseIds']);

        $this->put(route('admin.classifications.update', $row), [
            'name_ar' => 'صف يحتفظ بالتصنيف بعد التعديل',
            'name_en' => 'Preserved taxonomy',
            'show_on_home' => '1',
            'home_order' => 10,
            'course_ids' => [$visible->id],
            'editor_version' => (string) $edit->original->getData()['editorVersion'],
        ])->assertRedirect(route('admin.classifications.index'));

        self::assertEqualsCanonicalizing(
            [$visible->id, $hidden->id],
            $row->courses()->pluck('courses.id')->all()
        );
        self::assertSame(
            1,
            (int) $this->get(route('admin.classifications.index'))
                ->assertOk()
                ->original
                ->getData()['classifications']
                ->firstWhere('id', $row->id)
                ->home_courses_count
        );
    }

    private function publishedCourse(string $name, int $order): Course
    {
        $course = $this->course($name, false, true, $order);
        $module = CourseModule::query()->create([
            'course_id' => $course->id,
            'title_ar' => 'الوحدة الأولى',
            'order' => 1,
        ]);
        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'المقطع الأول',
            'description_ar' => 'محتوى المقطع',
            'video_source_type' => 'youtube',
            'video_link' => 'https://www.youtube.com/watch?v=home-curation',
            'is_opened' => true,
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id,
            'module_id' => $module->id,
            'title_ar' => 'المقطع الأول',
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => 1,
        ]);
        CourseAccessPlan::query()->create([
            'course_id' => $course->id,
            'code' => 'basic',
            'name_ar' => 'التعلّم',
            'price_coins' => 100,
            'certificate_enabled' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        return $course;
    }

    private function course(string $name, bool $comingSoon, bool $visible, int $order): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => $name,
            'description_ar' => 'وصف واضح للكورس',
            'image' => 'courses/home-curation.jpg',
            'price' => 100,
            'is_coming_soon' => $comingSoon,
            'is_catalog_visible' => $visible,
            'home_sort_order' => $order,
            'authoring_version' => 1,
        ])->save();

        return $course;
    }
}
