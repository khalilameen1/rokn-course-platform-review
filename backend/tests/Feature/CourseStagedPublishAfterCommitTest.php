<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class CourseStagedPublishAfterCommitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // afterCommit behavior must be observed without RefreshDatabase's
        // outer transaction postponing callbacks until test teardown.
        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    public function test_notification_preparation_failure_rolls_back_the_publish_transaction(): void
    {
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->once()->ordered()->andReturn([
            'ready' => true,
            'issues' => [],
        ]);
        $publishing->shouldReceive('audit')->once()->ordered()->andThrow(
            new \RuntimeException('notification preparation unavailable')
        );
        $this->app->instance(CoursePublishingService::class, $publishing);

        $canonical = new Course();
        $canonical->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'النسخة المنشورة',
            'description_ar' => 'وصف منشور',
            'price' => 0,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'authoring_version' => 4,
            'last_published_authoring_version' => 4,
            'published_at' => now(),
        ])->save();
        $service = app(CourseStagedAuthoringService::class);
        $draft = $service->draftFor($canonical);
        $draft->forceFill(['name_ar' => 'التعديل المنشور'])->saveQuietly();
        $expectedPublishedRevision = max(
            (int) $canonical->authoring_version,
            (int) $draft->authoring_version
        ) + 1;
        Cache::forever('courses:catalog-revision', 10);
        self::assertSame(0, DB::transactionLevel());

        try {
            $service->publish(
                $draft,
                (int) $draft->authoring_version,
                true
            );
            self::fail('Notification preparation failure must fail the transaction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('notification preparation unavailable', $exception->getMessage());
        }
        self::assertSame(0, DB::transactionLevel());

        self::assertSame('النسخة المنشورة', $canonical->fresh()->name_ar);
        self::assertSame('التعديل المنشور', $draft->fresh()->name_ar);
        self::assertTrue(CourseAuthoringRevision::query()
            ->where('revision_course_id', $draft->id)
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->exists());
        $this->assertDatabaseMissing('notification_campaigns', [
            'delivery_key' => 'course-published:' . $canonical->id . ':v' . $expectedPublishedRevision,
        ]);
        self::assertSame(10, (int) Cache::get('courses:catalog-revision'));
    }
}
