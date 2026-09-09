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
        (require database_path('migrations/2026_09_08_000001_add_source_and_platform_to_course_pdfs.php'))->up();

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
            'description' => 'وصف محفوظ',
            'description_en' => 'Preserved description',
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
            'order' => null,
        ]);
        $update->headers->set('Accept', 'application/json');
        $this->prepareFormRequest($update, $course);
        $updated = $controller->update($update, $course, $pdf)->getData(true);
        self::assertSame(3, $updated['authoring_version']);
        self::assertSame('دليل الكورس المحدث', $updated['pdf']['title']);
        self::assertSame('وصف محفوظ', $updated['pdf']['description']);
        self::assertSame('Preserved description', $updated['pdf']['description_en']);
        self::assertSame($pdf->order, $updated['pdf']['order']);
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

    public function test_external_attachment_create_deduplicates_per_device_and_preserves_patch_omissions(): void
    {
        $course = Course::findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();
        $controller = app(AdminCoursePdfController::class);
        $data = [
            'title' => 'ملفات التصميم', 'source_type' => 'external', 'platform' => 'computer',
            'external_url' => 'https://files.example.org/large-assets.zip',
            'authoring_version' => 1, 'authoring_request_id' => (string) Str::uuid(),
        ];
        $request = CoursePdfRequest::create('/admin/course-pdf', 'POST', $data);
        $request->headers->set('Accept', 'application/json');
        $this->prepareFormRequest($request, $course);
        $payload = $controller->store($request, $course)->getData(true);
        self::assertSame('external', $payload['pdf']['source_type']);
        self::assertSame('computer', $payload['pdf']['platform']);
        self::assertNull($payload['pdf']['file_size']);
        self::assertSame('', $payload['pdf']['formatted_file_size']);
        self::assertSame([], Storage::disk('course-pdfs-shared')->allFiles());
        self::assertSame(0, DB::table('account_file_deletions')->count());

        $duplicate = CoursePdfRequest::create('/admin/course-pdf', 'POST', array_replace($data, [
            'authoring_version' => 2, 'authoring_request_id' => (string) Str::uuid(),
        ]));
        $duplicate->headers->set('Accept', 'application/json');
        $this->prepareFormRequest($duplicate, $course->fresh());
        $same = $controller->store($duplicate, $course->fresh())->getData(true);
        self::assertSame($payload['pdf']['id'], $same['pdf']['id']);
        self::assertSame(2, $same['authoring_version']);
        self::assertSame('هذا الرابط مضاف بالفعل لهذا الجهاز', $same['message']);

        $pdf = CoursePdf::findOrFail($payload['pdf']['id']);
        $patch = CoursePdfRequest::create('/admin/course-pdf/'.$pdf->id, 'PATCH', [
            'title' => 'عنوان جديد', 'authoring_version' => 2,
        ]);
        $patch->headers->set('Accept', 'application/json');
        $this->prepareFormRequest($patch, $course->fresh(), $pdf);
        $updated = $controller->update($patch, $course->fresh(), $pdf)->getData(true);
        self::assertSame('computer', $updated['pdf']['platform']);
        self::assertSame($data['external_url'], $updated['pdf']['external_url']);
        self::assertSame(3, $updated['authoring_version']);

        $mobile = CoursePdfRequest::create('/admin/course-pdf', 'POST', array_replace($data, [
            'platform' => 'mobile', 'authoring_version' => 3, 'authoring_request_id' => (string) Str::uuid(),
        ]));
        $mobile->headers->set('Accept', 'application/json');
        $this->prepareFormRequest($mobile, $course->fresh());
        $other = $controller->store($mobile, $course->fresh())->getData(true);
        self::assertNotSame($pdf->id, $other['pdf']['id']);
        self::assertSame(2, CoursePdf::count());
    }

    public function test_external_download_and_preview_redirect_only_after_existing_access_checks(): void
    {
        $pdf = CoursePdf::create([
            'course_id' => 7, 'title' => 'المصدر', 'source_type' => 'external',
            'platform' => 'computer', 'external_url' => 'https://drive.google.com/file/d/abc123/view?resourcekey=key',
            'file_path' => '', 'is_active' => true,
        ]);
        DB::table('course_enrollments')->insert([
            'user_id' => 42, 'course_id' => 7, 'is_active' => true,
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->authenticate(42);
        $metadata = $this->getJson('/api/v1/courses/7/pdfs/'.$pdf->id)->assertOk()->json('data');
        self::assertTrue($metadata['external']);
        self::assertSame('computer', $metadata['platform']);
        self::assertSame($pdf->external_url, $metadata['external_url']);
        foreach (['file_type', 'mime_type', 'file_size', 'file_size_bytes', 'file_name'] as $field) {
            self::assertNull($metadata[$field]);
        }
        self::assertStringNotContainsString('drive.google.com', $metadata['download_url']);
        $target = \App\Support\CourseAttachmentExternalUrl::normalize($pdf->external_url);
        $this->get($metadata['download_url'])->assertRedirect($target)->assertHeader('Referrer-Policy', 'no-referrer');
        self::assertSame($target, app(AdminCoursePdfController::class)->preview(Course::findOrFail(7), $pdf)->getTargetUrl());
        $this->get($metadata['download_url'].'&tampered=1')->assertForbidden();
        $pdf->update(['is_active' => false]);
        $this->get($metadata['download_url'])->assertForbidden();
        $pdf->update(['is_active' => true]);
        DB::table('course_enrollments')->update(['is_active' => false]);
        $this->get($metadata['download_url'])->assertForbidden();
        $this->getJson('/api/v1/courses/7/pdfs/'.$pdf->id)->assertForbidden();
        self::assertSame([], Storage::disk('course-pdfs-shared')->allFiles());
    }

    public function test_source_switch_requires_replacement_and_only_cleans_replaced_local_file(): void
    {
        $course = Course::findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();
        $pdf = CoursePdf::create([
            'course_id' => 7, 'title' => 'ملف', 'file_path' => 'courses/7/old.pdf',
            'storage_disk' => 'course-pdfs-shared', 'file_size' => 10,
        ]);
        Storage::disk('course-pdfs-shared')->put($pdf->file_path, '%PDF-old');
        $service = app(\App\Services\AdminCoursePdfApplicationService::class);
        $service->update($course, $pdf, [
            'source_type' => 'external', 'external_url' => 'https://example.org/archive.zip',
        ], 1, null);
        self::assertTrue($pdf->fresh()->isExternal());
        self::assertSame('', $pdf->fresh()->file_path);
        self::assertTrue(DB::table('account_file_deletions')->where('path_hash', hash('sha256', 'courses/7/old.pdf'))->exists());
        Storage::disk('course-pdfs-shared')->assertMissing('courses/7/old.pdf');
        $deletions = DB::table('account_file_deletions')->count();
        $switch = CoursePdfRequest::create('/admin/course-pdf/'.$pdf->id, 'PATCH', [
            'source_type' => 'upload', 'authoring_version' => 2,
        ]);
        try {
            $this->prepareFormRequest($switch, $course->fresh(), $pdf->fresh());
            self::fail('An external link cannot become an upload without replacement bytes.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            self::assertArrayHasKey('pdf_file', $exception->errors());
        }
        self::assertSame(2, (int) $course->fresh()->authoring_version);
        self::assertSame($deletions, DB::table('account_file_deletions')->count());
        $service->update($course->fresh(), $pdf->fresh(), ['source_type' => 'upload'], 2,
            UploadedFile::fake()->createWithContent('replacement.pdf', "%PDF-1.4\n%%EOF"));
        $restored = $pdf->fresh();
        self::assertFalse($restored->isExternal());
        self::assertNull($restored->external_url);
        Storage::disk('course-pdfs-shared')->assertExists($restored->file_path);
    }

    public function test_external_delete_and_toggle_preserve_version_contract_without_storage_cleanup(): void
    {
        $course = Course::findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();
        $pdf = CoursePdf::create([
            'course_id' => 7, 'title' => 'ملف خارجي', 'file_path' => '',
            'source_type' => 'external', 'external_url' => 'https://example.org/large.zip',
        ]);
        $service = app(\App\Services\AdminCoursePdfApplicationService::class);
        self::assertFalse($service->toggle($course, $pdf, 1)['pdf']['is_active']);
        try {
            $service->destroy($course->fresh(), $pdf->fresh(), 1);
            self::fail('Stale version must not delete the attachment.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            self::assertSame(409, $exception->status);
        }
        self::assertTrue($service->destroy($course->fresh(), $pdf->fresh(), 2)['pdf']['deleted']);
        self::assertSame(0, DB::table('account_file_deletions')->count());
    }

    public function test_invalid_external_and_mixed_source_requests_are_rejected(): void
    {
        $course = Course::findOrFail(7);
        foreach (['http://example.org/file', 'https://user:password@example.org/file', '', str_repeat('a', 2001), ['https://example.org/file']] as $url) {
            $request = CoursePdfRequest::create('/admin/course-pdf', 'POST', [
                'title' => 'ملف', 'source_type' => 'external', 'external_url' => $url,
                'authoring_version' => 1, 'authoring_request_id' => (string) Str::uuid(),
            ]);
            try {
                $this->prepareFormRequest($request, $course);
                self::fail('Invalid external URL must be rejected.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                self::assertArrayHasKey('external_url', $exception->errors());
            }
        }
        $request = CoursePdfRequest::create('/admin/course-pdf', 'POST', [
            'title' => 'ملف', 'source_type' => 'external', 'external_url' => 'https://example.org/file',
            'authoring_version' => 1, 'authoring_request_id' => (string) Str::uuid(),
        ]);
        $request->files->set('pdf_file', UploadedFile::fake()->createWithContent('file.pdf', '%PDF-1.4'));
        try {
            $this->prepareFormRequest($request, $course);
            self::fail('Mixed file and external source must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            self::assertArrayHasKey('pdf_file', $exception->errors());
        }
        self::assertSame(0, CoursePdf::count());
    }

    public function test_uploaded_text_attachment_preserves_validated_type_filename_and_download_bytes(): void
    {
        $course = Course::findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();
        $request = CoursePdfRequest::create('/admin/course-pdf', 'POST', [
            'title' => 'ملاحظات', 'platform' => 'computer', 'authoring_version' => 1,
            'authoring_request_id' => (string) Str::uuid(),
        ]);
        $bytes = "Project setup instructions\nKeep exact source text\n";
        $request->files->set('pdf_file', UploadedFile::fake()->createWithContent('setup-notes.txt', $bytes));
        $request->headers->set('Accept', 'application/json');
        $this->prepareFormRequest($request, $course);
        $created = app(AdminCoursePdfController::class)->store($request, $course)->getData(true);
        $pdf = CoursePdf::findOrFail($created['pdf']['id']);
        self::assertSame('text/plain', $pdf->mime_type);
        self::assertSame('txt', $pdf->file_extension);
        self::assertStringEndsWith('.txt', $pdf->file_path);
        self::assertSame('setup-notes.txt', $pdf->original_filename);
        $preview = app(AdminCoursePdfController::class)->preview($course, $pdf);
        self::assertSame('text/plain', $preview->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment;', $preview->headers->get('Content-Disposition'));
        $course->forceFill(['is_coming_soon' => false])->save();
        DB::table('course_enrollments')->insert([
            'user_id' => 42, 'course_id' => 7, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->authenticate(42);
        $metadata = $this->getJson('/api/v1/courses/7/pdfs/'.$pdf->id)->assertOk()->json('data');
        self::assertSame('txt', $metadata['file_type']);
        self::assertSame('text/plain', $metadata['mime_type']);
        self::assertSame('setup-notes.txt', $metadata['file_name']);
        self::assertSame('computer', $metadata['platform']);
        $download = $this->get($metadata['download_url'])->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        self::assertSame($bytes, file_get_contents($download->baseResponse->getFile()->getPathname()));
    }

    public function test_replacing_back_to_prior_content_cannot_reuse_a_path_being_deleted(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $course = Course::findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();
        $disk = Storage::disk('course-pdfs-shared');
        $pdf = CoursePdf::create([
            'course_id' => 7, 'title' => 'ملاحظات', 'file_path' => 'courses/7/seed.txt',
            'storage_disk' => 'course-pdfs-shared', 'file_size' => 4,
        ]);
        $disk->put($pdf->file_path, 'seed');
        $service = app(\App\Services\AdminCoursePdfApplicationService::class);
        $firstBytes = "First version of the course notes\n";
        $service->update($course, $pdf, [], 1,
            UploadedFile::fake()->createWithContent('notes.txt', $firstBytes));
        $priorPath = $pdf->fresh()->file_path;
        $service->update($course->fresh(), $pdf->fresh(), [], 2,
            UploadedFile::fake()->createWithContent('notes.txt', "Second version of the course notes\n"));
        $cleanup = \App\Models\AccountFileDeletion::query()
            ->where('path_hash', hash('sha256', $priorPath))->firstOrFail();

        $observedDisk = \Mockery::mock($disk);
        $observedDisk->shouldReceive('delete')->once()->with($priorPath)
            ->andReturnUsing(function () use ($disk, $service, $course, $pdf, $firstBytes, $priorPath): bool {
                // The real cleanup job already checked that this old path has
                // no owner. Interleave the next real edit before byte deletion;
                // this is a deterministic storage-boundary race, not a claim
                // about concurrent SQLite/MySQL lock execution.
                $service->update($course->fresh(), $pdf->fresh(), [], 3,
                    UploadedFile::fake()->createWithContent('notes.txt', $firstBytes));

                return $disk->delete($priorPath);
            });
        Storage::set('course-pdfs-shared', $observedDisk);
        try {
            (new \App\Jobs\DeleteAccountFile((int) $cleanup->id))
                ->handle(app(\App\Services\StoredFileReferenceService::class));
        } finally {
            Storage::set('course-pdfs-shared', $disk);
        }

        $current = $pdf->fresh();
        self::assertSame(4, (int) $course->fresh()->authoring_version);
        $disk->assertExists($current->file_path);
        self::assertSame($firstBytes, $disk->get($current->file_path));
        self::assertNotSame($priorPath, $current->file_path);
        $disk->assertMissing($priorPath);
    }

    public function test_retry_after_rollback_cannot_reuse_an_orphan_path_being_deleted(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $course = Course::findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();
        $disk = Storage::disk('course-pdfs-shared');
        $pdf = CoursePdf::create([
            'course_id' => 7, 'title' => 'ملاحظات', 'file_path' => 'courses/7/seed.txt',
            'storage_disk' => 'course-pdfs-shared', 'file_size' => 4,
        ]);
        $disk->put($pdf->file_path, 'seed');
        $service = app(\App\Services\AdminCoursePdfApplicationService::class);
        $bytes = "Replacement notes after a failed save\n";
        $oldPathHash = hash('sha256', $pdf->file_path);
        DB::statement("CREATE TRIGGER reject_old_attachment_cleanup BEFORE INSERT ON account_file_deletions
            WHEN NEW.path_hash = '{$oldPathHash}' BEGIN SELECT RAISE(ABORT, 'cleanup unavailable'); END");
        try {
            $service->update($course, $pdf, [], 1,
                UploadedFile::fake()->createWithContent('notes.txt', $bytes));
            self::fail('The rejected cleanup ledger must roll back the edit.');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('cleanup unavailable', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER reject_old_attachment_cleanup');
        }
        self::assertSame(1, (int) $course->fresh()->authoring_version);
        self::assertSame('courses/7/seed.txt', $pdf->fresh()->file_path);
        $cleanup = \App\Models\AccountFileDeletion::query()->firstOrFail();
        $failedPath = $cleanup->path;
        $disk->assertExists($failedPath);

        $observedDisk = \Mockery::mock($disk);
        $observedDisk->shouldReceive('delete')->once()->with($failedPath)
            ->andReturnUsing(function () use ($disk, $service, $course, $pdf, $bytes, $failedPath): bool {
                $service->update($course->fresh(), $pdf->fresh(), [], 1,
                    UploadedFile::fake()->createWithContent('notes.txt', $bytes));

                return $disk->delete($failedPath);
            });
        Storage::set('course-pdfs-shared', $observedDisk);
        try {
            (new \App\Jobs\DeleteAccountFile((int) $cleanup->id))
                ->handle(app(\App\Services\StoredFileReferenceService::class));
        } finally {
            Storage::set('course-pdfs-shared', $disk);
        }

        self::assertSame(2, (int) $course->fresh()->authoring_version);
        $current = $pdf->fresh();
        $disk->assertExists($current->file_path);
        self::assertSame($bytes, $disk->get($current->file_path));
        self::assertNotSame($failedPath, $current->file_path);
    }

    public function test_database_retry_and_content_deduplication_do_not_repeat_the_physical_upload(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $course = Course::findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();
        $disk = Storage::disk('course-pdfs-shared');
        $observedDisk = \Mockery::mock($disk);
        $observedDisk->shouldReceive('putFileAs')->once()
            ->andReturnUsing(fn (...$arguments) => $disk->putFileAs(...$arguments));
        Storage::set('course-pdfs-shared', $observedDisk);
        $receipts = 0;
        $service = app(\App\Services\AdminCoursePdfApplicationService::class);
        try {
            $created = $service->store($course,
                UploadedFile::fake()->createWithContent('notes.txt', "Course notes\n"),
                ['title' => 'ملاحظات'], 1, (string) Str::uuid(),
                function () use (&$receipts): void {
                    $receipts++;
                    if ($receipts === 1) {
                        throw new \PDOException('deadlock detected', 40001);
                    }
                });
            $duplicate = $service->store($course->fresh(),
                UploadedFile::fake()->createWithContent('notes.txt', "Course notes\n"),
                ['title' => 'اسم آخر'], 2, (string) Str::uuid(), static function (): void {});
        } finally {
            Storage::set('course-pdfs-shared', $disk);
        }

        self::assertSame(2, $receipts);
        self::assertSame($created['pdf']['id'], $duplicate['pdf']['id']);
        self::assertSame('هذا الملف مضاف بالفعل', $duplicate['message']);
        self::assertSame(2, (int) $course->fresh()->authoring_version);
        self::assertSame(1, CoursePdf::count());
        self::assertCount(1, $disk->allFiles());
    }

    public function test_failed_byte_write_keeps_current_attachment_and_cleanup_cannot_remove_its_retry(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $course = Course::findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();
        $disk = Storage::disk('course-pdfs-shared');
        $pdf = CoursePdf::create([
            'course_id' => 7, 'title' => 'ملاحظات', 'file_path' => 'courses/7/seed.txt',
            'storage_disk' => 'course-pdfs-shared', 'file_size' => 4,
        ]);
        $disk->put($pdf->file_path, 'seed');
        $service = app(\App\Services\AdminCoursePdfApplicationService::class);
        $observedDisk = \Mockery::mock($disk);
        $observedDisk->shouldReceive('putFileAs')->once()
            ->andReturnUsing(function ($directory, $file, $name) use ($disk): bool {
                $disk->put($directory.'/'.$name, 'partial');
                return false;
            });
        Storage::set('course-pdfs-shared', $observedDisk);
        try {
            $service->update($course, $pdf, [], 1,
                UploadedFile::fake()->createWithContent('notes.txt', "Complete replacement\n"));
            self::fail('A failed byte write must not be acknowledged as saved.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Tracked file storage failed.', $exception->getMessage());
        } finally {
            Storage::set('course-pdfs-shared', $disk);
        }
        self::assertSame(1, (int) $course->fresh()->authoring_version);
        self::assertSame('courses/7/seed.txt', $pdf->fresh()->file_path);
        self::assertSame('seed', $disk->get($pdf->fresh()->file_path));
        $failedCleanup = \App\Models\AccountFileDeletion::query()->firstOrFail();
        $failedPath = $failedCleanup->path;
        $service->update($course->fresh(), $pdf->fresh(), [], 1,
            UploadedFile::fake()->createWithContent('notes.txt', "Complete replacement\n"));
        $failedCleanup->forceFill(['available_at' => now()])->save();
        (new \App\Jobs\DeleteAccountFile((int) $failedCleanup->id))
            ->handle(app(\App\Services\StoredFileReferenceService::class));
        self::assertSame(2, (int) $course->fresh()->authoring_version);
        self::assertSame("Complete replacement\n", $disk->get($pdf->fresh()->file_path));
        self::assertNotSame($failedPath, $pdf->fresh()->file_path);
        $disk->assertMissing($failedPath);
    }

    public function test_a_stale_replacement_cleans_only_its_upload_and_preserves_the_winning_edit(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $course = Course::findOrFail(7);
        $course->forceFill(['is_coming_soon' => true])->save();
        $disk = Storage::disk('course-pdfs-shared');
        $pdf = CoursePdf::create([
            'course_id' => 7, 'title' => 'ملاحظات', 'file_path' => 'courses/7/seed.txt',
            'storage_disk' => 'course-pdfs-shared', 'file_size' => 4,
        ]);
        $disk->put($pdf->file_path, 'seed');
        $service = app(\App\Services\AdminCoursePdfApplicationService::class);
        $observedDisk = \Mockery::mock($disk);
        $observedDisk->shouldReceive('putFileAs')->once()
            ->andReturnUsing(function (...$arguments) use ($disk, $service, $course, $pdf) {
                $path = $disk->putFileAs(...$arguments);
                $service->update($course->fresh(), $pdf->fresh(), ['title' => 'التعديل الأحدث'], 1, null);
                return $path;
            });
        Storage::set('course-pdfs-shared', $observedDisk);
        try {
            $service->update($course, $pdf, [], 1,
                UploadedFile::fake()->createWithContent('notes.txt', "Stale replacement\n"));
            self::fail('A concurrent winning edit must reject the old version.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            self::assertSame(409, $exception->status);
        } finally {
            Storage::set('course-pdfs-shared', $disk);
        }
        self::assertSame(2, (int) $course->fresh()->authoring_version);
        self::assertSame('التعديل الأحدث', $pdf->fresh()->title);
        $cleanup = \App\Models\AccountFileDeletion::query()->firstOrFail();
        $unusedPath = $cleanup->path;
        (new \App\Jobs\DeleteAccountFile((int) $cleanup->id))
            ->handle(app(\App\Services\StoredFileReferenceService::class));
        $disk->assertMissing($unusedPath);
        self::assertSame('seed', $disk->get($pdf->fresh()->file_path));
        self::assertSame(1, CoursePdf::count());
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

    private function prepareFormRequest(FormRequest $request, Course $course, ?CoursePdf $pdf = null): void
    {
        $route = new Route([$request->method()], $request->path(), static fn () => null);
        $route->bind($request);
        $route->setParameter('course', $course);
        if ($pdf !== null) {
            $route->setParameter('pdf', $pdf);
        }
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
