<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use Illuminate\Http\UploadedFile;

/** One file contract for project payloads, HTTP admission and submission storage. */
final readonly class ProjectSubmissionFilePolicy
{
    public function __construct(private AiInputAttachmentService $attachments)
    {
    }

    /** @return list<string> */
    public function allowedMimeTypes(?Project $project = null): array
    {
        $global = array_values(array_intersect(
            array_map('strtolower', (array) config('projects.allowed_mime_types', [])),
            $this->attachments->allowedMimeTypes()
        ));

        // A course may narrow supported formats, never enable a disabled one.
        // An explicit empty list means files are disabled, not use the defaults.
        return array_values(array_unique($project?->submission_allowed_mime_types === null
            ? $global
            : array_intersect(
                array_map('strtolower', (array) $project->submission_allowed_mime_types),
                $global
            )));
    }

    public function acceptsType(Project $project, UploadedFile $file): bool
    {
        $mime = $this->attachments->canonicalMime($file);

        return $mime !== null && in_array($mime, $this->allowedMimeTypes($project), true);
    }

    /**
     * The HTTP gate permits container MIME aliases so genuine OOXML reaches
     * canonical content inspection. These aliases are not learner-facing types.
     * @return list<string>
     */
    public function requestMimeTypes(): array
    {
        return array_values(array_unique([
            ...$this->allowedMimeTypes(),
            'application/zip', 'application/x-zip-compressed', 'application/octet-stream',
        ]));
    }

    public static function maximumFiles(Project $project): int
    {
        return max(1, min(5, (int) ($project->submission_max_files ?: 3)));
    }

    public static function maximumFileBytes(): int
    {
        return max(1024, min(
            max(1, (int) config('projects.maximum_file_kilobytes', 25600)) * 1024,
            max(1024, (int) config('openrouter.attachment_provider_max_bytes', 8388608))
        ));
    }

    public static function maximumFileKilobytes(): int
    {
        return max(1, (int) floor(self::maximumFileBytes() / 1024));
    }

    public static function maximumFileMegabytesLabel(): string
    {
        return rtrim(rtrim(number_format(self::maximumFileBytes() / 1048576, 2, '.', ''), '0'), '.');
    }
}
