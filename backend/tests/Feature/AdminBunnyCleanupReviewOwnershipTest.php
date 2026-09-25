<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AdminBunnyCleanupReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AdminBunnyCleanupReviewOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private int $reviewerId;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->bind(SettingsController::class, static function (): never {
            throw new \LogicException('Cleanup review must not resolve the HTTP adapter.');
        });
        $this->reviewerId = User::query()->forceCreate([
            'name' => 'Admin', 'email' => 'cleanup-admin@rokn.test', 'role' => 'admin',
            'active' => true, 'password' => 'test-only',
        ])->id;
    }

    public function test_single_review_preserves_retention_and_does_not_delete_remote_media(): void
    {
        $candidate = $this->candidate();
        $eligibleAfter = $candidate->eligible_after->toISOString();
        self::assertTrue(app(AdminBunnyCleanupReviewService::class)->approve($candidate->id, $this->reviewerId));
        $candidate->refresh();
        self::assertSame($eligibleAfter, $candidate->eligible_after->toISOString());
        self::assertNotNull($candidate->reviewed_at);
        self::assertSame($this->reviewerId, (int) $candidate->reviewed_by);
        self::assertFalse($candidate->requires_review);
        self::assertNull($candidate->last_error);
        self::assertNull($candidate->remote_deleted_at);
        Http::assertNothingSent();
    }

    public function test_single_and_batch_reviews_both_retain_referenced_media(): void
    {
        $candidate = $this->candidate();
        $this->reference($candidate);
        $before = $candidate->fresh()->getRawOriginal();
        $service = app(AdminBunnyCleanupReviewService::class);
        self::assertFalse($service->approve($candidate->id, $this->reviewerId));
        self::assertSame(['approved' => 0, 'skipped_active' => 1], $service->approveBatch([$candidate->id], $this->reviewerId));
        self::assertSame($before, $candidate->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    public function test_batch_changes_only_selected_unreviewed_undeleted_candidates(): void
    {
        $pending = $this->candidate();
        $reviewed = $this->candidate();
        $reviewed->forceFill(['reviewed_at' => now()->subDay(), 'reviewed_by' => $this->reviewerId])->save();
        $deleted = $this->candidate();
        $deleted->forceFill(['remote_deleted_at' => now()])->save();
        $outside = $this->candidate();
        $before = [$reviewed->fresh()->getRawOriginal(), $deleted->fresh()->getRawOriginal(), $outside->fresh()->getRawOriginal()];
        $result = app(AdminBunnyCleanupReviewService::class)->approveBatch(
            [$pending->id, $reviewed->id, $deleted->id], $this->reviewerId
        );
        self::assertSame(['approved' => 1, 'skipped_active' => 0], $result);
        self::assertNotNull($pending->fresh()->reviewed_at);
        self::assertSame($before, [$reviewed->fresh()->getRawOriginal(), $deleted->fresh()->getRawOriginal(), $outside->fresh()->getRawOriginal()]);
        Http::assertNothingSent();
    }

    public function test_single_review_reloads_current_state_and_rejects_deleted_candidate(): void
    {
        $candidate = $this->candidate();
        $candidate->fresh()->forceFill(['remote_deleted_at' => now()])->save();
        try {
            app(AdminBunnyCleanupReviewService::class)->approve($candidate->id, $this->reviewerId);
            self::fail('A stale page must not approve an already deleted video.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        self::assertNull($candidate->fresh()->reviewed_at);
    }

    public function test_outer_rollback_restores_review_state(): void
    {
        $candidate = $this->candidate();
        $before = $candidate->fresh()->getRawOriginal();
        try {
            DB::transaction(function () use ($candidate): void {
                app(AdminBunnyCleanupReviewService::class)->approve($candidate->id, $this->reviewerId);
                throw new \RuntimeException('review operation failed');
            });
            self::fail('The review must roll back with its enclosing operation.');
        } catch (\RuntimeException $error) {
            self::assertSame('review operation failed', $error->getMessage());
        }
        self::assertSame($before, $candidate->fresh()->getRawOriginal());
        Http::assertNothingSent();
    }

    private function candidate(): BunnyVideoCleanupCandidate
    {
        return BunnyVideoCleanupCandidate::query()->create([
            'video_guid' => (string) Str::uuid(), 'reason' => 'superseded_video',
            'eligible_after' => now()->addDay(), 'requires_review' => true,
            'last_error' => 'prior failure',
        ]);
    }

    private function reference(BunnyVideoCleanupCandidate $candidate): void
    {
        $course = Course::factory()->create(['tenant_id' => 1, 'is_coming_soon' => true]);
        $module = CourseModule::query()->create([
            'course_id' => $course->id, 'title_ar' => 'الوحدة الأولى', 'order' => 1,
        ]);
        $lesson = Lesson::query()->create([
            'list_id' => $course->id, 'title_ar' => 'مقطع', 'bunny_video_id' => $candidate->video_guid,
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id,
            'title_ar' => 'مقطع', 'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id, 'order' => 1,
        ]);
    }
}
