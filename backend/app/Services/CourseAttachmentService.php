<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\User;
use App\Support\DownloadFilename;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

final class CourseAttachmentService
{
    public function __construct(private readonly CourseModuleAccessService $access) {}

    /** @return array<string,mixed> */
    public function pdfPayload(
        User $user,
        Course $course,
        CoursePdf $pdf
    ): array {
        $download = $this->access->temporaryPdfDownloadContract($user, $course, $pdf);

        return [
            'id' => (int) $pdf->id,
            'title' => (string) $pdf->title,
            'title_en' => $pdf->title_en,
            'description' => $pdf->description,
            'description_en' => $pdf->description_en,
            'order' => (int) $pdf->order,
            ...$download,
            'download_only' => true,
            'download_refresh_endpoint' => "/api/v1/courses/{$course->id}/pdfs/{$pdf->id}",
            'file_type' => 'pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => max(0, (int) $pdf->file_size),
            'file_size' => (int) $pdf->file_size > 0 ? $pdf->formatted_file_size : null,
            'download_version' => $this->version(
                $pdf->id,
                $pdf->updated_at,
                $pdf->file_path,
                $pdf->file_size
            ),
        ];
    }

    /** @return array{disk:FilesystemAdapter,disk_name:string,path:string,name:string,mime:string,expires_at:\DateTimeInterface}|null */
    public function pdfFile(CoursePdf $pdf): ?array
    {
        $configuredDisk = trim((string) config('course_pdfs.disk'));
        if ($configuredDisk === '' || trim((string) $pdf->storage_disk) !== $configuredDisk) {
            return null;
        }

        return $this->storedFile(
            $configuredDisk,
            (string) $pdf->file_path,
            (string) ($pdf->original_filename ?: $pdf->title),
            'rokn-file',
            'pdf',
            'application/pdf'
        );
    }

    /** @return array{disk:FilesystemAdapter,disk_name:string,path:string,name:string,mime:string,expires_at:\DateTimeInterface}|null */
    private function storedFile(
        string $diskName,
        string $inputPath,
        string $inputName,
        string $fallbackName,
        string $extension,
        string $mime
    ): ?array {
        $diskName = trim($diskName);
        $path = ltrim(str_replace('\\', '/', trim($inputPath)), '/');
        if (
            $diskName === ''
            || !is_array(config("filesystems.disks.{$diskName}"))
            || $path === ''
            || preg_match('~(^|/)\.\.?(/|$)~', $path) === 1
        ) {
            return null;
        }

        $disk = Storage::disk($diskName);
        if (!$disk->exists($path)) {
            return null;
        }
        if ($mime === '') {
            try {
                $mime = (string) ($disk->mimeType($path) ?: 'application/octet-stream');
            } catch (\Throwable) {
                $mime = 'application/octet-stream';
            }
        }

        return [
            'disk' => $disk,
            'disk_name' => $diskName,
            'path' => $path,
            'name' => DownloadFilename::safe($inputName, $fallbackName, $extension),
            'mime' => $mime,
            'expires_at' => now()->addMinutes($this->access->downloadLifetimeMinutes()),
        ];
    }

    private function version(mixed ...$parts): string
    {
        return sha1(implode('|', array_map(static fn ($part): string => (string) $part, $parts)));
    }
}
