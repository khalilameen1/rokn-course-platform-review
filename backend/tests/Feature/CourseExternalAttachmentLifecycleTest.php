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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CourseExternalAttachmentLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Real upload admission commits its cleanup ledger before writing bytes;
        // it cannot run inside RefreshDatabase's enclosing test transaction.
        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    #[DataProvider('createRouteSources')]
    public function test_external_create_receipt_replays_without_allocating_a_second_attachment(bool $hiddenDraft, string $role): void
    {
        Http::preventStrayRequests();
        $admin = new User();
        $admin->forceFill([
            'name_ar' => 'مدير', 'email' => 'attachment-admin@example.test',
            'role' => $role, 'active' => true,
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

    public function test_uploaded_create_receipt_replay_keeps_one_physical_file_and_original_identity(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('course-pdfs-shared');
        config(['course_pdfs.disk' => 'course-pdfs-shared', 'course_pdfs.shared_storage' => true]);
        $admin = new User();
        $admin->forceFill([
            'name_ar' => 'مدير', 'email' => 'attachment-upload-replay@example.test',
            'role' => 'admin', 'active' => true,
        ])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($admin, 'web');
        $course = $this->course(true);
        $requestId = (string) Str::uuid();
        $data = [
            'title' => 'ملاحظات', 'source_type' => 'upload', 'platform' => 'mobile',
            'authoring_version' => 4, 'authoring_request_id' => $requestId,
        ];
        $fixture = UploadedFile::fake()->createWithContent('notes.txt', "Exact notes\n");
        $first = $this->post(route('admin.courses.pdfs.store', $course), [
            ...$data, 'pdf_file' => new UploadedFile($fixture->getPathname(), 'notes.txt', 'text/plain', null, true),
        ], ['Accept' => 'application/json'])->assertOk();
        $pdf = CoursePdf::findOrFail($first->json('pdf.id'));
        $firstPath = $pdf->file_path;
        $replay = $this->post(route('admin.courses.pdfs.store', $course), [
            ...$data, 'pdf_file' => new UploadedFile($fixture->getPathname(), 'notes.txt', 'text/plain', null, true),
        ], ['Accept' => 'application/json'])->assertOk();
        self::assertSame($first->json(), $replay->json());
        self::assertSame($firstPath, $pdf->fresh()->file_path);
        self::assertSame(1, CoursePdf::count());
        self::assertSame(5, (int) $course->fresh()->authoring_version);
        self::assertCount(1, Storage::disk('course-pdfs-shared')->allFiles());
        self::assertSame("Exact notes\n", Storage::disk('course-pdfs-shared')->get($firstPath));
        self::assertSame(1, DB::table('admin_authoring_create_intents')->where('status', 'completed')->count());
        self::assertSame(1, DB::table('account_file_deletions')->count());
        Http::assertNothingSent();
    }

    public function test_moderator_can_complete_attachment_authoring_and_publish_through_http_routes(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('course-pdfs-shared');
        config(['course_pdfs.disk' => 'course-pdfs-shared', 'course_pdfs.shared_storage' => true]);
        $moderator = new User();
        $moderator->forceFill([
            'name_ar' => 'مشرف', 'email' => 'attachment-moderator@example.test',
            'role' => 'moderator', 'active' => true,
        ])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($moderator, 'web');
        $canonical = $this->course(false);
        $old = $canonical->pdfs()->create([
            'title' => 'المصدر المنشور', 'source_type' => 'external', 'platform' => 'computer',
            'external_url' => 'https://example.org/original.zip', 'file_path' => '', 'is_active' => true,
        ]);
        $started = $this->postJson(route('admin.courses.draft.start', $canonical))->assertOk();
        $draft = Course::findOrFail($started->json('draft_course_id'));
        $copy = $draft->pdfs()->firstOrFail();
        $version = $started->json('authoring_version');

        $this->patchJson(route('admin.courses.pdfs.update', [$draft, $copy]), [
            'source_type' => 'upload', 'platform' => 'computer', 'authoring_version' => $version,
        ])->assertUnprocessable()->assertJsonValidationErrors('pdf_file');
        self::assertSame($version, (int) $draft->fresh()->authoring_version);
        self::assertSame($old->external_url, $copy->fresh()->external_url);

        $uploaded = $this->patchJson(route('admin.courses.pdfs.update', [$draft, $copy]), [
            'source_type' => 'upload', 'platform' => 'computer', 'title' => 'دليل محدث',
            'pdf_file' => UploadedFile::fake()->createWithContent('guide.txt', "First guide\n"),
            'authoring_version' => $version,
        ])->assertOk()->assertJsonPath('pdf.source_type', 'upload')->assertJsonPath('pdf.platform', 'computer');
        $version = $uploaded->json('authoring_version');
        $firstPath = $copy->fresh()->file_path;
        Storage::disk('course-pdfs-shared')->assertExists($firstPath);
        self::assertNull($copy->fresh()->external_url);

        $replaced = $this->patchJson(route('admin.courses.pdfs.update', [$draft, $copy]), [
            'pdf_file' => UploadedFile::fake()->createWithContent('guide-v2.txt', "Replacement guide\n"),
            'authoring_version' => $version,
        ])->assertOk()->assertJsonPath('pdf.platform', 'computer');
        $version = $replaced->json('authoring_version');
        $currentPath = $copy->fresh()->file_path;
        self::assertNotSame($firstPath, $currentPath);
        self::assertSame("Replacement guide\n", Storage::disk('course-pdfs-shared')->get($currentPath));
        $retargeted = $this->patchJson(route('admin.courses.pdfs.update', [$draft, $copy]), [
            'platform' => 'mobile', 'title' => 'دليل الهاتف', 'authoring_version' => $version,
        ])->assertOk()->assertJsonPath('pdf.source_type', 'upload')->assertJsonPath('pdf.platform', 'mobile');
        $version = $retargeted->json('authoring_version');
        self::assertSame($currentPath, $copy->fresh()->file_path);

        $workFile = UploadedFile::fake()->createWithContent('work.txt', "Working notes\n");
        $created = $this->post(route('admin.courses.pdfs.store', $draft), [
            'title' => 'ملفات العمل', 'source_type' => 'upload', 'platform' => 'mobile',
            'pdf_file' => new UploadedFile($workFile->getPathname(), 'work.txt', 'text/plain', null, true),
            'authoring_version' => $version, 'authoring_request_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('pdf.source_type', 'upload')->assertJsonPath('pdf.platform', 'mobile');
        $version = $created->json('authoring_version');
        $external = CoursePdf::findOrFail($created->json('pdf.id'));
        $switched = $this->patchJson(route('admin.courses.pdfs.update', [$draft, $external]), [
            'source_type' => 'external', 'platform' => 'computer',
            'external_url' => 'https://example.org/work.zip', 'authoring_version' => $version,
        ])->assertOk()->assertJsonPath('pdf.source_type', 'external')->assertJsonPath('pdf.platform', 'computer')
            ->assertJsonPath('pdf.external_url', 'https://example.org/work.zip')->assertJsonPath('pdf.file_size', null);
        $version = $switched->json('authoring_version');
        self::assertSame('', $external->fresh()->file_path);
        $this->get(route('admin.courses.pdfs.preview', [$draft, $external]))->assertRedirect('https://example.org/work.zip');

        $temporary = $this->postJson(route('admin.courses.pdfs.store', $draft), [
            'title' => 'ملف سيحذف', 'source_type' => 'external', 'platform' => 'mobile',
            'external_url' => 'https://example.org/temporary.zip',
            'authoring_version' => $version, 'authoring_request_id' => (string) Str::uuid(),
        ])->assertOk();
        $version = $temporary->json('authoring_version');
        $temporaryId = $temporary->json('pdf.id');
        $hidden = $this->postJson(route('admin.courses.pdfs.toggle-status', [$draft, $external]), [
            'authoring_version' => $version,
        ])->assertOk()->assertJsonPath('pdf.is_active', false);
        $version = $hidden->json('authoring_version');
        $ordered = $this->postJson(route('admin.courses.pdfs.reorder', $draft), [
            'order' => [$external->id, $copy->id, $temporaryId], 'authoring_version' => $version,
        ])->assertOk();
        $version = $ordered->json('authoring_version');
        self::assertSame([$external->id, $copy->id, $temporaryId], $draft->pdfs()->orderBy('order')->pluck('id')->all());
        $this->deleteJson(route('admin.courses.pdfs.destroy', [$draft, $temporaryId]), [
            'authoring_version' => $version - 1,
        ])->assertStatus(409);
        self::assertNotNull(CoursePdf::find($temporaryId));
        $deleted = $this->deleteJson(route('admin.courses.pdfs.destroy', [$draft, $temporaryId]), [
            'authoring_version' => $version,
        ])->assertOk()->assertJsonPath('pdf.deleted', true);
        $version = $deleted->json('authoring_version');
        self::assertNull(CoursePdf::find($temporaryId));
        self::assertSame([$old->id], $canonical->pdfs()->pluck('id')->all());
        self::assertSame('https://example.org/original.zip', $old->fresh()->external_url);

        // This fixture omits unrelated course content. Only readiness is stubbed;
        // the moderator route, draft swap and attachment payload remain real.
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->twice()->andReturn(['ready' => true, 'issues' => []]);
        $this->app->instance(CoursePublishingService::class, $publishing);
        $this->patchJson(route('admin.courses.update', $draft), [
            'authoring_version' => $version, 'publishing_intent' => 'publish', 'is_catalog_visible' => true,
        ])->assertOk()->assertJsonPath('saved', true)->assertJsonPath('published', true)->assertJsonPath('course.id', $canonical->id);
        self::assertSame([$external->id, $copy->id], $canonical->pdfs()->orderBy('order')->pluck('id')->all());
        self::assertSame('external', $external->fresh()->source_type);
        self::assertSame('computer', $external->fresh()->platform);
        self::assertFalse($external->fresh()->is_active);
        self::assertSame('https://example.org/work.zip', $external->fresh()->external_url);
        self::assertSame('upload', $copy->fresh()->source_type);
        self::assertSame('mobile', $copy->fresh()->platform);
        self::assertSame('دليل الهاتف', $copy->fresh()->title);
        self::assertSame($currentPath, $copy->fresh()->file_path);
        Storage::disk('course-pdfs-shared')->assertExists($currentPath);
        self::assertSame($copy->id, app(CourseStagedAuthoringService::class)->currentEntityId(CoursePdf::class, $old->id));
        self::assertSame('https://example.org/original.zip', $old->fresh()->external_url);
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
        return [
            'admin isolated draft' => [true, 'admin'],
            'admin published course resolved to draft' => [false, 'admin'],
            'moderator isolated draft' => [true, 'moderator'],
            'moderator published course resolved to draft' => [false, 'moderator'],
        ];
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
