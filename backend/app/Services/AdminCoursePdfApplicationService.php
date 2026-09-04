<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CoursePdf;
use App\Support\DownloadFilename;
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
        UploadedFile $file,
        array $data,
        int $expectedVersion,
        string $requestId,
        Closure $completeIntent
    ): array {
        $this->assertDraft($course);
        $metadata = $this->filePolicy->pdf($file);
        $existing = $course->pdfs()->where('content_sha256', $metadata['sha256'])->first();
        if ($existing) {
            $existingPayload = DB::transaction(function () use (
                $course,
                $expectedVersion,
                $metadata,
                $completeIntent
            ): ?array {
                $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
                $this->assertDraft($lockedCourse);
                $lockedPdf = CoursePdf::query()
                    ->where('course_id', $lockedCourse->id)
                    ->where('content_sha256', $metadata['sha256'])
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
            'course-pdf|'.$course->id.'|'.$metadata['sha256'].'|'.$requestId
        );
        try {
            $result = DB::transaction(function () use (
                $course,
                $file,
                $data,
                $expectedVersion,
                $metadata,
                $stored,
                $completeIntent
            ): array {
                $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
                $this->assertDraft($lockedCourse);
                $existingPdf = CoursePdf::query()
                    ->where('course_id', $lockedCourse->id)
                    ->where('content_sha256', $metadata['sha256'])
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
                    'title' => $data['title'],
                    'title_en' => $data['title_en'] ?? null,
                    'description' => $data['description'] ?? null,
                    'description_en' => $data['description_en'] ?? null,
                    'file_path' => $stored['path'],
                    'storage_disk' => $stored['disk'],
                    'original_filename' => $this->safeOriginalFilename($file),
                    'file_size' => $file->getSize(),
                    'content_sha256' => $metadata['sha256'],
                    'order' => $data['order'] ?? (($lockedCourse->pdfs()->max('order') ?? 0) + 1),
                    'is_active' => array_key_exists('is_active', $data)
                        ? (bool) $data['is_active']
                        : true,
                ]);
                $version = $this->authoring->advance($lockedCourse);
                $payload = $this->payload(
                    'تم رفع ملف PDF بنجاح',
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
        $attributes = [
            'title' => $data['title'],
            'title_en' => $data['title_en'] ?? null,
            'description' => $data['description'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'order' => $data['order'] ?? $pdf->order,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $pdf->is_active,
        ];

        if ($replacement) {
            $metadata = $this->filePolicy->pdf($replacement);
            $stored = $this->storePdf(
                $replacement,
                $course,
                'course-pdf|'.$course->id.'|'.$metadata['sha256']
            );
            $attributes += [
                'file_path' => $stored['path'],
                'storage_disk' => $stored['disk'],
                'original_filename' => $this->safeOriginalFilename($replacement),
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
                if (!empty($attributes['content_sha256']) && CoursePdf::query()
                    ->where('course_id', $course->id)
                    ->where('content_sha256', $attributes['content_sha256'])
                    ->where('id', '<>', $lockedPdf->id)
                    ->lockForUpdate()
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'pdf_file' => 'هذا الملف مضاف بالفعل',
                    ]);
                }

                $lockedPdf->update($attributes);
                if ($stored) {
                    $this->fileDeletion->deleteOrQueue($oldDisk, $oldPath);
                }
                $version = $this->authoring->advance($lockedCourse);

                return $this->payload(
                    'تم تحديث ملف PDF بنجاح',
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
            $this->fileDeletion->deleteOrQueue(
                (string) $lockedPdf->storage_disk,
                (string) $lockedPdf->file_path
            );
            $version = $this->authoring->advance($lockedCourse);

            return $this->payload(
                'تم حذف ملف PDF بنجاح',
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
                    'order' => 'تغيّرت قائمة المرفقات منذ بدء السحب\nحدّث الصفحة ثم أعد الترتيب',
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
        string $operationIdentity
    ): array {
        $disk = trim((string) config('course_pdfs.disk'));
        if ($disk === '' || in_array($disk, ['local', 'public'], true)) {
            throw new \RuntimeException('Course PDF storage is not configured as a private shared disk.');
        }

        $path = $this->fileDeletion->storeTrackedUpload(
            $file,
            'courses/'.$course->id,
            $disk,
            60,
            $operationIdentity
        );

        return ['disk' => $disk, 'path' => $path];
    }

    private function safeOriginalFilename(UploadedFile $file): string
    {
        return DownloadFilename::safe($file->getClientOriginalName(), 'document', 'pdf');
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
