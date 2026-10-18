<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\User;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductionCourseCodeSchema;
use Tests\TestCase;

/**
 * The service-level boundary (receipt inside the batch transaction, full
 * rollback on a failed receipt) is proven in
 * AdminCourseCodeAuthoringOwnershipTest::test_batch_completes_one_receipt_in_the_same_transaction
 * and ::test_receipt_failure_rolls_back_every_code_and_allows_a_clean_retry.
 * These tests prove the dashboard store route goes through that boundary.
 */
final class AdminTransactionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ProductionCourseCodeSchema::applySqliteBridge();
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_bulk_course_code_creation_completes_one_receipt_for_the_whole_batch(): void
    {
        $admin = $this->admin();
        $course = $this->course();
        $payload = $this->payload($course->id);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($admin, 'web')
                ->post(route('admin.course-codes.store'), $payload)
                ->assertRedirect(route('admin.course-codes.index'))
                ->assertSessionHasNoErrors();
            self::assertSame(3, CourseCode::query()->count(), 'A replayed receipt must not create another batch.');
        }

        self::assertSame(3, CourseCode::query()->distinct()->count('code'));
        self::assertSame(3, CourseCode::query()->where('course_id', $course->id)->where('name', 'دفعة الجامعة')->count());
        $receipt = DB::table('admin_authoring_create_intents')
            ->where('actor_id', $admin->id)
            ->where('route_name', 'admin.course-codes.store')
            ->where('intent_id', $payload['authoring_request_id'])
            ->sole();
        self::assertSame('completed', $receipt->status);
        self::assertSame(CourseCode::class, $receipt->resource_type);
        self::assertSame((int) CourseCode::query()->min('id'), (int) $receipt->resource_id);
    }

    public function test_rejected_batch_target_writes_no_codes_and_no_completed_receipt(): void
    {
        $admin = $this->admin();
        // A draft copy passes the request's exists rule; only the service's
        // in-transaction target check rejects it.
        $draft = app(CourseStagedAuthoringService::class)->draftFor($this->course());
        $payload = $this->payload($draft->id);

        $this->actingAs($admin, 'web')
            ->from(route('admin.course-codes.create'))
            ->post(route('admin.course-codes.store'), $payload)
            ->assertRedirect(route('admin.course-codes.create'))
            ->assertSessionHasErrors('course_id');

        self::assertSame(0, CourseCode::query()->count());
        self::assertFalse(DB::table('admin_authoring_create_intents')
            ->where('intent_id', $payload['authoring_request_id'])
            ->where('status', 'completed')
            ->exists());
    }

    /** @return array<string, mixed> */
    private function payload(int $courseId): array
    {
        return [
            'authoring_request_id' => (string) Str::uuid(),
            'name' => 'دفعة الجامعة',
            'type' => 'course',
            'max_uses' => 50,
            'number_of_codes' => 3,
            'course_id' => $courseId,
        ];
    }

    private function admin(): User
    {
        return User::query()->forceCreate([
            'name' => 'Code Admin', 'email' => 'code-batch-admin@rokn.test',
            'role' => 'admin', 'active' => true,
        ]);
    }

    private function course(): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس الأكواد', 'price' => 100,
            'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 1, 'last_published_authoring_version' => 1, 'published_at' => now(),
        ]);
    }
}
