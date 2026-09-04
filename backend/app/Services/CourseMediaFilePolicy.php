<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class CourseMediaFilePolicy
{
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
}
