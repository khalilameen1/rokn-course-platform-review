<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminSessionIdentity;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminCoursePdfCreateReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->withoutMiddleware(RequireAdminMfa::class);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('course-pdfs-shared');
        config(['course_pdfs.disk' => 'course-pdfs-shared', 'course_pdfs.shared_storage' => true]);
    }

    public function test_uploaded_create_can_be_read_without_resending_bytes_and_returns_current_metadata(): void
    {
        $this->actingAs($this->actor(), 'web');
        $course = $this->course();
        $intent = (string) Str::uuid();
        $fixture = UploadedFile::fake()->createWithContent('notes.txt', "Lesson notes\n");
        $response = $this->post(route('admin.courses.pdfs.store', $course), [
            ...$this->input($intent),
            'source_type' => 'upload',
            'external_url' => null,
            'pdf_file' => new UploadedFile($fixture->getPathname(), 'notes.txt', 'text/plain', null, true),
        ], ['Accept' => 'application/json'])->assertOk();
        $pdf = CoursePdf::findOrFail($response->json('pdf.id'));
        $pdf->forceFill(['title' => 'Current title', 'platform' => 'computer'])->save();
        $course->forceFill(['authoring_version' => 7])->save();
        $before = DB::table('admin_authoring_create_intents')->first();

        for ($i = 0; $i < 2; $i++) {
            $this->getJson($this->lookup($course, $intent))->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('state', 'completed')
                ->assertJsonPath('success', true)
                ->assertJsonPath('pdf.id', $pdf->id)
                ->assertJsonPath('pdf.title', 'Current title')
                ->assertJsonPath('pdf.platform', 'computer')
                ->assertJsonPath('receipt_authoring_version', 5)
                ->assertJsonPath('authoring_version', 7);
        }
        self::assertEquals($before, DB::table('admin_authoring_create_intents')->first());
        self::assertSame(1, CoursePdf::count());
        self::assertCount(1, Storage::disk('course-pdfs-shared')->allFiles());
        Http::assertNothingSent();
    }

    public function test_original_canonical_parent_receipt_resolves_only_its_existing_draft_without_allocating_another(): void
    {
        $this->actingAs($this->actor(), 'web');
        $course = $this->course(false);
        $intent = (string) Str::uuid();
        $created = $this->postJson(route('admin.courses.pdfs.store', $course), $this->input($intent))->assertOk();
        $pdf = CoursePdf::findOrFail($created->json('pdf.id'));
        self::assertNotSame($course->id, $pdf->course_id);
        $this->getJson($this->lookup($course, $intent))->assertOk()
            ->assertJsonPath('state', 'completed')->assertJsonPath('pdf.id', $pdf->id)
            ->assertJsonPath('authoring_version', 5);
        // The original intent is not transferable to a different route parent.
        $this->getJson($this->lookup($pdf->course, $intent))->assertOk()->assertJsonPath('state', 'absent');
        $draftIntent = (string) Str::uuid();
        $this->postJson(route('admin.courses.pdfs.store', $pdf->course), [
            ...$this->input($draftIntent), 'authoring_version' => 5,
        ])->assertOk();
        DB::table('course_authoring_revisions')->update(['status' => 'archived', 'active_slot' => null]);
        $this->getJson($this->lookup($course, $intent))->assertOk()->assertJsonPath('state', 'superseded');
        $this->getJson($this->lookup($pdf->course, $draftIntent))->assertOk()->assertJsonPath('state', 'superseded');
        self::assertSame(2, Course::count());
        self::assertSame(1, DB::table('course_authoring_revisions')->count());
    }

    public function test_duplicate_external_create_receipt_does_not_require_a_version_increment(): void
    {
        $this->actingAs($this->actor(), 'web');
        $course = $this->course();
        $first = $this->postJson(route('admin.courses.pdfs.store', $course), $this->input((string) Str::uuid()))->assertOk();
        $intent = (string) Str::uuid();
        $this->postJson(route('admin.courses.pdfs.store', $course), [
            ...$this->input($intent), 'authoring_version' => 5,
        ])->assertOk()->assertJsonPath('authoring_version', 5);
        $this->getJson($this->lookup($course, $intent))->assertOk()
            ->assertJsonPath('state', 'completed')->assertJsonPath('pdf.id', $first->json('pdf.id'))
            ->assertJsonPath('receipt_authoring_version', 5)->assertJsonPath('authoring_version', 5);
        self::assertSame(1, CoursePdf::count());
    }

    public function test_missing_processing_failed_and_foreign_receipts_are_read_only(): void
    {
        $owner = $this->actor();
        $course = $this->course(false);
        $intent = (string) Str::uuid();
        $this->actingAs($owner, 'web');
        $this->getJson($this->lookup($course, $intent))->assertOk()->assertJsonPath('state', 'absent');
        DB::table('admin_authoring_create_intents')->insert([
            'actor_id' => $owner->id, 'route_name' => 'admin.courses.pdfs.store',
            'parent_scope' => hash('sha256', json_encode(['course' => (string) $course->id])),
            'intent_id' => $intent, 'request_fingerprint' => hash('sha256', 'body'),
            'status' => 'processing', 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
        ]);
        $before = DB::table('admin_authoring_create_intents')->first();
        $this->getJson($this->lookup($course, $intent))->assertOk()->assertJsonPath('state', 'processing');
        self::assertEquals($before, DB::table('admin_authoring_create_intents')->first());
        DB::table('admin_authoring_create_intents')->update(['status' => 'failed']);
        $this->getJson($this->lookup($course, $intent))->assertOk()->assertJsonPath('state', 'failed');
        $this->getJson($this->lookup($this->course(), $intent))->assertOk()->assertJsonPath('state', 'absent');
        $this->withSession([AdminSessionIdentity::SESSION_KEY => '']);
        $this->actingAs($this->actor(), 'web');
        $this->getJson($this->lookup($course, $intent))->assertOk()->assertJsonPath('state', 'absent');
        self::assertSame(0, DB::table('course_authoring_revisions')->count());
        self::assertSame(0, CoursePdf::count());
        Http::assertNothingSent();
    }

    public function test_deleted_or_moved_resource_is_superseded_and_non_staff_cannot_read_receipt(): void
    {
        $this->actingAs($this->actor(), 'web');
        $course = $this->course();
        $intent = (string) Str::uuid();
        $created = $this->postJson(route('admin.courses.pdfs.store', $course), $this->input($intent))->assertOk();
        $pdf = CoursePdf::findOrFail($created->json('pdf.id'));
        $pdf->forceFill(['course_id' => $this->course()->id])->save();
        $this->getJson($this->lookup($course, $intent))->assertOk()
            ->assertJsonPath('state', 'superseded')->assertJsonMissingPath('pdf');
        $pdf->delete();
        $this->getJson($this->lookup($course, $intent))->assertOk()->assertJsonPath('state', 'superseded');
        $this->withSession([AdminSessionIdentity::SESSION_KEY => '']);
        $this->actingAs($this->actor('user'), 'web');
        $this->getJson($this->lookup($course, $intent))->assertForbidden();
    }

    private function lookup(Course $course, string $intent): string
    {
        return '/dashboard/courses/'.$course->id.'/pdfs/create-intents/'.$intent;
    }

    private function input(string $intent): array
    {
        return ['title' => 'Files', 'source_type' => 'external', 'platform' => 'mobile',
            'external_url' => 'https://files.example.org/course.zip',
            'authoring_version' => 4, 'authoring_request_id' => $intent];
    }

    private function actor(string $role = 'moderator'): User
    {
        $user = new User();
        $user->forceFill(['name_ar' => 'Editor', 'email' => Str::uuid().'@example.test', 'role' => $role, 'active' => true])->save();
        return $user;
    }

    private function course(bool $draft = true): Course
    {
        $course = new Course();
        $course->forceFill(['tenant_id' => 1, 'name_ar' => 'Course', 'price' => 800,
            'is_coming_soon' => $draft, 'is_catalog_visible' => !$draft,
            'authoring_version' => 4, 'last_published_authoring_version' => $draft ? null : 4,
            'published_at' => $draft ? null : now()])->save();
        return $course;
    }
}
