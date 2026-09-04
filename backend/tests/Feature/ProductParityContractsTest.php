<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Models\Course;
use App\Models\CourseRating;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Path;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProductParityContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
    }

    public function test_only_real_playback_preferences_are_account_scoped_and_returned_by_profile(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'api')->putJson('/api/v1/user/profile', [
            'video_quality_preference' => '720p',
            'playback_speed' => 1.5,
        ])->assertOk()
            ->assertJsonPath('data.video_quality_preference', '720p')
            ->assertJsonPath('data.playback_speed', 1.5)
            ->assertJsonMissingPath('data.autoplay_next_enabled')
            ->assertJsonMissingPath('data.video_fit_mode');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'video_quality_preference' => '720p',
        ]);
    }

    public function test_retired_playback_switches_cannot_create_hidden_account_settings(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'api')->putJson('/api/v1/user/profile', [
            'autoplay_next_enabled' => false,
            'video_fit_mode' => 'contain',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'autoplay_next_enabled',
                'video_fit_mode',
            ]);
    }

    public function test_compact_search_uses_keywords_without_exposing_commercial_terms(): void
    {
        $course = $this->course([
            'name_ar' => 'أساسيات التصميم',
            'name_en' => 'Design Basics',
            'description_ar' => 'كورس عملي',
            'price' => 900,
            'is_coming_soon' => false,
            'search_keywords_ar' => 'هوية بصرية شعارات',
        ]);
        $lesson = Lesson::create([
            'list_id' => $course->id,
            'title' => 'الخطوة الأولى',
            'title_ar' => 'الخطوة الأولى',
            'is_opened' => true,
        ]);
        $moduleId = DB::table('course_modules')->insertGetId([
            'course_id' => $course->id,
            'title' => 'المحتوى',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $course->id,
            'module_id' => $moduleId,
            'title' => 'البداية',
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/search/courses?q=شعارات')
            ->assertOk()
            ->assertJsonPath('data.items.0.course_id', $course->id)
            ->assertJsonPath('data.items.0.title', 'أساسيات التصميم')
            ->assertJsonMissingPath('data.items.0.price')
            ->assertJsonMissingPath('data.items.0.access_plans')
            ->assertJsonMissingPath('data.items.0.modules');
    }

    public function test_feedback_is_private_server_owned_and_accepts_anonymous_reports(): void
    {
        Storage::fake('feedback');
        $clientRequestId = (string) \Illuminate\Support\Str::uuid();
        DB::table('app_versions')->insert([
            'platform' => 'android',
            'distribution_channel' => 'direct',
            'version_name' => '1.0.22',
            'version_code' => 22,
            'is_force_update' => false,
            'is_active' => true,
            'download_url' => 'https://rokn.app/downloads/rokn-1.0.22.apk',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $headers = [
            'X-Rokn-Platform' => 'android',
            'X-Rokn-App-Version' => '1.0.22',
            'X-Rokn-App-Build' => '22',
        ];
        $response = $this->withHeaders($headers)->postJson('/api/v1/feedback', [
            'client_request_id' => $clientRequestId,
            'category' => 'suggestion',
            'message' => 'Please add a clearer explanation to this screen.',
            'screen_key' => 'settings.feedback',
            'platform' => 'android',
            'app_version' => 'forged-value',
            'build_number' => 99999,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'received');

        $publicId = $response->json('data.public_id');
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $publicId);
        $this->assertDatabaseHas('feedback_reports', [
            'public_id' => $publicId,
            'user_id' => null,
            'category' => 'suggestion',
            'app_version' => '1.0.22',
            'build_number' => 22,
        ]);
        $response->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.ip_hash');

        $this->withHeaders($headers)->postJson('/api/v1/feedback', [
            'client_request_id' => $clientRequestId,
            'category' => 'suggestion',
            'message' => 'Please add a clearer explanation to this screen.',
            'screen_key' => 'settings.feedback',
            'platform' => 'android',
            'app_version' => 'forged-value',
            'build_number' => 99999,
        ])->assertOk()
            ->assertJsonPath('data.public_id', $publicId)
            ->assertJsonPath('data.replayed', true);
        $this->assertDatabaseCount('feedback_reports', 1);
    }

    public function test_course_details_exposes_the_verified_total_duration_in_minutes(): void
    {
        $path = Path::create([
            'title_ar' => 'مسار المدة',
            'title_en' => 'Duration path',
        ]);
        $course = $this->course([
            'name_ar' => 'Duration course',
            'price' => 300,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'is_main_course' => true,
            'path_id' => $path->id,
        ]);
        $declaredLesson = Lesson::create([
            'list_id' => $course->id,
            'title' => 'Declared duration',
            'duration_minutes' => 12,
            'is_opened' => true,
        ]);
        $providerLesson = Lesson::create([
            'list_id' => $course->id,
            'title' => 'Provider duration',
            // The authored estimate must not override verified Bunny timing.
            'duration_minutes' => 1,
            'is_opened' => true,
            'video_source_type' => 'bunny',
            'bunny_video_id' => 'duration-test',
        ]);
        LessonMediaState::create([
            'lesson_id' => $providerLesson->id,
            'provider' => 'bunny',
            'provider_media_id' => 'duration-test',
            'status' => 'ready',
            'protocol' => 'hls',
            'duration_seconds' => 125,
            'integrity_status' => 'healthy',
            'last_reconciled_at' => now(),
        ]);

        $moduleId = DB::table('course_modules')->insertGetId([
            'course_id' => $course->id,
            'title' => 'Duration module',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$declaredLesson, $providerLesson] as $index => $lesson) {
            DB::table('course_sections')->insert([
                'course_id' => $course->id,
                'module_id' => $moduleId,
                'title' => 'Lesson '.($index + 1),
                'section_type' => 'lesson',
                'sectionable_type' => Lesson::class,
                'sectionable_id' => $lesson->id,
                'order' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->getJson("/api/v1/courses/{$course->id}/details")
            ->assertOk()
            ->assertJsonPath('data.metadata.duration_minutes', 15)
            ->assertJsonMissingPath('data.sections')
            ->assertJsonPath('data.modules.0.sections.1.content.duration_seconds', 125);

        $this->getJson('/api/v1/courses/list?per_page=50')
            ->assertOk()
            ->assertJsonPath('data.courses.0.id', $course->id)
            ->assertJsonPath('data.courses.0.metadata.duration_minutes', 15);

        $this->getJson('/api/v1/paths')
            ->assertOk()
            ->assertJsonPath('data.0.courses.0.id', $course->id)
            ->assertJsonPath('data.0.courses.0.metadata.duration_minutes', 15);
    }

    public function test_guest_course_social_proof_uses_real_enrollments_and_ratings(): void
    {
        $course = $this->course([
            'name_ar' => 'كورس الدليل الاجتماعي',
            'price' => 300,
            'students_count' => 900,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
        ]);
        $lesson = Lesson::create([
            'list_id' => $course->id,
            'title' => 'مقطع منشور',
            'duration_minutes' => 7,
            'is_opened' => true,
        ]);
        $moduleId = DB::table('course_modules')->insertGetId([
            'course_id' => $course->id,
            'title' => 'الوحدة الأولى',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $course->id,
            'module_id' => $moduleId,
            'title' => 'مقطع منشور',
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $activeLearners = [$this->user(), $this->user()];
        $inactiveLearner = $this->user();
        $deletedLearner = $this->user();
        foreach ($activeLearners as $learner) {
            DB::table('course_enrollments')->insert([
                'tenant_id' => 1,
                'user_id' => $learner->id,
                'course_id' => $course->id,
                'enrolled_at' => now(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('course_enrollments')->insert([
            'tenant_id' => 1,
            'user_id' => $inactiveLearner->id,
            'course_id' => $course->id,
            'enrolled_at' => now(),
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_enrollments')->insert([
            'tenant_id' => 1,
            'user_id' => $deletedLearner->id,
            'course_id' => $course->id,
            'enrolled_at' => now(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->where('id', $deletedLearner->id)->update(['deleted_at' => now()]);
        (new CourseRating())->forceFill([
            'user_id' => $activeLearners[0]->id,
            'course_id' => $course->id,
            'rating' => 5,
            'version' => 1,
        ])->save();
        (new CourseRating())->forceFill([
            'user_id' => $activeLearners[1]->id,
            'course_id' => $course->id,
            'rating' => 4,
            'version' => 1,
        ])->save();

        $this->getJson("/api/v1/courses/{$course->id}/details")
            ->assertOk()
            ->assertJsonPath('data.metadata.duration_minutes', 7)
            ->assertJsonPath('data.metadata.students_count', 2)
            ->assertJsonPath('data.ratings_count', 2)
            ->assertJsonPath('data.average_rating', 4.5);
    }

    public function test_learning_dashboard_returns_only_a_valid_resume_projection(): void
    {
        $user = $this->user([
            'watch_history_enabled' => true,
        ]);
        $course = $this->course([
            'name_ar' => 'كورس الاستئناف',
            'price' => 300,
            'is_coming_soon' => false,
        ]);
        $lesson = Lesson::create([
            'list_id' => $course->id,
            'title' => 'الدرس الحالي',
            'title_ar' => 'الدرس الحالي',
            'is_opened' => false,
        ]);
        $moduleId = DB::table('course_modules')->insertGetId([
            'course_id' => $course->id,
            'title' => 'المحتوى',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = DB::table('course_sections')->insertGetId([
            'course_id' => $course->id,
            'module_id' => $moduleId,
            'title' => 'القسم الحالي',
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nextLesson = Lesson::create([
            'list_id' => $course->id,
            'title' => 'الدرس التالي',
            'title_ar' => 'الدرس التالي',
            'is_opened' => false,
        ]);
        $nextSectionId = DB::table('course_sections')->insertGetId([
            'course_id' => $course->id,
            'module_id' => $moduleId,
            'title' => 'الدرس التالي',
            'title_ar' => 'الدرس التالي',
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $nextLesson->id,
            'order' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_enrollments')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'is_active' => true,
            'access_granted_at' => now()->subDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'api')->getJson('/api/v1/learning/courses')
            ->assertOk()
            ->assertJsonPath('data.items.0.learning_started', false)
            ->assertJsonPath('data.items.0.resume.available', false);

        DB::table('watching_logs')->insert([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'lesson_name' => 'الدرس الحالي',
            'course_id' => $course->id,
            'course_section_id' => $sectionId,
            'course_name' => 'كورس الاستئناف',
            'position_seconds' => 42,
            'duration_seconds' => 120,
            'watched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('student_section_progress')->insert([
            'user_id' => $user->id,
            'course_section_id' => $sectionId,
            'is_completed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'api')->getJson('/api/v1/learning/courses')
            ->assertOk()
            ->assertJsonPath('data.items.0.learning_started', true)
            ->assertJsonPath('data.items.0.resume.available', true)
            ->assertJsonPath('data.items.0.resume.lesson_id', $lesson->id)
            ->assertJsonPath('data.items.0.resume.position_seconds', 42)
            ->assertJsonPath('data.items.0.resume.progress_percentage', 35)
            ->assertJsonPath('data.items.0.next_section.course_section_id', $nextSectionId)
            ->assertJsonPath('data.items.0.next_section.id', $nextLesson->id)
            ->assertJsonPath('data.items.0.next_section.type', 'lesson')
            ->assertJsonMissingPath('data.items.0.resume.video_url');
    }

    private function user(array $attributes = []): User
    {
        $user = new User();
        $user->forceFill(array_merge([
            'name' => 'Parity Student',
            'email' => 'parity-'.str()->uuid().'@example.test',
            'role' => 'client',
            'active' => true,
            'social_provider' => 'google',
            'social_id' => (string) str()->uuid(),
        ], $attributes));
        $user->save();

        return $user;
    }

    private function course(array $attributes): Course
    {
        $teacher = $this->user(['role' => 'teacher']);
        $course = new Course();
        $course->forceFill(array_merge([
            'tenant_id' => 1,
            'teacher_id' => $teacher->id,
            'description_ar' => 'محتوى تعليمي واضح',
            'description_en' => 'Clear learning content',
            'image' => 'courses/parity-cover.jpg',
            'is_catalog_visible' => true,
        ], $attributes));
        $course->save();

        DB::table('course_teacher')->insert([
            'course_id' => $course->id,
            'teacher_id' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $classificationId = DB::table('classifications')->insertGetId([
            'name_ar' => 'التعلّم',
            'name_en' => 'Learning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('classification_course')->insert([
            'classification_id' => $classificationId,
            'course_id' => $course->id,
        ]);
        app(\App\Services\CourseAccessPlanService::class)->createDefaults($course);

        return $course;
    }
}
