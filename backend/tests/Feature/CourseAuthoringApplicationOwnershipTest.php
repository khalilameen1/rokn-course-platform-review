<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\CourseAuthoringEdit;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Classification;
use App\Models\Course;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminCourseAuthoringService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CourseAuthoringApplicationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Exceptions::fake();
        // The historical removal migration skips SQLite table rebuilding.
        // Exercise creation against the production contract (no tenant column),
        // without injecting a model observer or reintroducing a retired field.
        if (DB::connection()->getDriverName() === 'sqlite' && Schema::hasColumn('courses', 'tenant_id')) {
            DB::statement('DROP INDEX IF EXISTS courses_tenant_id_index');
            DB::statement('ALTER TABLE courses DROP COLUMN tenant_id');
        }
    }

    public function test_request_free_creation_and_replay_complete_inside_the_owned_transaction(): void
    {
        foreach ([CourseController::class, AdminAuthoringCreateIntentService::class] as $httpOwner) {
            $this->app->bind($httpOwner, static function (): never {
                throw new \LogicException('Course application must not resolve its HTTP caller or receipt adapter.');
            });
        }
        $writer = app(AdminCourseAuthoringService::class);
        $edit = CourseAuthoringEdit::fromValidated([
            'name_ar' => 'الكورس الجديد', 'certificate_text_template_key' => 'completion',
            'authoring_request_id' => (string) Str::uuid(),
        ]);
        $outerLevel = DB::transactionLevel();
        $completed = [];
        $complete = static function (Course $course) use (&$completed, $outerLevel): void {
            self::assertGreaterThan($outerLevel, DB::transactionLevel());
            self::assertTrue($course->exists);
            self::assertSame(3, $course->accessPlans()->count());
            $completed[] = (int) $course->id;
        };

        $first = $writer->create($edit, $complete);
        $replay = $writer->create($edit, $complete);
        Exceptions::throwFirstReported();

        self::assertSame('created', $first['status']);
        self::assertSame('existing', $replay['status']);
        self::assertSame([$first['course']->id, $first['course']->id], $completed);
        self::assertSame(1, Course::query()->count());
        self::assertTrue((bool) $first['course']->is_coming_soon);
        self::assertFalse((bool) $first['course']->is_catalog_visible);
        self::assertFalse((bool) $first['course']->attachment_prompt_enabled);
        self::assertSame($outerLevel, DB::transactionLevel());
        Http::assertNothingSent();
    }

    public function test_creation_callback_failure_rolls_back_the_course_and_plans_and_allows_retry(): void
    {
        $writer = app(AdminCourseAuthoringService::class);
        $edit = CourseAuthoringEdit::fromValidated([
            'name_ar' => 'كورس قابل لإعادة المحاولة', 'certificate_text_template_key' => 'completion',
            'authoring_request_id' => (string) Str::uuid(),
        ]);
        $failed = $writer->create($edit, static function (Course $course): never {
            self::assertSame(3, $course->accessPlans()->count());
            throw new \RuntimeException('Receipt storage unavailable.');
        });

        self::assertSame('failed', $failed['status']);
        self::assertNull($failed['course']);
        self::assertSame(0, Course::query()->count());
        self::assertSame(0, DB::table('course_access_plans')->count());
        Exceptions::assertReported(fn (\RuntimeException $error): bool =>
            $error->getMessage() === 'Receipt storage unavailable.'
        );
        Exceptions::fake();
        $retried = $writer->create($edit, static function (Course $course): void {
            self::assertTrue($course->exists);
        });
        Exceptions::throwFirstReported();
        self::assertSame('created', $retried['status']);
        self::assertSame(1, Course::query()->count());
        self::assertSame(3, DB::table('course_access_plans')->count());
    }

    public function test_request_free_partial_save_preserves_omitted_relations_and_honors_explicit_clear(): void
    {
        $course = $this->draft();
        $classification = Classification::query()->create([
            'name_ar' => 'صف الكورسات', 'name_en' => 'Course row',
        ]);
        $teacher = User::query()->forceCreate([
            'name_ar' => 'المدرب', 'email' => 'course-teacher@example.test', 'role' => 'teacher', 'active' => true,
        ]);
        $course->classifications()->sync([$classification->id]);
        $course->teachers()->sync([$teacher->id]);
        $writer = app(AdminCourseAuthoringService::class);

        $first = $writer->update(CourseAuthoringEdit::fromValidated([
            'authoring_version' => 1, 'name_ar' => 'اسم جديد', 'is_main_course' => true,
        ]), $course, false, false);
        self::assertSame('updated', $first['status']);
        self::assertSame([$classification->id], $course->classifications()->pluck('classifications.id')->all());
        self::assertSame([$teacher->id], $course->teachers()->pluck('users.id')->all());
        self::assertFalse((bool) $course->fresh()->is_main_course, 'The input cannot grant home-curation permission.');
        self::assertSame('Original description', $course->fresh()->description_en);

        $second = $writer->update(CourseAuthoringEdit::fromValidated([
            'authoring_version' => 2, 'classification_ids_present' => true,
            'teacher_ids_present' => true, 'description_en' => null,
        ]), $course->fresh(), false, false);
        self::assertSame('updated', $second['status']);
        self::assertSame([], $course->classifications()->pluck('classifications.id')->all());
        self::assertSame([], $course->teachers()->pluck('users.id')->all());
        self::assertNull($course->fresh()->description_en);
        self::assertSame('اسم جديد', $course->fresh()->name_ar);
        self::assertSame(3, (int) $course->fresh()->authoring_version);
        Http::assertNothingSent();
    }

    public function test_a_stale_edit_cannot_overwrite_a_newer_course_version(): void
    {
        $course = $this->draft();
        $course->forceFill(['authoring_version' => 2])->saveQuietly();
        try {
            app(AdminCourseAuthoringService::class)->update(CourseAuthoringEdit::fromValidated([
                'authoring_version' => 1, 'name_ar' => 'اسم قديم من محرر آخر',
            ]), $course, true, true);
            self::fail('Stale authoring must fail before writing.');
        } catch (ValidationException $exception) {
            self::assertSame(409, $exception->status);
            self::assertArrayHasKey('authoring_version', $exception->errors());
        }
        self::assertSame('الكورس الأصلي', $course->fresh()->name_ar);
        self::assertSame(2, (int) $course->fresh()->authoring_version);
    }

    public function test_real_http_creation_receipt_failure_rolls_back_and_same_intent_retries_once(): void
    {
        $moderator = User::query()->forceCreate([
            'name_ar' => 'محرر المحتوى', 'email' => 'course-creation@example.test',
            'role' => 'moderator', 'active' => true,
        ]);
        $this->actingAs($moderator, 'web')->withoutMiddleware(RequireAdminMfa::class);
        $payload = [
            'name_ar' => 'كورس الإنشاء الآمن', 'certificate_text_template_key' => 'completion',
            'authoring_request_id' => (string) Str::uuid(),
        ];
        DB::statement("CREATE TRIGGER reject_course_creation_receipt BEFORE UPDATE ON admin_authoring_create_intents
            WHEN NEW.status = 'completed' BEGIN SELECT RAISE(ABORT, 'receipt unavailable'); END");
        try {
            $this->post(route('admin.courses.store'), $payload)->assertSessionHas('error');
        } finally {
            DB::statement('DROP TRIGGER reject_course_creation_receipt');
        }
        self::assertSame(0, Course::query()->count());
        self::assertSame(0, DB::table('course_access_plans')->count());
        self::assertSame('failed', DB::table('admin_authoring_create_intents')->value('status'));
        Exceptions::assertReported(fn (QueryException $error): bool =>
            str_contains($error->getMessage(), 'receipt unavailable')
        );
        Exceptions::fake();
        $this->app['session']->forget(['error', 'errors']);

        $response = $this->post(route('admin.courses.store'), $payload);
        Exceptions::throwFirstReported();
        $course = Course::query()->sole();
        $response->assertRedirect(route('admin.courses.show', $course));
        $receipt = DB::table('admin_authoring_create_intents')->sole();
        self::assertSame('completed', $receipt->status);
        self::assertSame('redirect', $receipt->response_kind);
        self::assertSame((string) $course->id, (string) $receipt->resource_id);
        $this->post(route('admin.courses.store'), $payload)->assertRedirect(route('admin.courses.show', $course));
        self::assertSame(1, Course::query()->count());
        self::assertSame(3, DB::table('course_access_plans')->count());
        Http::assertNothingSent();
    }

    private function draft(): Course
    {
        return Course::query()->forceCreate([
            'name_ar' => 'الكورس الأصلي', 'description_en' => 'Original description',
            'is_coming_soon' => true, 'is_catalog_visible' => false, 'is_main_course' => false,
            'authoring_version' => 1, 'last_published_authoring_version' => 0,
            'certificate_text_template_key' => 'completion',
        ]);
    }
}
