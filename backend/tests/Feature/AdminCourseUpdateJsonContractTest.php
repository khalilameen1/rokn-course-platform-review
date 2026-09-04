<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
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
