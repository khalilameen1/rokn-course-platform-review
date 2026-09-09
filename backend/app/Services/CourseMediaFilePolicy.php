<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class CourseMediaFilePolicy
{
    private const MIMES = [
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'zip' => 'application/zip',
    ];

    /** @return array{extension:string,mime:string,sha256:string} */
    public function attachment(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = (array) config('course_attachments.allowed_upload_extensions', []);
        $limit = (int) config('course_attachments.max_upload_kilobytes', 51200) * 1024;
        if (!$file->isValid() || !in_array($extension, $allowed, true)
            || $file->getSize() < 1 || $file->getSize() > $limit) {
            throw ValidationException::withMessages(['pdf_file' => 'اختر ملفًا من الأنواع المتاحة ضمن الحجم المحدد']);
        }
        if ($extension === 'pdf') {
            return $this->pdf($file);
        }

        $mime = self::MIMES[$extension] ?? null;
        $detected = strtolower((string) $file->getMimeType());
        $matches = $mime !== null && $detected === $mime;
        if (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            $matches = $this->isOfficePackage($file, $extension);
        } elseif (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            $matches = $matches || in_array($detected, ['application/x-ole-storage', 'application/cdfv2'], true);
        } elseif ($extension === 'zip') {
            $matches = in_array($detected, [
                'application/zip', 'application/x-zip-compressed',
                self::MIMES['docx'], self::MIMES['xlsx'], self::MIMES['pptx'],
            ], true);
        }
        if (!$matches) {
            throw ValidationException::withMessages(['pdf_file' => 'محتوى الملف لا يطابق نوعه']);
        }

        return [
            'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
            'mime' => $mime,
            'sha256' => $this->hash($file, 'pdf_file'),
        ];
    }

    /** @return array{extension:string,mime:string,sha256:string} */
    public function pdf(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $signature = is_resource($handle) ? (string) fread($handle, 5) : '';
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($signature !== '%PDF-' || strtolower((string) $file->getMimeType()) !== 'application/pdf') {
            throw ValidationException::withMessages(['pdf_file' => 'اختر ملف PDF صالحًا']);
        }

        return [
            'extension' => 'pdf',
            'mime' => 'application/pdf',
            'sha256' => $this->hash($file, 'pdf_file'),
        ];
    }

    private function hash(UploadedFile $file, string $field): string
    {
        $hash = hash_file('sha256', $file->getRealPath());
        if (!is_string($hash) || strlen($hash) !== 64) {
            throw ValidationException::withMessages([$field => 'تعذر التحقق من الملف']);
        }

        return $hash;
    }

    private function isOfficePackage(UploadedFile $file, string $extension): bool
    {
        if (!class_exists(\ZipArchive::class)) {
            throw ValidationException::withMessages(['pdf_file' => "تعذر قراءة ملف Office الآن\nيمكنك إضافته برابط خارجي"]);
        }
        $mainPart = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$extension];
        $zip = new \ZipArchive();
        if ($zip->open($file->getRealPath(), \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            return $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName($mainPart) !== false;
        } finally {
            $zip->close();
        }
    }
}
