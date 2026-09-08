<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CourseMediaFilePolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CourseAttachmentFilePolicyTest extends TestCase
{
    private array $fixtures = [];

    public function test_small_downloads_keep_their_real_format(): void
    {
        $policy = app(CourseMediaFilePolicy::class);
        $pdf = $this->file('guide.pdf', "%PDF-1.4\n%%EOF");
        $text = $this->file('readme.txt', "ملفات التدريب\nافتح المجلد لبدء المشروع");
        $image = $this->file('reference.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jS1cAAAAASUVORK5CYII='));

        foreach ([[$pdf, 'pdf', 'application/pdf'], [$text, 'txt', 'text/plain'], [$image, 'png', 'image/png']] as [$file, $extension, $mime]) {
            $metadata = $policy->attachment($file);
            self::assertSame($extension, $metadata['extension']);
            self::assertSame($mime, $metadata['mime']);
            self::assertSame(hash_file('sha256', $file->getRealPath()), $metadata['sha256']);
        }
    }

    public function test_a_web_page_renamed_as_a_document_is_not_accepted(): void
    {
        $this->expectException(ValidationException::class);
        app(CourseMediaFilePolicy::class)->attachment($this->file('guide.pdf', '<!DOCTYPE html><html>Sign in</html>'));
    }

    public function test_unsupported_extensions_are_not_added_by_a_fake_content_type(): void
    {
        $this->expectException(ValidationException::class);
        app(CourseMediaFilePolicy::class)->attachment($this->file('installer.exe', 'MZ executable'));
    }

    public function test_internal_limit_is_enforced_by_the_file_policy_not_only_the_form(): void
    {
        config(['course_attachments.max_upload_kilobytes' => 1]);
        $this->expectException(ValidationException::class);
        app(CourseMediaFilePolicy::class)->attachment($this->file('readme.txt', str_repeat('text ', 250)));
    }

    public function test_zip_assets_and_office_documents_keep_distinct_types(): void
    {
        if (!class_exists(\ZipArchive::class)) self::markTestSkipped('ZIP extension unavailable on this host');
        $policy = app(CourseMediaFilePolicy::class);
        foreach ([
            'docx' => ['word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xlsx' => ['xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'pptx' => ['ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        ] as $extension => [$mainPart, $mime]) {
            $package = $this->officeFile($extension, $mainPart);
            $metadata = $policy->attachment($package);
            self::assertSame($extension, $metadata['extension']);
            self::assertSame($mime, $metadata['mime']);
            self::assertSame(hash_file('sha256', $package->getRealPath()), $metadata['sha256']);
            $archive = new UploadedFile($package->getRealPath(), 'assets.zip', null, null, true);
            self::assertSame('application/zip', $policy->attachment($archive)['mime']);
        }

        $this->expectException(ValidationException::class);
        $policy->attachment(new UploadedFile($package->getRealPath(), 'guide.docx', null, null, true));
    }

    private function officeFile(string $extension, string $mainPart): UploadedFile
    {
        // Laravel's fake retains an open tmpfile handle. Windows then prevents
        // ZipArchive from replacing it when close() writes the finished package.
        $path = tempnam(sys_get_temp_dir(), 'rokn-course-office-');
        self::assertIsString($path);
        $this->beforeApplicationDestroyed(static fn () => unlink($path));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE) === true);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString($mainPart, '<document/>');
        self::assertTrue($zip->close());

        return new UploadedFile($path, "guide.{$extension}", null, null, true);
    }

    private function file(string $name, string $bytes): UploadedFile
    {
        $fixture = UploadedFile::fake()->createWithContent($name, $bytes);
        $this->fixtures[] = $fixture;

        // Laravel's fake reports MIME from the filename; exercise real content detection instead.
        return new UploadedFile($fixture->getRealPath(), $name, null, null, true);
    }
}
