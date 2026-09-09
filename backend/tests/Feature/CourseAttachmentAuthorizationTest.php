<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\Totp;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\User;
use App\Services\CoursePublishingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CourseAttachmentAuthorizationTest extends TestCase
{
    private Course $course;
    private CoursePdf $original;
    private CoursePdf $removable;

    protected function setUp(): void
    {
        parent::setUp();
        // File admission must commit its real cleanup ledger, so do not wrap
        // these HTTP uploads in RefreshDatabase's outer transaction.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('attachment-authorization');
        config([
            'course_pdfs.disk' => 'attachment-authorization',
            'course_pdfs.shared_storage' => true,
        ]);
        $this->course = Course::query()->forceCreate([
            'tenant_id' => 1,
            'name_ar' => 'كورس صلاحيات المرفقات',
            'description_ar' => 'وصف الكورس',
            'price' => 100,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'authoring_version' => 4,
            'last_published_authoring_version' => 4,
            'published_at' => now(),
        ]);
        $this->original = $this->uploadedAttachment('original.txt', 'Original notes', 1);
        $this->removable = $this->uploadedAttachment('removable.txt', 'Other notes', 2);
    }

    #[DataProvider('authorRoles')]
    public function test_author_can_create_edit_replace_reorder_hide_delete_and_publish_attachments(string $role): void
    {
        $this->signIn($role);
        // Only unrelated course readiness is omitted. Roles, MFA, draft
        // resolution, upload admission and publication are the real workflow.
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->twice()->andReturn(['ready' => true, 'issues' => []]);
        $this->app->instance(CoursePublishingService::class, $publishing);

        $started = $this->postJson(route('admin.courses.draft.start', $this->course))->assertOk();
        $draft = Course::findOrFail($started->json('draft_course_id'));
        $version = (int) $started->json('authoring_version');
        $original = $draft->pdfs()->where('original_filename', 'original.txt')->firstOrFail();
        $removable = $draft->pdfs()->where('original_filename', 'removable.txt')->firstOrFail();

        $created = $this->postJson(route('admin.courses.pdfs.store', $draft), [
            ...$this->externalPayload(), 'authoring_version' => $version,
        ])->assertOk()->assertJsonPath('pdf.source_type', 'external')->assertJsonPath('pdf.platform', 'computer');
        $version = (int) $created->json('authoring_version');
        $externalId = $created->json('pdf.id');
        $this->assertDatabaseHas('course_pdfs', ['id' => $externalId, 'course_id' => $draft->id]);

        $edited = $this->patchJson(route('admin.courses.pdfs.update', [$draft, $original]), [
            'title' => 'عنوان الدليل الجديد', 'platform' => 'computer', 'authoring_version' => $version,
        ])->assertOk()->assertJsonPath('pdf.title', 'عنوان الدليل الجديد')->assertJsonPath('pdf.platform', 'computer');
        $version = (int) $edited->json('authoring_version');
        self::assertSame('original.txt', $original->fresh()->file_path);

        $fixture = UploadedFile::fake()->createWithContent('replacement.txt', 'Replacement notes');
        $replaced = $this->patchJson(route('admin.courses.pdfs.update', [$draft, $original]), [
            'pdf_file' => new UploadedFile($fixture->getPathname(), 'replacement.txt', 'text/plain', null, true),
            'authoring_version' => $version,
        ])->assertOk();
        $version = (int) $replaced->json('authoring_version');
        $replacementPath = $original->fresh()->file_path;
        self::assertNotSame('original.txt', $replacementPath);
        self::assertSame('Replacement notes', Storage::disk('attachment-authorization')->get($replacementPath));

        $order = [$externalId, $original->id, $removable->id];
        $ordered = $this->postJson(route('admin.courses.pdfs.reorder', $draft), [
            'order' => $order, 'authoring_version' => $version,
        ])->assertOk();
        $version = (int) $ordered->json('authoring_version');
        self::assertSame($order, $draft->pdfs()->orderBy('order')->pluck('id')->all());

        $hidden = $this->postJson(route('admin.courses.pdfs.toggle-status', [$draft, $externalId]), [
            'authoring_version' => $version,
        ])->assertOk()->assertJsonPath('pdf.is_active', false);
        $version = (int) $hidden->json('authoring_version');
        self::assertFalse(CoursePdf::findOrFail($externalId)->is_active);

        $deleted = $this->deleteJson(route('admin.courses.pdfs.destroy', [$draft, $removable]), [
            'authoring_version' => $version,
        ])->assertOk()->assertJsonPath('pdf.deleted', true);
        $version = (int) $deleted->json('authoring_version');
        self::assertNull(CoursePdf::find($removable->id));
        self::assertSame([$this->original->id, $this->removable->id], $this->course->pdfs()->orderBy('order')->pluck('id')->all());

        $this->patchJson(route('admin.courses.update', $draft), [
            'authoring_version' => $version, 'publishing_intent' => 'publish', 'is_catalog_visible' => true,
        ])->assertOk()->assertJsonPath('saved', true)->assertJsonPath('published', true)
            ->assertJsonPath('course.id', $this->course->id);
        self::assertSame([$externalId, $original->id], $this->course->pdfs()->orderBy('order')->pluck('id')->all());
        self::assertSame('عنوان الدليل الجديد', $original->fresh()->title);
        self::assertSame('computer', $original->fresh()->platform);
        self::assertSame('replacement.txt', $original->fresh()->original_filename);
        self::assertSame($replacementPath, $original->fresh()->file_path);
        self::assertFalse(CoursePdf::findOrFail($externalId)->is_active);
        self::assertSame('Original notes', Storage::disk('attachment-authorization')->get('original.txt'));
        Http::assertNothingSent();
    }

    #[DataProvider('nonAuthorRoles')]
    public function test_non_author_cannot_mutate_attachments_or_allocate_a_draft(string $role): void
    {
        if ($role !== 'guest') $this->signIn($role);
        $before = $this->authoringSnapshot();
        $fixture = UploadedFile::fake()->createWithContent('replacement.txt', 'Unauthorized replacement');
        $version = ['authoring_version' => 4];
        $actions = [
            'create' => ['POST', route('admin.courses.pdfs.store', $this->course), [...$this->externalPayload(), ...$version]],
            'edit' => ['PATCH', route('admin.courses.pdfs.update', [$this->course, $this->original]), ['title' => 'Unauthorized edit', ...$version]],
            'replace' => ['PATCH', route('admin.courses.pdfs.update', [$this->course, $this->original]), [
                'pdf_file' => new UploadedFile($fixture->getPathname(), 'replacement.txt', 'text/plain', null, true), ...$version,
            ]],
            'reorder' => ['POST', route('admin.courses.pdfs.reorder', $this->course), ['order' => [$this->removable->id, $this->original->id], ...$version]],
            'toggle' => ['POST', route('admin.courses.pdfs.toggle-status', [$this->course, $this->original]), $version],
            'delete' => ['DELETE', route('admin.courses.pdfs.destroy', [$this->course, $this->original]), $version],
            'publish' => ['PATCH', route('admin.courses.update', $this->course), ['publishing_intent' => 'publish', 'is_catalog_visible' => true, ...$version]],
        ];

        foreach ($actions as $action => [$verb, $url, $payload]) {
            $response = $this->json($verb, $url, $payload);
            if ($role === 'guest') $response->assertRedirect(route('login'));
            else $response->assertForbidden();
            self::assertSame($before, $this->authoringSnapshot(), $role.' '.$action.' must have no authoring side effects');
        }
        Http::assertNothingSent();
    }

    public static function authorRoles(): array
    {
        return [['admin'], ['moderator']];
    }

    public static function nonAuthorRoles(): array
    {
        return [['client'], ['guest']];
    }

    private function signIn(string $role): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $actor = User::query()->forceCreate([
            'name_ar' => 'مستخدم الاختبار', 'email' => $role.'-attachments@example.test',
            'role' => $role, 'active' => true,
            'admin_totp_secret' => $secret, 'admin_totp_confirmed_at' => now(),
        ]);
        $this->actingAs($actor, 'web')->withSession([
            'admin_mfa_verified_user_id' => $actor->id,
            'admin_mfa_verified_at' => time(),
            'admin_mfa_secret_fingerprint' => app(Totp::class)->secretFingerprint($secret),
        ]);
    }

    private function uploadedAttachment(string $path, string $bytes, int $order): CoursePdf
    {
        Storage::disk('attachment-authorization')->put($path, $bytes);
        return $this->course->pdfs()->create([
            'title' => $path, 'source_type' => 'upload', 'platform' => 'mobile',
            'file_path' => $path, 'storage_disk' => 'attachment-authorization',
            'original_filename' => $path, 'file_extension' => 'txt', 'mime_type' => 'text/plain',
            'file_size' => strlen($bytes), 'content_sha256' => hash('sha256', $bytes),
            'is_active' => true, 'order' => $order,
        ]);
    }

    private function externalPayload(): array
    {
        return [
            'title' => 'ملفات العمل', 'source_type' => 'external', 'platform' => 'computer',
            'external_url' => 'https://files.example.test/work.zip',
            'authoring_request_id' => (string) Str::uuid(),
        ];
    }

    private function authoringSnapshot(): array
    {
        return [
            'courses' => DB::table('courses')->orderBy('id')->get()->toJson(),
            'attachments' => DB::table('course_pdfs')->orderBy('id')->get()->toJson(),
            'revisions' => DB::table('course_authoring_revisions')->count(),
            'intents' => DB::table('admin_authoring_create_intents')->count(),
            'file_cleanup' => DB::table('account_file_deletions')->count(),
            'files' => collect(Storage::disk('attachment-authorization')->allFiles())->sort()->mapWithKeys(
                fn (string $path): array => [$path => Storage::disk('attachment-authorization')->get($path)]
            )->all(),
        ];
    }
}
