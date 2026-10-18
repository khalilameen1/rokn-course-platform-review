<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BunnyVideoCleanupCandidate;
use App\Models\Lesson;
use App\Models\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class BunnyCleanupWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('bunny_enabled')->default(false);
            $table->timestamps();
        });
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->string('bunny_video_id')->nullable();
            $table->timestamps();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('sectionable_type');
            $table->unsignedBigInteger('sectionable_id');
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('bunny_video_cleanup_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('video_guid')->unique();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->string('reason');
            $table->timestamp('eligible_after');
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('remote_deleted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('requires_review')->default(true);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });

        config()->set('bunny.stream_api_key', 'test-stream-key');
        config()->set('bunny.library_id', '123');
        Setting::create(['bunny_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bunny_video_cleanup_candidates');
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('settings');
        parent::tearDown();
    }

    public function test_unreviewed_candidate_is_never_deleted(): void
    {
        $candidate = $this->candidate('review-required');
        Http::fake();

        $this->artisan('bunny:cleanup-videos')->assertExitCode(0);

        self::assertNull($candidate->fresh()->remote_deleted_at);
        Http::assertNothingSent();
    }

    public function test_reviewed_unreferenced_candidate_is_deleted(): void
    {
        $candidate = $this->candidate('approved-guid', true);
        Http::fake(fn (Request $request) => Http::response([], 204));

        $this->artisan('bunny:cleanup-videos')->assertExitCode(0);

        self::assertNotNull($candidate->fresh()->remote_deleted_at);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_active_reference_cancels_prior_approval(): void
    {
        $lessonId = DB::table('lessons')->insertGetId([
            'bunny_video_id' => 'active-guid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_sections')->insert([
            'course_id' => 1,
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lessonId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $candidate = $this->candidate('active-guid', true);
        Http::fake();

        $this->artisan('bunny:cleanup-videos')->assertExitCode(1);

        $candidate->refresh();
        self::assertNull($candidate->reviewed_at);
        self::assertNull($candidate->reviewed_by);
        self::assertTrue($candidate->requires_review);
        self::assertNull($candidate->remote_deleted_at);
        Http::assertNothingSent();
    }

    private function candidate(string $guid, bool $approved = false): BunnyVideoCleanupCandidate
    {
        return BunnyVideoCleanupCandidate::create([
            'video_guid' => $guid,
            'reason' => 'unpublished_upload',
            'eligible_after' => now()->subMinute(),
            'reviewed_at' => $approved ? now() : null,
            'reviewed_by' => $approved ? 1 : null,
            'requires_review' => ! $approved,
        ]);
    }
}
