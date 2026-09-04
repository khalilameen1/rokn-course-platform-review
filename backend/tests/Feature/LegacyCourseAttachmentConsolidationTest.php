<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Models\Course;
use App\Models\CourseModule;
use App\Services\StoredFileReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class LegacyCourseAttachmentConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_rows_move_to_course_library_and_retired_files_enter_durable_cleanup(): void
    {
        Storage::fake('course-pdfs-shared');
        Storage::disk('course-pdfs-shared')->put('legacy/Course-Workbook', 'pdf');
        Storage::disk('course-pdfs-shared')->put('legacy/course-workbook', 'another pdf');
        $course = Course::factory()->make();
        $course->forceFill(['tenant_id' => 1])->save();
        $module = CourseModule::query()->create([
            'course_id' => $course->id,
            'title_ar' => 'الوحدة الأولى',
            'order' => 1,
        ]);
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('title');
            $table->string('file_path');
            $table->string('storage_disk', 64)->default('public');
            $table->string('file_type')->nullable();
            $table->string('mime_type', 190)->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });
        $now = now();
        DB::table('attachments')->insert([
            [
                'attachable_type' => CourseModule::class,
                'attachable_id' => $module->id,
                'title' => 'ملف العمل',
                'file_path' => 'legacy/Course-Workbook',
                'storage_disk' => 'course-pdfs-shared',
                'file_type' => 'pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 2048,
                'content_sha256' => hash('sha256', 'pdf'),
                'order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'attachable_type' => CourseModule::class,
                'attachable_id' => $module->id,
                'title' => 'سجل قديم لنفس المسار',
                'file_path' => 'legacy/Course-Workbook',
                'storage_disk' => 'course-pdfs-shared',
                'file_type' => 'zip',
                'mime_type' => 'application/zip',
                'file_size' => 2048,
                'content_sha256' => hash('sha256', 'same-path-legacy-row'),
                'order' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'attachable_type' => CourseModule::class,
                'attachable_id' => $module->id,
                'title' => 'ملف آخر بحالة أحرف مختلفة',
                'file_path' => 'legacy/course-workbook',
                'storage_disk' => 'course-pdfs-shared',
                'file_type' => 'pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 3072,
                'content_sha256' => hash('sha256', 'another pdf'),
                'order' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'attachable_type' => CourseModule::class,
                'attachable_id' => $module->id,
                'title' => 'ملف قديم غير مدعوم',
                'file_path' => 'legacy/archive.zip',
                'storage_disk' => 'course-pdfs-shared',
                'file_type' => 'zip',
                'mime_type' => 'application/zip',
                'file_size' => 1024,
                'content_sha256' => hash('sha256', 'zip'),
                'order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'attachable_type' => CourseModule::class,
                'attachable_id' => $module->id,
                'title' => 'نسخة زائدة',
                'file_path' => 'legacy/orphan-copy.pdf',
                'storage_disk' => 'course-pdfs-shared',
                'file_type' => 'pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 2048,
                'content_sha256' => hash('sha256', 'pdf'),
                'order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $migration = require database_path(
            'migrations/2026_09_04_000019_consolidate_legacy_course_attachments.php'
        );
        $migration->up();

        $this->assertDatabaseHas('course_pdfs', [
            'course_id' => $course->id,
            'title' => 'ملف العمل',
            'file_path' => 'legacy/Course-Workbook',
            'storage_disk' => 'course-pdfs-shared',
            'is_active' => true,
        ]);
        self::assertFalse(Schema::hasTable('attachments'));
        $this->assertDatabaseHas('account_file_deletions', [
            'disk' => 'course-pdfs-shared',
            'path_hash' => hash('sha256', 'legacy/archive.zip'),
            'status' => 'pending',
        ]);
        self::assertSame(2, DB::table('course_pdfs')->where('course_id', $course->id)->count());
        $this->assertDatabaseHas('course_pdfs', [
            'course_id' => $course->id,
            'file_path' => 'legacy/course-workbook',
        ]);

        $this->assertDatabaseMissing('account_file_deletions', [
            'disk' => 'course-pdfs-shared',
            'path_hash' => hash('sha256', 'legacy/Course-Workbook'),
        ]);
        $samePathDeletion = AccountFileDeletion::query()->create([
            'disk' => 'course-pdfs-shared',
            'path_hash' => hash('sha256', 'legacy/Course-Workbook'),
            'path' => 'legacy/Course-Workbook',
            'status' => AccountFileDeletion::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => now(),
        ]);
        (new DeleteAccountFile((int) $samePathDeletion->id))->handle(
            app(StoredFileReferenceService::class)
        );

        self::assertTrue(Storage::disk('course-pdfs-shared')->exists('legacy/Course-Workbook'));
        self::assertSame(AccountFileDeletion::STATUS_SKIPPED, $samePathDeletion->fresh()->status);
        $this->assertDatabaseHas('account_file_deletions', [
            'disk' => 'course-pdfs-shared',
            'path_hash' => hash('sha256', 'legacy/orphan-copy.pdf'),
            'status' => 'pending',
        ]);
    }
}
