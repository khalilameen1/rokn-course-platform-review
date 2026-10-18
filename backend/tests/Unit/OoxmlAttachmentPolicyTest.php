<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AiInputAttachmentService;
use App\Support\DownloadFilename;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZipArchive;

final class OoxmlAttachmentPolicyTest extends TestCase
{
    public function test_zip_detected_docx_is_canonicalized_only_when_structure_is_genuine(): void
    {
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZIP extension unavailable.');
        }
        $valid = $this->officePackage('docx', true);
        $fake = $this->officePackage('docx', false);
        try {
            $service = (new ReflectionClass(AiInputAttachmentService::class))
                ->newInstanceWithoutConstructor();

            self::assertSame(
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                $service->canonicalMime(new UploadedFile($valid, 'project.docx', 'application/zip', null, true))
            );
            $octetStream = new class($valid, 'project.docx', 'application/octet-stream', null, true) extends UploadedFile
            {
                public function getMimeType(): ?string
                {
                    return 'application/octet-stream';
                }
            };
            self::assertSame(
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                $service->canonicalMime($octetStream)
            );
            self::assertGreaterThanOrEqual(100, $service->estimatedUploadedFileTokens($octetStream));
            self::assertSame('docx', $service->canonicalExtension(
                (string) $service->canonicalMime($octetStream)
            ));
            self::assertSame(
                'project.docx',
                DownloadFilename::safe('project.docx', 'project', 'docx')
            );
            self::assertNull(
                $service->canonicalMime(new UploadedFile($fake, 'project.docx', 'application/zip', null, true))
            );
            self::assertNull(
                $service->canonicalMime(new UploadedFile($valid, 'project.zip', 'application/zip', null, true))
            );
        } finally {
            @unlink($valid);
            @unlink($fake);
        }
    }

    public function test_zip_detected_pptx_is_canonicalized_from_its_own_main_part(): void
    {
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('ZIP extension unavailable.');
        }
        $path = $this->officePackage('pptx', true);
        try {
            $service = (new ReflectionClass(AiInputAttachmentService::class))
                ->newInstanceWithoutConstructor();
            self::assertSame(
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                $service->canonicalMime(new UploadedFile($path, 'slides.pptx', 'application/zip', null, true))
            );
        } finally {
            @unlink($path);
        }
    }

    private function officePackage(string $type, bool $genuine): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rokn-ooxml-');
        self::assertIsString($path);
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
        $main = $type === 'docx' ? 'word/document.xml' : 'ppt/presentation.xml';
        $root = $type === 'docx'
            ? '<w:document><w:p><w:r><w:t>محاولة مشروع مكتوبة بوضوح</w:t></w:r></w:p></w:document>'
            : '<p:presentation/>';
        $zip->addFromString(
            '_rels/.rels',
            "<Relationships><Relationship Type=\"officeDocument\" Target=\"{$main}\"/></Relationships>"
        );
        $marker = $type === 'docx'
            ? 'wordprocessingml.document.main+xml'
            : 'presentationml.presentation.main+xml';
        $zip->addFromString('[Content_Types].xml', $genuine ? "<Types>{$marker}</Types>" : '<Types/>');
        $zip->addFromString($main, $root);
        $zip->close();

        return $path;
    }
}
