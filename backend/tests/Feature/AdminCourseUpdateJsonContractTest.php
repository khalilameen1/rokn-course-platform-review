<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Classification;
use App\Models\Course;
use App\Models\User;
use App\Services\AdminCourseEditorStatePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminCourseUpdateJsonContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_studio_form_data_save_returns_the_canonical_editor_state(): void
    {
        $moderator = $this->moderator();
        $course = $this->draftCourse();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($moderator, 'web')
            ->withHeader('Accept', 'application/json')
            ->post(route('admin.courses.update', $course), [
                '_method' => 'PATCH',
                'name_ar' => 'عنوان محدث',
                'authoring_version' => 3,
                'certificate_text_template_key' => 'completion',
                'publishing_intent' => 'save',
                'return_to' => 'studio',
            ])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'saved' => true,
                'published' => false,
                'status' => 'updated',
                'message' => 'تم تحديث الكورس بنجاح',
                'authoring_version' => 4,
                'course' => [
                    'id' => $course->id,
                    'title' => 'عنوان محدث',
                    'authoring_version' => 4,
                    'publishing_status' => 'draft',
                    'studio_url' => route('admin.courses.show', $course),
                    'image_url' => null,
                ],
            ]);

        self::assertSame(4, (int) $course->fresh()->authoring_version);
    }

    public function test_existing_html_update_flow_keeps_its_studio_redirect(): void
    {
        $course = $this->draftCourse();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($this->moderator(), 'web')
            ->post(route('admin.courses.update', $course), [
                '_method' => 'PATCH',
                'name_ar' => 'عنوان HTML',
                'authoring_version' => 3,
                'certificate_text_template_key' => 'completion',
                'publishing_intent' => 'save',
                'return_to' => 'studio',
            ])
            ->assertRedirect(route('admin.courses.show', $course));
    }

    public function test_summary_read_uses_saved_course_and_readiness_without_replacing_editors(): void
    {
        $course = $this->draftCourse();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($this->moderator(), 'web');
        $url = route('admin.courses.show', [$course, 'summary' => 1]);
        $before = $this->getJson($url)->assertOk()->json('html');
        self::assertStringContainsString('أضف وصفًا مختصرًا يوضح نتيجة الكورس', $before);

        $this->patchJson(route('admin.courses.update', $course), [
            'name_ar' => 'عنوان محفوظ',
            'description_ar' => 'وصف محفوظ بعد التعديل',
            'authoring_version' => 3,
            'publishing_intent' => 'save',
        ])->assertOk();

        $response = $this->getJson($url)->assertOk()
            ->assertJsonPath('course_id', $course->id)
            ->assertJsonPath('authoring_version', 4)
            ->assertHeader('Cache-Control', 'no-store, private');
        $html = $response->json('html');
        self::assertStringContainsString('عنوان محفوظ', $html);
        self::assertStringContainsString('وصف محفوظ بعد التعديل', $html);
        self::assertStringNotContainsString('أضف وصفًا مختصرًا يوضح نتيجة الكورس', $html);
        foreach (['course', 'instructor', 'readiness'] as $region) {
            self::assertStringContainsString('data-studio-summary="'.$region.'"', $html);
        }
        self::assertStringNotContainsString('studioCourseForm', $html);
        self::assertStringNotContainsString('OpenRouter', $html);
    }

    public function test_laravel_validation_errors_remain_json_for_the_studio_form(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($this->moderator(), 'web')
            ->patchJson(route('admin.courses.update', $this->draftCourse()), [
                'name_ar' => '',
                'authoring_version' => 3,
                'certificate_text_template_key' => 'completion',
                'publishing_intent' => 'save',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name_ar');
    }

    public function test_partial_patch_preserves_omitted_relations_publication_state_and_plan_price(): void
    {
        $course = $this->draftCourse();
        $moderator = $this->moderator();
        $classification = Classification::query()->create([
            'name_ar' => 'تصنيف ثابت',
            'name_en' => 'Stable category',
        ]);
        $teacher = new User();
        $teacher->forceFill([
            'name_ar' => 'مدرب ثابت',
            'email' => 'stable-teacher@example.test',
            'role' => 'teacher',
            'active' => true,
        ])->save();
        $course->classifications()->attach($classification->id);
        $course->teachers()->attach($teacher->id);
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($moderator, 'web')
            ->patchJson(route('admin.courses.update', $course), [
                'authoring_version' => 3,
                // Crafted legacy fields must not bypass their owning services.
                'price' => 1,
                'is_coming_soon' => false,
            ])
            ->assertOk();

        $course->refresh();
        self::assertSame('مسودة الكورس', $course->name_ar);
        self::assertSame(800, (int) $course->price);
        self::assertTrue((bool) $course->is_coming_soon);
        self::assertFalse((bool) $course->is_catalog_visible);
        self::assertTrue($course->classifications()->whereKey($classification->id)->exists());
        self::assertTrue($course->teachers()->whereKey($teacher->id)->exists());

        $this->actingAs($moderator, 'web')
            ->patchJson(route('admin.courses.update', $course), [
                'authoring_version' => 4,
                'classification_ids_present' => false,
                'teacher_ids_present' => false,
            ])
            ->assertOk();

        self::assertTrue($course->classifications()->whereKey($classification->id)->exists());
        self::assertTrue($course->teachers()->whereKey($teacher->id)->exists());

        $this->actingAs($moderator, 'web')
            ->patchJson(route('admin.courses.update', $course), [
                'authoring_version' => 5,
                'classification_ids_present' => true,
                'teacher_ids_present' => true,
            ])
            ->assertOk();

        self::assertFalse($course->classifications()->exists());
        self::assertFalse($course->teachers()->exists());
    }

    public function test_studio_round_trip_preserves_only_the_existing_legacy_admin_instructor(): void
    {
        $course = $this->draftCourse();
        $linkedAdministrator = $this->instructor('admin', 'linked-admin-instructor@example.test');
        $unrelatedAdministrator = $this->instructor('admin', 'private-admin@example.test');
        $teacher = $this->instructor('teacher', 'teacher-option@example.test');
        $course->teachers()->attach([$linkedAdministrator->id, $teacher->id]);
        $this->withoutMiddleware(RequireAdminMfa::class);

        $studio = $this->actingAs($this->moderator(), 'web')
            ->get(route('admin.courses.show', $course))
            ->assertOk();
        $teacherOptions = $studio->original->getData()['teachers'];
        $optionIds = $teacherOptions->modelKeys();

        self::assertContains($linkedAdministrator->id, $optionIds);
        self::assertContains($teacher->id, $optionIds);
        self::assertNotContains($unrelatedAdministrator->id, $optionIds);

        $this->patchJson(route('admin.courses.update', $course), [
            'authoring_version' => 3,
            'teacher_ids_present' => true,
            'teacher_ids' => $optionIds,
        ])->assertOk();

        self::assertTrue($course->teachers()->whereKey($linkedAdministrator->id)->exists());
        self::assertTrue($course->teachers()->whereKey($teacher->id)->exists());
        self::assertFalse($course->teachers()->whereKey($unrelatedAdministrator->id)->exists());
    }

    public function test_every_authoring_result_uses_the_same_editor_payload_shape(): void
    {
        $course = $this->draftCourse();
        $presenter = app(AdminCourseEditorStatePresenter::class);
        $statuses = [
            'updated' => [true, 200],
            'live_incomplete' => [false, 422],
            'save_failed' => [false, 500],
            'staged_publish_failed' => [true, 200],
            'publish_failed' => [true, 200],
            'not_ready' => [true, 200],
            'catalog_publish_failed' => [true, 200],
            'catalog_not_ready' => [true, 200],
            'hero_failed' => [true, 200],
        ];

        foreach ($statuses as $status => [$success, $httpStatus]) {
            $result = $presenter->result([
                'status' => $status,
                'course' => $course,
                'issues' => $status === 'not_ready' ? ['أضف مقطعًا'] : [],
            ]);
            self::assertSame($httpStatus, $result['http_status'], $status);
            self::assertSame($success, $result['payload']['success'], $status);
            self::assertSame($success, $result['payload']['saved'], $status);
            self::assertFalse($result['payload']['published'], $status);
            self::assertSame($status, $result['payload']['status']);
            self::assertSame(3, $result['payload']['authoring_version']);
            self::assertSame([
                'id', 'title', 'authoring_version', 'publishing_status', 'studio_url', 'image_url',
            ], array_keys($result['payload']['course']));
            if ($status === 'not_ready') {
                self::assertSame(['أضف مقطعًا'], $result['payload']['issues']);
            } else {
                self::assertArrayNotHasKey('issues', $result['payload']);
            }
            if ($success && $status !== 'updated') {
                self::assertSame($result['payload']['message'], $result['payload']['warning']);
            } else {
                self::assertArrayNotHasKey('warning', $result['payload']);
            }
        }
    }

    private function moderator(): User
    {
        $user = new User();
        $user->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => 'course-json-moderator@example.test',
            'role' => 'moderator',
            'active' => true,
        ])->save();

        return $user;
    }

    private function instructor(string $role, string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name_ar' => $email,
            'email' => $email,
            'role' => $role,
            'active' => true,
        ])->save();

        return $user;
    }

    private function draftCourse(): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'مسودة الكورس',
            'price' => 800,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'authoring_version' => 3,
            'certificate_text_template_key' => 'completion',
        ])->save();

        return $course;
    }
}
