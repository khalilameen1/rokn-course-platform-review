<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\User;
use App\Models\Lesson;
use App\Models\PlaybackSession;
use App\Models\ProjectSubmission;
use App\Models\InternalSignal;
use App\Services\LearningEvidenceService;
use App\Services\InternalSignalService;
use App\Services\InternalSignalHandler;
use App\Services\ProjectSubmissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * End-to-end Feature flow test simulating a realistic student journey in the Rokn e-learning platform.
 * This covers major business flows after social sign-in: course browsing, code redemption,
 * section completion, ratings, saved folders, and portfolio management.
 */
class StudentElearningFlowTest extends ApiTestCase
{
    public function test_project_review_notification_signal_recovers_idempotently(): void
    {
        Queue::fake();
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'ai_prompt' => 'راجع المحاولة',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $this->courseId,
            'title' => 'مشروع العبور',
            'section_type' => 'project',
            'sectionable_type' => \App\Models\Project::class,
            'sectionable_id' => $projectId,
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $submission = ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'project_id' => $projectId,
            'idempotency_key' => (string) Str::uuid(),
            'effort_status' => ProjectSubmission::EFFORT_VALID,
            'review_status' => ProjectSubmission::STATUS_PENDING,
            'submitted_at' => now(),
            'auto_pass_at' => now()->subSecond(),
        ]);

        $reviewed = app(ProjectSubmissionService::class)->finalizeIfDue($submission);
        self::assertSame(ProjectSubmission::STATUS_PASSED, $reviewed->review_status);

        $signal = InternalSignal::query()
            ->where('type', 'project.review.notification')
            ->where('aggregate_type', ProjectSubmission::class)
            ->where('aggregate_id', $submission->id)
            ->sole();

        app(InternalSignalHandler::class)->handle($signal);
        app(InternalSignalHandler::class)->handle($signal);

        self::assertSame(1, DB::table('student_notifications')
            ->where('delivery_key', "project-review:{$submission->public_id}:passed")
            ->count());
        $this->assertDatabaseHas('student_notifications', [
            'user_id' => $this->user->id,
            'notification_type' => 'project_update',
            'link' => "rokn://course/{$this->courseId}/project/{$projectId}",
        ]);
    }

    public function test_completion_signal_cannot_create_an_earned_revision_without_complete_learning_evidence(): void
    {
        Queue::fake();
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signal = app(InternalSignalService::class)->record(
            'course.completed',
            "user:{$this->user->id}:course:{$this->courseId}",
            ['user_id' => $this->user->id, 'course_id' => $this->courseId]
        );

        self::assertNull(data_get($signal->payload, 'curriculum_revision'));
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'completed_curriculum_revision' => null,
        ]);

        app(InternalSignalHandler::class)->handle($signal);

        self::assertSame(0, DB::table('internal_signals')
            ->where('type', 'like', 'course.completed.%')
            ->count());
    }

    public function test_earned_course_revision_is_grandfathered_and_signal_identity_is_versioned(): void
    {
        Queue::fake();
        DB::table('courses')->where('id', $this->courseId)->update([
            'authoring_version' => 7,
            'last_published_authoring_version' => 7,
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
            'course_section_id' => $this->sectionId,
            'is_completed' => true,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signals = app(InternalSignalService::class);
        $first = $signals->record(
            'course.completed',
            "user:{$this->user->id}:course:{$this->courseId}",
            ['user_id' => $this->user->id, 'course_id' => $this->courseId]
        );

        self::assertSame(7, (int) data_get($first->payload, 'curriculum_revision'));
        self::assertSame(
            hash('sha256', "course.completed|user:{$this->user->id}:course:{$this->courseId}:revision:7"),
            $first->signal_key
        );
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'completed_curriculum_revision' => 7,
        ]);

        app(InternalSignalHandler::class)->handle($first);
        self::assertSame(1, DB::table('internal_signals')
            ->where('type', 'course.completed.badge')
            ->count());
        self::assertSame(1, DB::table('internal_signals')
            ->where('type', 'course.completed.reward')
            ->count());
        self::assertSame(0, DB::table('internal_signals')
            ->where('type', 'course.completed.certificate')
            ->count());

        DB::table('courses')->where('id', $this->courseId)->update([
            'authoring_version' => 8,
            'last_published_authoring_version' => 8,
        ]);
        $replay = $signals->record(
            'course.completed',
            "user:{$this->user->id}:course:{$this->courseId}",
            ['user_id' => $this->user->id, 'course_id' => $this->courseId]
        );

        self::assertSame($first->id, $replay->id);
        self::assertSame(1, DB::table('internal_signals')->where('type', 'course.completed')->count());
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'completed_curriculum_revision' => 7,
        ]);
    }

    public function test_learning_evidence_uses_the_accepted_playback_session_sample(): void
    {
        DB::table('lessons')->where('id', 10)->update(['duration_minutes' => 2]);
        DB::table('lesson_media_states')->insert([
            'lesson_id' => 10,
            'provider' => 'bunny',
            'status' => 'ready',
            'duration_seconds' => 120,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_sections')->where('id', $this->sectionId)->update([
            'sectionable_type' => Lesson::class,
            'sectionable_id' => 10,
            'section_type' => 'lesson',
        ]);
        $lesson = Lesson::with('courseSection')->findOrFail(10);
        $service = app(LearningEvidenceService::class);

        $service->recordHeartbeat($this->user, $lesson, 0, 120);
        $this->travel(10)->seconds();
        $firstSession = $service->recordHeartbeat($this->user, $lesson, 10, 120);
        self::assertSame(10, $firstSession['verified_seconds']);

        $newSession = $service->recordHeartbeat($this->user, $lesson, 90, 120, [
            'position_seconds' => 0,
            'recorded_at' => null,
        ]);
        self::assertSame(10, $newSession['verified_seconds']);

        $this->travel(10)->seconds();
        $continuedSession = $service->recordHeartbeat($this->user, $lesson, 100, 120, [
            'position_seconds' => 90,
            'recorded_at' => now()->subSeconds(10),
        ]);
        self::assertSame(20, $continuedSession['verified_seconds']);
    }

    public function test_one_second_client_duration_cannot_unlock_a_lesson_with_unknown_server_duration(): void
    {
        DB::table('lessons')->where('id', 10)->update(['duration_minutes' => 0]);
        DB::table('course_sections')->where('id', $this->sectionId)->update([
            'sectionable_type' => \App\Models\Lesson::class,
            'sectionable_id' => 10,
            'section_type' => 'lesson',
        ]);
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')->postJson('/api/v1/user/watch-history', [
            'lesson_id' => 10,
            'position_seconds' => 0,
            'duration_seconds' => 1,
            'playback_session_id' => (string) Str::uuid(),
            'sequence' => 1,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'video_metadata_unavailable');

        $this->actingAs($this->user, 'api')
            ->postJson("/api/v1/courses/{$this->courseId}/sections/{$this->sectionId}/complete")
            ->assertStatus(409)
            ->assertJsonPath('code', 'verified_watch_required');
        $this->assertDatabaseMissing('student_section_progress', [
            'user_id' => $this->user->id,
            'course_section_id' => $this->sectionId,
        ]);
    }

    public function test_a_replayed_heartbeat_cannot_rewind_the_resume_position(): void
    {
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('lesson_media_states')->insert([
            'lesson_id' => 10,
            'provider' => 'bunny',
            'status' => 'ready',
            'duration_seconds' => 120,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $startedAt = now()->subMinute();
        $session = PlaybackSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'lesson_id' => 10,
            'course_section_id' => $this->sectionId,
            'last_sequence' => 2,
            'last_position_seconds' => 30,
            'duration_seconds' => 120,
            'started_at' => $startedAt,
            'last_heartbeat_at' => now(),
            'event_type' => 'heartbeat',
            'source_protocol' => 'hls',
        ]);
        DB::table('watching_logs')->insert([
            'user_id' => $this->user->id,
            'lesson_id' => 10,
            'lesson_name' => 'Lesson 10',
            'course_id' => $this->courseId,
            'course_section_id' => $this->sectionId,
            'course_name' => 'دورة تجريبية',
            'position_seconds' => 30,
            'duration_seconds' => 120,
            'playback_session_id' => $session->id,
            'playback_session_started_at' => $startedAt,
            'last_playback_sequence' => 2,
            'watched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')->postJson('/api/v1/user/watch-history', [
            'lesson_id' => 10,
            'position_seconds' => 5,
            'duration_seconds' => 120,
            'playback_session_id' => $session->id,
            'sequence' => 1,
            'event_type' => 'heartbeat',
        ])->assertOk()
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.recorded', false)
            ->assertJsonPath('data.position_seconds', 30);

        $this->assertDatabaseHas('watching_logs', [
            'user_id' => $this->user->id,
            'lesson_id' => 10,
            'position_seconds' => 30,
            'last_playback_sequence' => 2,
        ]);
    }

    /**
     * Test the complete student journey from registration to course completion.
     */
    public function test_complete_elearning_journey_from_social_sign_in_to_certification(): void
    {
        // Social-provider identity verification itself is covered by the OAuth
        // contract tests. Start this journey with the verified identity that
        // the social callback persists, without reviving the removed OTP flow.
        $student = User::create([
            'name' => 'Sara Student',
            'email' => 'sara@rokn.com',
            'role' => 'client',
            'active' => true,
            'social_provider' => 'google',
            'social_id' => 'google-sara-001',
            'watch_history_enabled' => true,
        ]);
        $student->forceFill([
            'wallet_coins' => 20,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 20,
        ])->save();

        DB::table('wallet_transactions')->insert([
            'public_id' => (string) Str::uuid(),
            'user_id' => $student->id,
            'direction' => 'credit',
            'category' => 'welcome_bonus',
            'bucket' => 'reward',
            'amount' => 20,
            'paid_amount' => 0,
            'reward_amount' => 20,
            'balance_after' => 20,
            'paid_balance_after' => 0,
            'reward_balance_after' => 20,
            'idempotency_key' => "welcome-bonus:user:{$student->id}",
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $student->refresh();

        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'email' => 'sara@rokn.com',
            'social_provider' => 'google',
        ]);

        // 2. Student browses courses and checks access before enrolling
        $browseResponse = $this->actingAs($student, 'api')->getJson('/api/v1/courses/list');
        $browseResponse->assertStatus(200);

        $this->actingAs($student, 'api')
            ->getJson("/api/v1/courses/{$this->courseId}/details")
            ->assertOk()
            ->assertJsonPath('data.enrollment', null);

        // 3. Student redeems a course code to gain full access
        $redeemResponse = $this->actingAs($student, 'api')->postJson('/api/v1/course-codes/redeem', [
            'code' => 'TESTCODE'
        ]);
        $redeemResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        // Verify enrollment and free order creation
        $this->assertDatabaseHas('course_enrollments', [
            'user_id' => $student->id,
            'course_id' => $this->courseId,
            'is_active' => 1
        ]);

        $this->assertDatabaseHas('orders', [
            'user_id' => $student->id,
            'course_id' => $this->courseId,
            'status' => 'approved'
        ]);

        // 4. The player sends server-timed evidence before completing a lesson.
        DB::table('lessons')->where('id', 10)->update(['duration_minutes' => 1]);
        DB::table('course_sections')->where('id', $this->sectionId)->update([
            'sectionable_type' => \App\Models\Lesson::class,
            'sectionable_id' => 10,
            'section_type' => 'lesson',
        ]);
        DB::table('lesson_media_states')->updateOrInsert(
            ['lesson_id' => 10],
            [
                'provider' => 'bunny',
                'status' => 'ready',
                'duration_seconds' => 60,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        $playbackSession = PlaybackSession::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $student->id,
            'lesson_id' => 10,
            'course_section_id' => $this->sectionId,
            'started_at' => now(),
            'event_type' => 'play',
            'source_protocol' => 'hls',
            'source_host' => 'video.test',
        ]);
        $this->actingAs($student, 'api')->postJson('/api/v1/user/watch-history', [
            'lesson_id' => 10,
            'position_seconds' => 0,
            'duration_seconds' => 60,
            'playback_session_id' => $playbackSession->id,
            'sequence' => 1,
            'event_type' => 'start',
        ])->assertOk();
        // Production heartbeats are intentionally bounded to short gaps.
        // Reproduce a real player cadence instead of one synthetic 48-second
        // jump, while accumulating the same verified watch time.
        $this->travel(24)->seconds();
        $this->actingAs($student, 'api')->postJson('/api/v1/user/watch-history', [
            'lesson_id' => 10,
            'position_seconds' => 24,
            'duration_seconds' => 60,
            'playback_session_id' => $playbackSession->id,
            'sequence' => 2,
            'event_type' => 'heartbeat',
        ])->assertOk();
        $this->travel(24)->seconds();
        $this->actingAs($student, 'api')->postJson('/api/v1/user/watch-history', [
            'lesson_id' => 10,
            'position_seconds' => 48,
            'duration_seconds' => 60,
            'playback_session_id' => $playbackSession->id,
            'sequence' => 3,
            'event_type' => 'heartbeat',
        ])->assertOk();

        // The completion endpoint now validates that evidence rather than a
        // client-supplied "completed" boolean.
        $progressResponse = $this->actingAs($student, 'api')->postJson("/api/v1/courses/{$this->courseId}/sections/{$this->sectionId}/complete");
        $progressResponse->assertStatus(200);

        // Check student section progress recorded in DB
        $this->assertDatabaseHas('student_section_progress', [
            'user_id' => $student->id,
            'course_section_id' => $this->sectionId,
        ]);

        // Verify course progress calculation
        $courseProgressResponse = $this->actingAs($student, 'api')->getJson("/api/v1/courses/{$this->courseId}/progress");
        $courseProgressResponse->assertStatus(200);

        // 5. Student submits course rating and checks profile
        $rateResponse = $this->actingAs($student, 'api')->postJson("/api/v1/courses/{$this->courseId}/rate", [
            'rating' => 5,
            'comment' => 'ممتازة جدا وشرح رائع',
            'version' => 0,
        ]);
        $rateResponse->assertStatus(200);

        $profileResponse = $this->actingAs($student, 'api')->getJson('/api/v1/user/profile');
        $profileResponse->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /**
     * Test student study organization (saved folders/lessons) and portfolio showcase workflow.
     */
    public function test_student_study_organization_and_portfolio_flow(): void
    {
        $student = $this->user;

        // Saved learning material may only reference a course the student can
        // actually open. Grant the same scholarship-code access used by the
        // real app before exercising folder organisation.
        $this->actingAs($student, 'api')->postJson('/api/v1/course-codes/redeem', [
            'code' => 'TESTCODE',
        ])->assertOk();

        // 1. Create a study folder to organize saved lessons
        $createFolderResponse = $this->actingAs($student, 'api')->postJson('/api/v1/saved-folders', [
            'name' => 'مفضلاتي في الرياضيات'
        ]);
        $createFolderResponse->assertStatus(201)
            ->assertJsonPath('success', true);

        $folderId = (int) $createFolderResponse->json('data.id');
        $this->assertDatabaseHas('saved_folders', ['id' => $folderId, 'name' => 'مفضلاتي في الرياضيات']);

        // 2. Save a lesson into the newly created folder
        $saveLessonResponse = $this->actingAs($student, 'api')->postJson("/api/v1/saved-folders/{$folderId}/lessons", [
            'lesson_id' => 10
        ]);
        $saveLessonResponse->assertStatus(200);

        // Retrieve folder contents to ensure lesson was added
        $getFolderResponse = $this->actingAs($student, 'api')->getJson("/api/v1/saved-folders/{$folderId}");
        $getFolderResponse->assertStatus(200);

        // 3. Create a portfolio item to showcase achievements
        $portfolioResponse = $this->actingAs($student, 'api')->postJson('/api/v1/portfolio', [
            'title' => 'مشروع التخرج في البرمجة',
            'description' => 'وصف تفصيلي للمشروع وما تم إنجازه',
        ]);
        $portfolioResponse->assertStatus(200)
            ->assertJsonPath('status', 200);

        $portfolioId = (int) $portfolioResponse->json('data.id');
        $this->assertDatabaseHas('portfolio_items', [
            'id' => $portfolioId,
            'user_id' => $student->id,
            'title' => 'مشروع التخرج في البرمجة'
        ]);

        // Retrieve user portfolio list
        $listPortfolioResponse = $this->actingAs($student, 'api')->getJson('/api/v1/portfolio');
        $listPortfolioResponse->assertStatus(200);

        // 4. Student updates classification interests (API requires at least one valid classification_id)
        $updateInterestsResponse = $this->actingAs($student, 'api')->postJson('/api/v1/user/interests', [
            'classification_ids' => [1]
        ]);
        $updateInterestsResponse->assertStatus(200);
    }
}
