<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\API\CoursePdfController;
use App\Http\Controllers\Admin\CoursePdfController as AdminCoursePdfController;
use App\Http\Requests\Admin\CoursePdfOrderRequest;
use App\Http\Requests\Admin\CoursePdfRequest;
use App\Http\Requests\Admin\CoursePdfVersionRequest;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\User;
use App\Services\CourseModuleAccessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CoursePdfSharedStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->boolean('is_coming_soon')->default(false);
            $table->unsignedInteger('authoring_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('sectionable_type')->nullable();
            $table->unsignedBigInteger('sectionable_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_authoring_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('canonical_course_id');
            $table->unsignedBigInteger('revision_course_id')->unique();
            $table->unsignedBigInteger('base_authoring_version');
            $table->unsignedBigInteger('published_authoring_version')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('active_slot', 80)->nullable()->unique();
            $table->uuid('clone_key')->unique();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retain_until')->nullable();
            $table->timestamps();
        });
        Schema::create('course_authoring_revision_entities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_authoring_revision_id');
            $table->string('entity_type', 120);
            $table->unsignedBigInteger('source_entity_id');
            $table->unsignedBigInteger('revision_entity_id');
            $table->boolean('survives_publish')->default(false);
            $table->boolean('carries_learner_state')->default(false);
            $table->unsignedBigInteger('learner_root_entity_id')->nullable();
        });
        Schema::create('course_pdfs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('title')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description')->nullable();
            $table->text('description_en')->nullable();
            $table->string('file_path');
            $table->string('storage_disk')->nullable();
            $table->string('original_filename')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        (require database_path('migrations/2026_08_07_000022_create_account_file_deletions_table.php'))->up();

        DB::table('courses')->insert(['id' => 7, 'name_ar' => 'اختبار', 'is_coming_soon' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert(['id' => 42, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('course_sections')->insert([
            'id' => 1,
            'course_id' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Storage::fake('course-pdfs-shared');
        config([
            'course_pdfs.disk' => 'course-pdfs-shared',
            'course_pdfs.shared_storage' => true,
            'filesystems.disks.course-pdfs-shared' => [
                'driver' => 'local',
                'root' => sys_get_temp_dir() . '/course-pdfs-shared',
                'visibility' => 'private',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('account_file_deletions');
        Schema::dropIfExists('course_pdfs');
        Schema::dropIfExists('course_authoring_revision_entities');
        Schema::dropIfExists('course_authoring_revisions');
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('course_enrollments');
        Schema::dropIfExists('users');
        Schema::dropIfExists('courses');
        parent::tearDown();
    }

    public function test_entitled_pdf_download_reads_shared_disk(): void
    {
        $pdf = CoursePdf::create([
            'course_id' => 7,
            'title' => 'ملف',
            'file_path' => 'courses/7/example.pdf',
            'storage_disk' => 'course-pdfs-shared',
            'file_size' => 10,
            'is_active' => true,
        ]);
        Storage::disk('course-pdfs-shared')->put($pdf->file_path, '0123456789');
        DB::table('course_enrollments')->insert([
            'user_id' => 42,
            'course_id' => 7,
            'is_active' => true,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->authenticate(42);

        $course = Course::query()->findOrFail(7);
        $user = User::query()->findOrFail(42);
        $url = app(CourseModuleAccessService::class)
            ->temporaryPdfDownloadContract($user, $course, $pdf)['download_url'];
        $response = $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Accept-Ranges', 'bytes');
        self::assertSame(
            '0123456789',
            file_get_contents($response->baseResponse->getFile()->getPathname())
        );
    }

    public function test_expired_enrollment_cannot_read_pdf(): void
    {
        $pdf = CoursePdf::create([
            'course_id' => 7,
            'title' => 'ملف',
            'file_path' => 'courses/7/example.pdf',
            'storage_disk' => 'course-pdfs-shared',
            'file_size' => 4,
            'is_active' => true,
        ]);
        Storage::disk('course-pdfs-shared')->put($pdf->file_path, '%PDF');
        DB::table('course_enrollments')->insert([
            'user_id' => 42,
            'course_id' => 7,
            'is_active' => true,
            'expires_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->authenticate(42);

        $course = Course::query()->findOrFail(7);
        $user = User::query()->findOrFail(42);
        $url = app(CourseModuleAccessService::class)
            ->temporaryPdfDownloadContract($user, $course, $pdf)['download_url'];
        $this->get($url)->assertForbidden();
    }

    public function test_migration_gives_duplicate_legacy_references_distinct_verified_keys(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('course-pdfs/7/legacy.pdf', '%PDF-legacy');
        CoursePdf::create([
            'course_id' => 7,
            'title' => 'الأول',
            'file_path' => 'course-pdfs/7/legacy.pdf',
            'storage_disk' => null,
            'file_size' => 11,
        ]);
        CoursePdf::create([
            'course_id' => 7,
            'title' => 'الثاني',
            'file_path' => 'course-pdfs/7/legacy.pdf',
            'storage_disk' => 'local',
            'file_size' => 11,
        ]);

        $status = Artisan::call('course-pdfs:migrate-storage', ['--execute' => true]);
        self::assertSame(0, $status, Artisan::output());
        $rows = CoursePdf::query()->orderBy('id')->get();
        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]->file_path, $rows[1]->file_path);
        foreach ($rows as $row) {
            self::assertSame('course-pdfs-shared', $row->storage_disk);
            Storage::disk('course-pdfs-shared')->assertExists($row->file_path);
            self::assertSame('%PDF-legacy', Storage::disk('course-pdfs-shared')->get($row->file_path));
        }
    }

    public function test_admin_upload_persists_configured_disk_and_server_generated_unique_keys(): void
    {
        $course = Course::query()->findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();

        foreach (['first', 'second'] as $title) {
            $request = CoursePdfRequest::create('/admin/course-pdf', 'POST', [
                'title' => $title,
                'is_active' => true,
                'authoring_version' => $course->authoring_version,
                'authoring_request_id' => (string) Str::uuid(),
            ]);
            $request->files->set(
                'pdf_file',
                UploadedFile::fake()->createWithContent(
                    'same-original-name.pdf',
                    "%PDF-1.4\n1 0 obj\n<< /Title ({$title}) >>\nendobj\n%%EOF"
                )
            );
            $this->prepareFormRequest($request, $course);

            $response = app(AdminCoursePdfController::class)->store($request, $course);
            self::assertTrue($response->isRedirect());
            $course->refresh();
        }

        $rows = CoursePdf::query()->orderBy('id')->get();
        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]->file_path, $rows[1]->file_path);
        foreach ($rows as $row) {
            self::assertSame('course-pdfs-shared', $row->storage_disk);
            self::assertMatchesRegularExpression('~^courses/7/[0-9a-f]{64}\.pdf$~', $row->file_path);
            self::assertStringNotContainsString('same-original-name', $row->file_path);
            Storage::disk('course-pdfs-shared')->assertExists($row->file_path);
        }
    }

    public function test_admin_pdf_mutations_share_one_authoring_json_contract(): void
    {
        $course = Course::query()->findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();
        $controller = app(AdminCoursePdfController::class);

        $store = CoursePdfRequest::create('/admin/course-pdf', 'POST', [
            'title' => 'دليل الكورس',
            'is_active' => true,
            'authoring_version' => 1,
            'authoring_request_id' => (string) Str::uuid(),
        ]);
        $store->headers->set('Accept', 'application/json');
        $store->files->set(
            'pdf_file',
            UploadedFile::fake()->createWithContent(
                'course-guide.pdf',
                "%PDF-1.4\n1 0 obj\n<< /Title (Guide) >>\nendobj\n%%EOF"
            )
        );
        $this->prepareFormRequest($store, $course);
        $stored = $controller->store($store, $course)->getData(true);

        self::assertTrue($stored['success']);
        self::assertSame(2, $stored['authoring_version']);
        $this->assertPdfPayload($stored['pdf']);
        $pdf = CoursePdf::query()->findOrFail($stored['pdf']['id']);

        $duplicateStore = CoursePdfRequest::create('/admin/course-pdf', 'POST', [
            'title' => 'نسخة مكررة',
            'is_active' => true,
            'authoring_version' => 2,
            'authoring_request_id' => (string) Str::uuid(),
        ]);
        $duplicateStore->headers->set('Accept', 'application/json');
        $duplicateStore->files->set(
            'pdf_file',
            UploadedFile::fake()->createWithContent(
                'same-guide.pdf',
                "%PDF-1.4\n1 0 obj\n<< /Title (Guide) >>\nendobj\n%%EOF"
            )
        );
        $this->prepareFormRequest($duplicateStore, $course);
        $duplicate = $controller->store($duplicateStore, $course)->getData(true);
        self::assertSame($pdf->id, $duplicate['pdf']['id']);
        self::assertSame(2, $duplicate['authoring_version']);
        self::assertSame(1, CoursePdf::query()->count());

        $update = CoursePdfRequest::create('/admin/course-pdf/'.$pdf->id, 'PUT', [
            'title' => 'دليل الكورس المحدث',
            'authoring_version' => 2,
        ]);
        $update->headers->set('Accept', 'application/json');
        $this->prepareFormRequest($update, $course);
        $updated = $controller->update($update, $course, $pdf)->getData(true);
        self::assertSame(3, $updated['authoring_version']);
        self::assertSame('دليل الكورس المحدث', $updated['pdf']['title']);
        $this->assertPdfPayload($updated['pdf']);

        $toggle = CoursePdfVersionRequest::create('/admin/course-pdf/'.$pdf->id.'/toggle', 'POST', [
            'authoring_version' => 3,
        ]);
        $toggle->headers->set('Accept', 'application/json');
        $this->prepareFormRequest($toggle, $course);
        $toggled = $controller->toggleStatus($toggle, $course, $pdf)->getData(true);
        self::assertSame(4, $toggled['authoring_version']);
        self::assertFalse($toggled['pdf']['is_active']);
        $this->assertPdfPayload($toggled['pdf']);

        $reorder = CoursePdfOrderRequest::create('/admin/course-pdf/reorder', 'POST', [
            'order' => [$pdf->id],
            'authoring_version' => 4,
        ]);
        $reorder->headers->set('Accept', 'application/json');
        $this->prepareFormRequest($reorder, $course);
        $reordered = $controller->reorder($reorder, $course)->getData(true);
        self::assertSame(5, $reordered['authoring_version']);
        self::assertCount(1, $reordered['pdfs']);
        $this->assertPdfPayload($reordered['pdfs'][0]);

        $destroy = CoursePdfVersionRequest::create('/admin/course-pdf/'.$pdf->id, 'DELETE', [
            'authoring_version' => 5,
        ]);
        $destroy->headers->set('Accept', 'application/json');
        $this->prepareFormRequest($destroy, $course);
        $deleted = $controller->destroy($destroy, $course, $pdf)->getData(true);
        self::assertSame(6, $deleted['authoring_version']);
        self::assertTrue($deleted['pdf']['deleted']);
        $this->assertPdfPayload($deleted['pdf']);
        self::assertNull(CoursePdf::query()->find($pdf->id));
    }

    public function test_admin_pdf_html_mutation_returns_to_course_studio_when_requested(): void
    {
        $course = Course::query()->findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();
        $pdf = CoursePdf::query()->create([
            'course_id' => $course->id,
            'title' => 'مرفق',
            'file_path' => 'courses/7/attachment.pdf',
            'storage_disk' => 'course-pdfs-shared',
            'file_size' => 4,
            'order' => 1,
            'is_active' => true,
        ]);

        $request = CoursePdfRequest::create('/admin/course-pdf/'.$pdf->id, 'PUT', [
            'title' => 'مرفق محدث',
            'authoring_version' => 1,
            'return_to' => 'studio',
        ]);
        $this->prepareFormRequest($request, $course);
        $response = app(AdminCoursePdfController::class)->update($request, $course, $pdf);

        self::assertTrue($response->isRedirect(
            route('admin.courses.show', $course).'#studioCourseAttachments'
        ));
        self::assertSame(2, (int) $course->fresh()->authoring_version);
    }

    /** @param array<string, mixed> $payload */
    private function assertPdfPayload(array $payload): void
    {
        foreach ([
            'id', 'title', 'title_en', 'description', 'description_en',
            'original_filename', 'file_size', 'formatted_file_size', 'order',
            'is_active', 'preview_url', 'update_url', 'toggle_url', 'delete_url',
        ] as $key) {
            self::assertArrayHasKey($key, $payload);
        }
    }

    private function prepareFormRequest(FormRequest $request, Course $course): void
    {
        $route = new Route([$request->method()], $request->path(), static fn () => null);
        $route->bind($request);
        $route->setParameter('course', $course);
        $request->setRouteResolver(static fn () => $route);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->validateResolved();
    }

    private function authenticate(int $id): void
    {
        $user = new User();
        $user->forceFill(['id' => $id, 'active' => true]);
        $user->exists = true;
        auth('api')->setUser($user);
    }
}
