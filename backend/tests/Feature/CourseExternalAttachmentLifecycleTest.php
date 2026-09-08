<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\User;
use App\Services\AdminCoursePdfApplicationService;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CourseExternalAttachmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('createRouteSources')]
    public function test_external_create_receipt_replays_without_allocating_a_second_attachment(bool $hiddenDraft): void
    {
        Http::preventStrayRequests();
        $admin = new User();
        $admin->forceFill([
            'name_ar' => 'مدير', 'email' => 'attachment-admin@example.test',
            'role' => 'admin', 'active' => true,
        ])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($admin, 'web');
        $course = $this->course($hiddenDraft);
        $data = [
            'title' => 'ملفات العمل', 'source_type' => 'external', 'platform' => 'computer',
            'external_url' => 'https://files.example.org/large-project.blend',
            'authoring_version' => 4, 'authoring_request_id' => (string) Str::uuid(),
        ];
        $first = $this->postJson(route('admin.courses.pdfs.store', $course), $data)->assertOk();
        self::assertSame('completed', DB::table('admin_authoring_create_intents')->value('status'));
        $again = $this->postJson(route('admin.courses.pdfs.store', $course), $data);
        self::assertSame(200, $again->status(), $again->getContent());
        self::assertSame($first->json(), $again->json());
        self::assertSame(1, CoursePdf::count());
        $savedPdf = CoursePdf::findOrFail($first->json('pdf.id'));
        self::assertSame(5, (int) $savedPdf->course->authoring_version);
        if (!$hiddenDraft) {
            self::assertNotSame($course->id, $savedPdf->course_id);
            self::assertSame(0, $course->pdfs()->count());
        }
        self::assertSame(1, DB::table('admin_authoring_create_intents')->where('status', 'completed')->count());
        $this->postJson(route('admin.courses.pdfs.store', $course), array_replace($data, [
            'external_url' => 'https://files.example.org/another.blend',
        ]))->assertStatus(409);
        self::assertSame(1, CoursePdf::count());
        self::assertSame(0, DB::table('account_file_deletions')->count());
        Http::assertNothingSent();
    }

    public function test_external_source_and_device_survive_staged_clone_publish_and_old_identity_resolution(): void
    {
        Http::preventStrayRequests();
        $canonical = $this->course(false);
        $old = $canonical->pdfs()->create([
            'title' => 'مصدر الكمبيوتر', 'source_type' => 'external', 'platform' => 'computer',
            'external_url' => 'https://example.org/original.zip', 'file_path' => '', 'is_active' => true,
        ]);
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->once()->andReturn(['ready' => true, 'issues' => []]);
        $revisions = new CourseStagedAuthoringService($publishing);
        $draft = $revisions->draftFor($canonical);
        $copy = $draft->pdfs()->firstOrFail();
        self::assertNotSame($old->id, $copy->id);
        self::assertSame('computer', $copy->platform);
        self::assertSame('external', $copy->source_type);
        self::assertSame($old->external_url, $copy->external_url);
        app(AdminCoursePdfApplicationService::class)->update($draft, $copy, [
            'external_url' => 'https://example.org/revised.zip', 'platform' => 'mobile',
        ], (int) $draft->authoring_version, null);
        self::assertSame('https://example.org/original.zip', $old->fresh()->external_url);
        $published = $revisions->publish($draft->fresh(), (int) $draft->fresh()->authoring_version, true);
        $current = $published['course']->pdfs()->firstOrFail();
        self::assertSame('external', $current->source_type);
        self::assertSame('mobile', $current->platform);
        self::assertSame('https://example.org/revised.zip', $current->external_url);
        self::assertSame($current->id, $revisions->currentEntityId(CoursePdf::class, $old->id));
        $archive = $published['archive']->pdfs()->firstOrFail();
        self::assertSame('computer', $archive->platform);
        self::assertSame('https://example.org/original.zip', $archive->external_url);
        self::assertSame(0, DB::table('account_file_deletions')->count());
        Http::assertNothingSent();
    }

    public function test_publication_checks_external_url_without_probing_shared_storage_or_network(): void
    {
        Http::preventStrayRequests();
        $course = $this->course(true);
        $pdf = $course->pdfs()->create([
            'title' => 'ملفات خارجية', 'source_type' => 'external', 'platform' => 'computer',
            'external_url' => 'https://example.org/large-files.zip', 'file_path' => '', 'is_active' => true,
        ]);
        $service = app(CoursePublishingService::class);
        // Other deliberately incomplete course data still blocks publication;
        // these assertions cover the actual attachment audit branch only.
        $issues = $service->audit($course)['issues'];
        self::assertFalse(collect($issues)->contains(fn (string $issue): bool => str_contains($issue, $pdf->title)));
        $pdf->forceFill(['external_url' => 'http://example.org/unsafe'])->save();
        $issues = $service->audit($course->fresh())['issues'];
        self::assertContains('رابط المرفق «ملفات خارجية» غير صالح', $issues);
        $pdf->forceFill(['is_active' => false])->save();
        self::assertNotContains('رابط المرفق «ملفات خارجية» غير صالح', $service->audit($course->fresh())['issues']);
        Http::assertNothingSent();
    }

    public static function createRouteSources(): array
    {
        return ['isolated draft' => [true], 'published course resolved to draft' => [false]];
    }

    public function test_claimed_receipt_cannot_be_completed_under_another_actor_route_or_request_id(): void
    {
        $actor = new User();
        $actor->forceFill(['name_ar' => 'مدير', 'email' => 'receipt-owner@example.test', 'role' => 'admin', 'active' => true])->save();
        $requestId = (string) Str::uuid();
        $request = \Illuminate\Http\Request::create('/test/attachments', 'POST', ['authoring_request_id' => $requestId]);
        $request->setUserResolver(fn () => $actor);
        $route = new \Illuminate\Routing\Route(['POST'], 'test/attachments', static fn () => null);
        $route->name('admin.courses.pdfs.store');
        $route->bind($request);
        $route->setParameter('course', $this->course(false));
        $request->setRouteResolver(fn () => $route);
        $service = app(\App\Services\AdminAuthoringCreateIntentService::class);
        $claim = $service->claim($request);
        self::assertIsArray($claim);
        $request->attributes->set(\App\Services\AdminAuthoringCreateIntentService::CLAIM_ATTRIBUTE, $claim);

        $otherActor = (new User())->forceFill(['id' => $actor->id + 1]);
        $request->setUserResolver(fn () => $otherActor);
        $service->completeJson($request, ['wrong' => 'actor']);
        $request->setUserResolver(fn () => $actor);
        $route->setAction(array_replace($route->getAction(), ['as' => 'admin.other.store']));
        $service->checkpointResource($request, CoursePdf::class, 99);
        $service->completeJson($request, ['wrong' => 'route']);
        $route->setAction(array_replace($route->getAction(), ['as' => 'admin.courses.pdfs.store']));
        $request->merge(['authoring_request_id' => (string) Str::uuid()]);
        $service->completeJson($request, ['wrong' => 'request']);
        self::assertSame('processing', DB::table('admin_authoring_create_intents')->value('status'));
        self::assertNull(DB::table('admin_authoring_create_intents')->value('resource_id'));
        $request->merge(['authoring_request_id' => $requestId]);
        $route->setParameter('course', 999);
        $service->completeJson($request, ['saved' => true]);
        self::assertSame('completed', DB::table('admin_authoring_create_intents')->value('status'));
        self::assertSame($claim['parent_scope'], DB::table('admin_authoring_create_intents')->value('parent_scope'));
    }

    private function course(bool $draft): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1, 'name_ar' => 'كورس المرفقات', 'description_ar' => 'وصف الكورس',
            'price' => 800, 'is_coming_soon' => $draft, 'is_catalog_visible' => !$draft,
            'authoring_version' => 4, 'last_published_authoring_version' => $draft ? null : 4,
            'published_at' => $draft ? null : now(),
        ])->save();

        return $course;
    }
}
