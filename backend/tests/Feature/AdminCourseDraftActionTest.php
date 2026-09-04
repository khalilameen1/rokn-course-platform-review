<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminCourseDraftActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_can_idempotently_start_and_resume_a_published_course_draft(): void
    {
        $moderator = new User();
        $moderator->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => 'draft-moderator@example.test',
            'role' => 'moderator',
            'active' => true,
        ])->save();
        $canonical = new Course();
        $canonical->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'الكورس المنشور',
            'price' => 800,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'is_main_course' => true,
            'authoring_version' => 7,
            'last_published_authoring_version' => 7,
            'published_at' => now(),
        ])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($moderator, 'web');

        $first = $this->postJson(route('admin.courses.draft.start', $canonical))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('canonical_course_id', $canonical->id)
            ->assertJsonStructure([
                'success', 'canonical_course_id', 'draft_course_id', 'authoring_version',
            ]);
        $draftId = (int) $first->json('draft_course_id');
        $draft = Course::query()->findOrFail($draftId);

        self::assertNotSame((int) $canonical->id, $draftId);
        self::assertTrue((bool) $draft->is_coming_soon);
        self::assertFalse((bool) $draft->is_catalog_visible);
        self::assertFalse((bool) $draft->is_main_course);
        self::assertSame((int) $draft->authoring_version, (int) $first->json('authoring_version'));
        self::assertSame(1, CourseAuthoringRevision::query()
            ->where('canonical_course_id', $canonical->id)
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->count());

        $this->postJson(route('admin.courses.draft.start', $canonical))
            ->assertOk()
            ->assertJsonPath('canonical_course_id', $canonical->id)
            ->assertJsonPath('draft_course_id', $draftId)
            ->assertJsonPath('authoring_version', (int) $draft->authoring_version);
        self::assertSame(1, CourseAuthoringRevision::query()
            ->where('canonical_course_id', $canonical->id)
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->count());

        $this->post(route('admin.courses.draft.start', $draft))
            ->assertRedirect(route('admin.courses.show', $draft));
        self::assertSame(1, CourseAuthoringRevision::query()
            ->where('canonical_course_id', $canonical->id)
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->count());
    }
}
