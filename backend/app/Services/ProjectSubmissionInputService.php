<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Support\UnicodeText;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Input normalization and admission limits; never stores files or changes learner state. */
final readonly class ProjectSubmissionInputService
{
    public function __construct(
        private AiInputAttachmentService $attachments,
        private ProjectSubmissionFilePolicy $files
    ) {
    }

    /** @return array{0:?string,1:list<UploadedFile>} */
    public function normalize(?string $text, ?array $files): array
    {
        $text = $text === null ? null : UnicodeText::clean($text);
        if ($text === '') {
            $text = null;
        }

        return [
            $text,
            array_values(array_filter(
                $files ?? [],
                static fn ($file): bool => $file instanceof UploadedFile
            )),
        ];
    }

    /** @param list<UploadedFile> $files */
    public function fingerprint(?string $text, array $files): string
    {
        $fileFacts = [];
        foreach ($files as $file) {
            $fileHash = hash_file('sha256', $file->getRealPath());
            if (!is_string($fileHash)) {
                throw new \RuntimeException('Unable to fingerprint the project attachment.');
            }
            $fileFacts[] = [
                'sha256' => $fileHash,
                'size' => (int) $file->getSize(),
                'mime_type' => (string) $this->attachments->canonicalMime($file),
            ];
        }

        return hash('sha256', json_encode([
            'text' => trim((string) $text),
            'files' => $fileFacts,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<UploadedFile> $files */
    public function assertAllowedFileTypes(Project $project, array $files): void
    {
        foreach ($files as $file) {
            if (!$this->files->acceptsType($project, $file)) {
                throw ValidationException::withMessages([
                    'submission_files' => ['أحد الملفات بصيغة غير متاحة لهذا المشروع'],
                ]);
            }
        }
    }

    /** @param list<UploadedFile> $files */
    public function assertReviewCapacity(Project $project, ?string $text, array $files): void
    {
        $maximumFileBytes = ProjectSubmissionFilePolicy::maximumFileBytes();
        $maximumInputCharacters = max(
            1000,
            (int) config('projects.evaluation_max_input_characters', 60000)
        );
        $reviewInputCharacters = mb_strlen(
            UnicodeText::clean((string) $project->requirements_text)
        ) + mb_strlen(UnicodeText::clean((string) $text));
        foreach ($files as $file) {
            if ((int) $file->getSize() > $maximumFileBytes) {
                throw ValidationException::withMessages([
                    'submission_files' => ['الملف أكبر من الحد المتاح لمراجعة المشروع'],
                ]);
            }
            try {
                $reviewInputCharacters += $this->attachments->reviewInputCharacterCount($file);
            } catch (\UnexpectedValueException) {
                throw ValidationException::withMessages([
                    'submission_files' => [
                        'تعذّرت قراءة أحد الملفات اختر ملفًا مقروءًا أو ارفعه كصورة أو PDF',
                    ],
                ]);
            }
            if ($reviewInputCharacters > $maximumInputCharacters) {
                throw ValidationException::withMessages([
                    'submission_files' => [
                        'محتوى الملفات أطول من مساحة المراجعة قلّل النص أو قسّمه بوضوح',
                    ],
                ]);
            }
        }
        if ($reviewInputCharacters > $maximumInputCharacters) {
            throw ValidationException::withMessages([
                ($files === [] ? 'submission_text' : 'submission_files') => [
                    'محتوى المشروع أطول من مساحة المراجعة قلّل النص أو قسّمه بوضوح',
                ],
            ]);
        }
    }
}
