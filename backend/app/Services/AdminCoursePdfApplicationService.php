<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CoursePdf;
use App\Support\DownloadFilename;
use App\Support\CourseAttachmentExternalUrl;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminCoursePdfApplicationService
{
    public function __construct(
        private readonly CourseAuthoringConcurrencyService $authoring,
        private readonly CourseMediaFilePolicy $filePolicy,
        private readonly StoredFileDeletionService $fileDeletion,
        private readonly AdminCoursePdfPresenter $presenter
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param Closure(Course, CoursePdf, array<string, mixed>): void $completeIntent
     * @return array<string, mixed>
     */
    public function store(
        Course $course,
        ?UploadedFile $file,
        array $data,
        int $expectedVersion,
        string $requestId,
        Closure $completeIntent
    ): array {
        $this->assertDraft($course);
        if (($data['source_type'] ?? 'upload') === 'external') {
            return $this->storeExternal($course, $data, $expectedVersion, $completeIntent);
        }
        if (!$file) {
            throw ValidationException::withMessages(['pdf_file' => 'اختر ملفًا صالحًا']);
        }
        $metadata = $this->filePolicy->attachment($file);
        $platform = $data['platform'] ?? 'mobile';
        $existing = $course->pdfs()->where('platform', $platform)->where('content_sha256', $metadata['sha256'])->first();
        if ($existing) {
            $existingPayload = DB::transaction(function () use (
                $course,
                $expectedVersion,
                $metadata,
                $platform,
                $completeIntent
            ): ?array {
                $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
                $this->assertDraft($lockedCourse);
                $lockedPdf = CoursePdf::query()
                    ->where('course_id', $lockedCourse->id)
                    ->where('content_sha256', $metadata['sha256'])
                    ->where('platform', $platform)
                    ->lockForUpdate()
                    ->first();
                if (!$lockedPdf) {
                    return null;
                }

                $payload = $this->payload(
                    'هذا الملف مضاف بالفعل',
                    (int) $lockedCourse->authoring_version,
                    ['pdf' => $this->presenter->one($lockedCourse, $lockedPdf)]
                );
                $completeIntent($lockedCourse, $lockedPdf, $payload);

                return $payload;
            }, 3);
            if ($existingPayload !== null) {
                return $existingPayload;
            }
        }

        $stored = $this->storePdf(
            $file,
            $course,
            'course-pdf|'.$course->id.'|'.$metadata['sha256'].'|'.$requestId,
            $metadata['extension']
        );
        try {
            $result = DB::transaction(function () use (
                $course,
                $file,
                $data,
                $expectedVersion,
                $metadata,
                $platform,
                $stored,
                $completeIntent
            ): array {
                $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
                $this->assertDraft($lockedCourse);
                $existingPdf = CoursePdf::query()
                    ->where('course_id', $lockedCourse->id)
                    ->where('content_sha256', $metadata['sha256'])
                    ->where('platform', $platform)
                    ->lockForUpdate()
                    ->first();
                if ($existingPdf) {
                    $payload = $this->payload(
                        'هذا الملف مضاف بالفعل',
                        (int) $lockedCourse->authoring_version,
                        ['pdf' => $this->presenter->one($lockedCourse, $existingPdf)]
                    );
                    $completeIntent($lockedCourse, $existingPdf, $payload);

                    return ['payload' => $payload, 'owns_stored_file' => false];
                }

                $pdf = CoursePdf::query()->create([
                    'course_id' => $lockedCourse->id,
                    'source_type' => 'upload',
                    'platform' => $platform,
                    'title' => $data['title'],
                    'title_en' => $data['title_en'] ?? null,
                    'description' => $data['description'] ?? null,
                    'description_en' => $data['description_en'] ?? null,
                    'file_path' => $stored['path'],
                    'storage_disk' => $stored['disk'],
                    'original_filename' => $this->safeOriginalFilename($file, $metadata['extension']),
                    'mime_type' => $metadata['mime'],
                    'file_extension' => $metadata['extension'],
                    'file_size' => $file->getSize(),
                    'content_sha256' => $metadata['sha256'],
                    'order' => $data['order'] ?? (($lockedCourse->pdfs()->max('order') ?? 0) + 1),
                    'is_active' => array_key_exists('is_active', $data)
                        ? (bool) $data['is_active']
                        : true,
                ]);
                $version = $this->authoring->advance($lockedCourse);
                $payload = $this->payload(
                    'تم رفع المرفق بنجاح',
                    $version,
                    ['pdf' => $this->presenter->one($lockedCourse, $pdf)]
                );
                $completeIntent($lockedCourse, $pdf, $payload);

                return ['payload' => $payload, 'owns_stored_file' => true];
            }, 3);

            if (!$result['owns_stored_file']) {
                $this->fileDeletion->deleteOrQueue($stored['disk'], $stored['path']);
            }

            return $result['payload'];
        } catch (\Throwable $exception) {
            $this->fileDeletion->deleteOrQueue($stored['disk'], $stored['path']);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(
        Course $course,
        CoursePdf $pdf,
        array $data,
        int $expectedVersion,
        ?UploadedFile $replacement
    ): array {
        $this->assertBelongsToCourse($course, $pdf);
        $this->assertDraft($course);
        $stored = null;
        $oldDisk = (string) $pdf->storage_disk;
        $oldPath = (string) $pdf->file_path;
        $attributes = [];
        foreach (['title', 'title_en', 'description', 'description_en', 'platform'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }
        if (isset($data['order'])) {
            $attributes['order'] = $data['order'];
        }
        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        $source = $data['source_type'] ?? $pdf->source_type;
        if ($source === 'external') {
            if ($replacement) {
                throw ValidationException::withMessages(['pdf_file' => 'احذف الملف عند اختيار رابط خارجي']);
            }
            $url = trim((string) ($data['external_url'] ?? ($pdf->isExternal() ? $pdf->external_url : '')));
            $this->assertExternalUrl($url);
            $attributes += [
                'source_type' => 'external',
                'external_url' => $url,
                'file_path' => '',
                'storage_disk' => null,
                'original_filename' => null,
                'file_size' => null,
                'content_sha256' => null,
                'mime_type' => null,
                'file_extension' => null,
            ];
        } elseif ($pdf->isExternal() && !$replacement) {
            throw ValidationException::withMessages(['pdf_file' => 'اختر ملفًا بدل الرابط الخارجي']);
        }

        if ($replacement) {
            $metadata = $this->filePolicy->attachment($replacement);
            $stored = $this->storePdf(
                $replacement,
                $course,
                'course-pdf|'.$course->id.'|'.$metadata['sha256'],
                $metadata['extension']
            );
            $attributes += [
                'source_type' => 'upload',
                'external_url' => null,
                'file_path' => $stored['path'],
                'storage_disk' => $stored['disk'],
                'original_filename' => $this->safeOriginalFilename($replacement, $metadata['extension']),
                'mime_type' => $metadata['mime'],
                'file_extension' => $metadata['extension'],
                'file_size' => $replacement->getSize(),
                'content_sha256' => $metadata['sha256'],
            ];
        }

        try {
            return DB::transaction(function () use (
                $course,
                $pdf,
                $attributes,
                $expectedVersion,
                $stored,
                $oldDisk,
                $oldPath
            ): array {
                $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
                $this->assertDraft($lockedCourse);
                $lockedPdf = CoursePdf::query()
                    ->whereKey($pdf->id)
                    ->where('course_id', $course->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $source = $attributes['source_type'] ?? $lockedPdf->source_type;
                $hash = $source === 'upload'
                    ? ($attributes['content_sha256'] ?? $lockedPdf->content_sha256)
                    : null;
                if ($hash && CoursePdf::query()
                    ->where('course_id', $course->id)
                    ->where('content_sha256', $hash)
                    ->where('platform', $attributes['platform'] ?? $lockedPdf->platform)
                    ->where('id', '<>', $lockedPdf->id)
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'pdf_file' => 'هذا الملف مضاف بالفعل',
                    ]);
                }

                if ($source === 'external' && CoursePdf::query()
                    ->where('course_id', $course->id)
                    ->where('source_type', 'external')
                    ->where('external_url', $attributes['external_url'])
                    ->where('platform', $attributes['platform'] ?? $lockedPdf->platform)
                    ->where('id', '<>', $lockedPdf->id)
                    ->lockForUpdate()->get()->contains(fn (CoursePdf $other): bool => $other->external_url === $attributes['external_url'])) {
                    throw ValidationException::withMessages(['external_url' => 'هذا الرابط مضاف بالفعل لهذا الجهاز']);
                }

                $lockedPdf->update($attributes);
                if (($stored || $lockedPdf->isExternal()) && $oldDisk !== '' && $oldPath !== '') {
                    $this->fileDeletion->deleteOrQueue($oldDisk, $oldPath);
                }
                $version = $this->authoring->advance($lockedCourse);

                return $this->payload(
                    'تم تحديث المرفق بنجاح',
                    $version,
                    ['pdf' => $this->presenter->one($lockedCourse, $lockedPdf)]
                );
            }, 3);
        } catch (\Throwable $exception) {
            if ($stored) {
                $this->fileDeletion->deleteOrQueue($stored['disk'], $stored['path']);
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function destroy(Course $course, CoursePdf $pdf, int $expectedVersion): array
    {
        $this->assertBelongsToCourse($course, $pdf);
        $this->assertDraft($course);

        return DB::transaction(function () use ($course, $pdf, $expectedVersion): array {
            $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
            $this->assertDraft($lockedCourse);
            $lockedPdf = CoursePdf::query()
                ->whereKey($pdf->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->firstOrFail();
            $deletedPdf = $this->presenter->one($lockedCourse, $lockedPdf) + ['deleted' => true];
            $lockedPdf->delete();
            if (!$lockedPdf->isExternal()) {
                $this->fileDeletion->deleteOrQueue(
                    (string) $lockedPdf->storage_disk,
                    (string) $lockedPdf->file_path
                );
            }
            $version = $this->authoring->advance($lockedCourse);

            return $this->payload(
                'تم حذف المرفق بنجاح',
                $version,
                ['pdf' => $deletedPdf]
            );
        }, 3);
    }

    /** @param list<int> $order @return array<string, mixed> */
    public function reorder(Course $course, array $order, int $expectedVersion): array
    {
        $this->assertDraft($course);

        return DB::transaction(function () use ($course, $order, $expectedVersion): array {
            $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
            $this->assertDraft($lockedCourse);
            $lockedIds = CoursePdf::query()
                ->where('course_id', $course->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->sort()
                ->values();
            $submittedIds = collect($order)->sort()->values();
            if ($lockedIds->all() !== $submittedIds->all()) {
                throw ValidationException::withMessages([
                    'order' => "تغيّرت قائمة المرفقات منذ بدء السحب\nحدّث الصفحة ثم أعد الترتيب",
                ])->status(409);
            }

            foreach ($order as $position => $pdfId) {
                CoursePdf::query()
                    ->whereKey($pdfId)
                    ->where('course_id', $course->id)
                    ->update(['order' => $position + 1]);
            }
            $version = $this->authoring->advance($lockedCourse);
            $pdfs = CoursePdf::query()
                ->where('course_id', $lockedCourse->id)
                ->ordered()
                ->get()
                ->map(fn (CoursePdf $item): array => $this->presenter->one($lockedCourse, $item))
                ->values()
                ->all();

            return $this->payload(
                'تم تحديث الترتيب بنجاح',
                $version,
                ['pdfs' => $pdfs]
            );
        }, 3);
    }

    /** @return array<string, mixed> */
    public function toggle(Course $course, CoursePdf $pdf, int $expectedVersion): array
    {
        $this->assertBelongsToCourse($course, $pdf);
        $this->assertDraft($course);

        return DB::transaction(function () use ($course, $pdf, $expectedVersion): array {
            $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
            $this->assertDraft($lockedCourse);
            $lockedPdf = CoursePdf::query()
                ->whereKey($pdf->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPdf->update(['is_active' => !$lockedPdf->is_active]);
            $version = $this->authoring->advance($lockedCourse);

            return $this->payload(
                $lockedPdf->is_active ? 'تم تفعيل الملف' : 'تم إلغاء تفعيل الملف',
                $version,
                ['pdf' => $this->presenter->one($lockedCourse, $lockedPdf)]
            );
        }, 3);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function storeExternal(Course $course, array $data, int $expectedVersion, Closure $completeIntent): array
    {
        $url = trim((string) ($data['external_url'] ?? ''));
        $this->assertExternalUrl($url);

        return DB::transaction(function () use ($course, $data, $url, $expectedVersion, $completeIntent): array {
            $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
            $this->assertDraft($lockedCourse);
            $platform = $data['platform'] ?? 'mobile';
            $pdf = $lockedCourse->pdfs()->where('source_type', 'external')
                ->where('external_url', $url)->where('platform', $platform)->lockForUpdate()->get()
                ->first(fn (CoursePdf $existing): bool => $existing->external_url === $url);
            $version = (int) $lockedCourse->authoring_version;
            $duplicate = $pdf !== null;
            if (!$pdf) {
                $pdf = $lockedCourse->pdfs()->create([
                    'title' => $data['title'],
                    'title_en' => $data['title_en'] ?? null,
                    'description' => $data['description'] ?? null,
                    'description_en' => $data['description_en'] ?? null,
                    'source_type' => 'external',
                    'platform' => $platform,
                    'external_url' => $url,
                    'file_path' => '',
                    'storage_disk' => null,
                    'original_filename' => null,
                    'file_size' => null,
                    'content_sha256' => null,
                    'mime_type' => null,
                    'file_extension' => null,
                    'order' => $data['order'] ?? (($lockedCourse->pdfs()->max('order') ?? 0) + 1),
                    'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                ]);
                $version = $this->authoring->advance($lockedCourse);
            }
            $payload = $this->payload($duplicate ? 'هذا الرابط مضاف بالفعل لهذا الجهاز' : 'تم حفظ المرفق',
                $version, ['pdf' => $this->presenter->one($lockedCourse, $pdf)]);
            $completeIntent($lockedCourse, $pdf, $payload);

            return $payload;
        }, 3);
    }

    private function assertExternalUrl(string $url): void
    {
        if (CourseAttachmentExternalUrl::normalize($url) === null) {
            throw ValidationException::withMessages(['external_url' => 'أدخل رابط HTTPS صالحًا دون بيانات تسجيل دخول']);
        }
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function payload(string $message, int $version, array $extra): array
    {
        return [
            'success' => true,
            'message' => $message,
            'authoring_version' => $version,
            ...$extra,
        ];
    }

    /** @return array{disk: string, path: string} */
    private function storePdf(
        UploadedFile $file,
        Course $course,
        string $operationIdentity,
        string $extension
    ): array {
        $disk = trim((string) config('course_pdfs.disk'));
        if ($disk === '' || in_array($disk, ['local', 'public'], true)) {
            throw new \RuntimeException('Course PDF storage is not configured as a private shared disk.');
        }

        // The extension comes from the content policy, not the browser name
        // or the generic ZIP MIME used by some valid Office documents.
        $path = 'courses/'.$course->id.'/'.hash('sha256', $operationIdentity).'.'.$extension;
        $mightAlreadyBeStored = $this->fileDeletion->trackPotentialOrphan($disk, $path, 60);
        $this->fileDeletion->writeTrackedUpload($file, $path, $disk, $mightAlreadyBeStored);

        return ['disk' => $disk, 'path' => $path];
    }

    private function safeOriginalFilename(UploadedFile $file, string $extension): string
    {
        return DownloadFilename::safe($file->getClientOriginalName(), 'document', $extension);
    }

    private function assertBelongsToCourse(Course $course, CoursePdf $pdf): void
    {
        abort_unless((int) $pdf->course_id === (int) $course->id, 404);
    }

    private function assertDraft(Course $course): void
    {
        if (!$course->is_coming_soon) {
            throw ValidationException::withMessages([
                'course' => 'حوّل الكورس إلى مسودة قبل تغيير مرفقاته',
            ]);
        }
    }
}
