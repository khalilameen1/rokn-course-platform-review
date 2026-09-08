<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\LessonWatchEvidence;
use App\Models\User;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SectionCompletionRevisionAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Course,CourseSection,User} */
    private function fixture(bool $withEvidence = true): array
    {
        Http::preventStrayRequests();
        $course = (new Course())->forceFill([
            'tenant_id' => 1, 'name_ar' => 'كورس التقدم', 'description_ar' => 'وصف',
            'price' => 0, 'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 4, 'last_published_authoring_version' => 4,
            'published_at' => now(),
        ]);
        $course->save();
        $module = $course->modules()->create(['title_ar' => 'الوحدة', 'order' => 1]);
        $lesson = Lesson::create(['list_id' => $course->id, 'title_ar' => 'الدرس']);
        $section = $course->sections()->create([
            'module_id' => $module->id, 'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id, 'order' => 1,
        ]);
        LessonMediaState::create(['lesson_id' => $lesson->id, 'duration_seconds' => 60, 'status' => 'ready']);
        $user = (new User())->forceFill([
            'name_ar' => 'متعلم', 'email' => 'progress-'.$course->id.'@example.test', 'role' => 'client', 'active' => true,
        ]);
        $user->save();
        (new CourseEnrollment())->forceFill([
            'tenant_id' => 1, 'course_id' => $course->id, 'user_id' => $user->id, 'is_active' => true,
        ])->save();
        // The heartbeat committed evidence, but its completion step failed;
        // the phone retained the existing explicit completion command offline.
        if ($withEvidence) {
            LessonWatchEvidence::create([
                'user_id' => $user->id, 'lesson_id' => $lesson->id, 'course_section_id' => $section->id,
                'duration_seconds' => 60, 'verified_seconds' => 60, 'completed_at' => now(),
            ]);
        }
        return [$course, $section, $user];
    }

    private function publish(Course $course, bool $deleteSection = false): void
    {
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->once()->andReturn(['ready' => true, 'issues' => []]);
        $revisions = new CourseStagedAuthoringService($publishing);
        $draft = $revisions->draftFor($course->fresh());
        if ($deleteSection) {
            $removed = $draft->sections()->firstOrFail();
            $removed->delete();
            // Keep a valid current learning graph: rejection must come from
            // missing lineage, not an accidentally empty unpublished course.
            $replacement = Lesson::create(['list_id' => $draft->id, 'title_ar' => 'درس آخر']);
            $draft->sections()->create([
                'module_id' => $removed->module_id, 'sectionable_type' => Lesson::class,
                'sectionable_id' => $replacement->id, 'order' => 1,
            ]);
        }
        $revisions->publish($draft, (int) $draft->authoring_version, true);
    }

    public function test_a_queued_completion_of_a_surviving_section_is_acknowledged_after_publication(): void
    {
        [$course, $section, $user] = $this->fixture();
        $this->publish($course);
        $this->publish($course);
        $current = $course->sections()->firstOrFail();
        self::assertNotSame($section->id, $current->id);
        self::assertSame(0, DB::table('student_section_progress')->count());
        $this->actingAs($user, 'api');
        $oldUrl = '/api/v1/courses/'.$course->id.'/sections/'.$section->id.'/complete';
        $response = $this->postJson($oldUrl);
        // Counterevidence: the same evidence already authorizes the current ID.
        $this->postJson('/api/v1/courses/'.$course->id.'/sections/'.$current->id.'/complete')
            ->assertOk()->assertJsonPath('data.section.is_completed', true);
        $response->assertOk()->assertJsonPath('data.section.is_completed', true);
        $this->postJson($oldUrl)->assertOk();
        self::assertSame(1, DB::table('student_section_progress')->count());
        self::assertSame($current->id, (int) DB::table('student_section_progress')->value('course_section_id'));
        Http::assertNothingSent();
    }

    public function test_replaying_a_completion_already_committed_before_publication_does_not_count_again(): void
    {
        [$course, $section, $user] = $this->fixture();
        $this->actingAs($user, 'api');
        $url = '/api/v1/courses/'.$course->id.'/sections/'.$section->id.'/complete';
        $this->postJson($url)->assertOk();
        $this->publish($course);
        $this->postJson($url)->assertOk()->assertJsonPath('data.section.is_completed', true);
        self::assertSame(1, DB::table('student_section_progress')->count());
        self::assertSame($section->id, (int) DB::table('student_section_progress')->value('course_section_id'));
        Http::assertNothingSent();
    }

    #[DataProvider('rejections')]
    public function test_historical_identity_does_not_bypass_current_completion_checks(string $reason, int $status): void
    {
        [$course, $section, $user] = $this->fixture($reason !== 'no evidence');
        $this->publish($course, $reason === 'deleted');
        if ($reason === 'revoked') {
            CourseEnrollment::query()->update(['is_active' => false]);
        } elseif ($reason === 'wrong course') {
            [$course] = $this->fixture(false);
        } elseif ($reason === 'unknown section') {
            $section = new CourseSection();
            $section->id = 0;
        } elseif ($reason === 'unpublished') {
            $course->fresh()->forceFill(['is_coming_soon' => true])->save();
        }
        $this->actingAs($user, 'api');
        $response = $this->postJson('/api/v1/courses/'.$course->id.'/sections/'.$section->id.'/complete')
            ->assertStatus($status);
        if ($reason === 'no evidence') {
            $response->assertJsonPath('code', 'verified_watch_required');
        }
        self::assertSame(0, DB::table('student_section_progress')->count());
        Http::assertNothingSent();
    }

    public static function rejections(): array
    {
        return [
            ['no evidence', 409], ['revoked', 403], ['deleted', 404],
            ['wrong course', 404], ['unknown section', 404], ['unpublished', 404],
        ];
    }
}
