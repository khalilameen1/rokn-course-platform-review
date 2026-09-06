<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiInputAttachment;
use App\Models\Course;
use App\Models\User;
use App\Models\CourseChatTurn;
use App\Models\ProjectFeedbackMessage;
use App\Support\DownloadFilename;
use App\Support\UnicodeText;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;
use ZipArchive;

final class AiInputAttachmentService
{
    public function __construct(private readonly StoredFileDeletionService $storedFiles)
    {
    }

    /** @return list<string> */
    public function allowedMimeTypes(): array
    {
        return [
            'image/jpeg', 'image/png', 'image/webp',
            'application/pdf', 'text/plain',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
    }

    /** Fileinfo reports valid OOXML packages as application/zip on some hosts. */
    public function canonicalMime(UploadedFile $file): ?string
    {
        $detected = strtolower(trim((string) $file->getMimeType()));
        $extension = strtolower(trim((string) $file->getClientOriginalExtension()));
        $officeMime = match ($extension) {
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => null,
        };

        if ($officeMime !== null) {
            if (!in_array($detected, [
                $officeMime,
                'application/zip',
                'application/x-zip-compressed',
                'application/octet-stream',
            ], true)) {
                return null;
            }

            return $this->isGenuineOoxml($file, $extension) ? $officeMime : null;
        }

        return in_array($detected, $this->allowedMimeTypes(), true)
            ? $detected
            : null;
    }

    public function canonicalExtension(string $mime): string
    {
        return match (strtolower(trim($mime))) {
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => '',
        };
    }

    public function store(
        User $user,
        Course $course,
        UploadedFile $file,
        string $purpose,
        string $clientUploadId
    ): AiInputAttachment {
        $mime = $this->canonicalMime($file);
        if ($mime === null) {
            throw new UnexpectedValueException('Unsupported AI attachment type.');
        }
        $sha = hash_file('sha256', $file->getRealPath());
        if (!is_string($sha)) {
            throw new \RuntimeException('Unable to fingerprint AI attachment.');
        }
        $size = (int) $file->getSize();
        $safeName = DownloadFilename::safe(
            $file->getClientOriginalName(),
            'rokn-input',
            $this->canonicalExtension($mime)
        );
        $existing = AiInputAttachment::query()
            ->where('user_id', $user->id)
            ->where('client_upload_id', $clientUploadId)
            ->first();
        if ($existing) {
            $this->assertReplay($existing, $course, $purpose, $sha, $size);
            return $existing;
        }
        $disk = (string) config('projects.submission_disk', 'local');
        $directory = "ai_inputs/{$user->id}/{$course->id}";
        $operationIdentity = implode('|', [
            'ai-input', $user->id, $course->id, $purpose, strtolower($clientUploadId), $sha,
        ]);
        $path = $this->storedFiles->trackedUploadDestination(
            $file,
            $directory,
            $disk,
            $operationIdentity
        );
        $reservation = DB::transaction(function () use (
            $user, $course, $clientUploadId, $purpose, $disk, $path, $safeName, $mime, $sha, $size
        ): AiInputAttachment {
            User::query()->whereKey($user->id)->where('active', true)
                ->lockForUpdate()->firstOrFail();
            $existing = AiInputAttachment::query()
                ->where('user_id', $user->id)
                ->where('client_upload_id', $clientUploadId)
                ->lockForUpdate()->first();
            if ($existing) {
                $this->assertReplay($existing, $course, $purpose, $sha, $size);
                return $existing;
            }
            $staged = AiInputAttachment::query()
                ->where('user_id', $user->id)
                ->whereNull('owner_id')
                ->whereIn('status', [AiInputAttachment::ALLOCATING, AiInputAttachment::READY]);
            $maxFiles = max(1, (int) config('openrouter.attachment_staging_max_files_per_user', 12));
            $maxBytes = max(1, (int) config('openrouter.attachment_staging_max_bytes_per_user', 67108864));
            if ((clone $staged)->count() >= $maxFiles
                || (int) (clone $staged)->sum('size_bytes') + $size > $maxBytes) {
                throw new UnexpectedValueException('AI attachment staging limit reached.');
            }
            return AiInputAttachment::query()->create([
                'public_id' => (string) Str::uuid(), 'user_id' => $user->id,
                'course_id' => $course->id, 'client_upload_id' => $clientUploadId,
                'purpose' => $purpose, 'storage_disk' => $disk, 'storage_path' => $path,
                'original_file_name' => $safeName,
                'mime_type' => $mime, 'size_bytes' => $size,
                'sha256' => $sha, 'status' => AiInputAttachment::ALLOCATING,
            ]);
        }, 3);

        // A completed idempotent replay does not rewrite provider storage.
        if ($reservation->status === AiInputAttachment::READY) {
            return $reservation;
        }

        try {
            // The quota reservation is cheap and precedes the cleanup ledger,
            // so rejected uploads cannot flood either storage or the ledger.
            // The ledger still commits before the first byte is written.
            $this->storedFiles->trackPotentialOrphan($disk, $path, 60);
            $this->storedFiles->writeTrackedUpload($file, $path, $disk);
            $updated = AiInputAttachment::query()
                ->whereKey($reservation->id)
                ->where('status', AiInputAttachment::ALLOCATING)
                ->update(['status' => AiInputAttachment::READY, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new \RuntimeException('AI attachment reservation was lost.');
            }
            return $reservation->fresh();
        } catch (Throwable $exception) {
            AiInputAttachment::query()
                ->whereKey($reservation->id)
                ->where('status', AiInputAttachment::ALLOCATING)
                ->delete();
            $this->storedFiles->deleteOrQueue($disk, $path);
            throw $exception;
        }
    }

    private function isGenuineOoxml(UploadedFile $file, string $extension): bool
    {
        if (!class_exists(ZipArchive::class)) return false;
        $path = $file->getRealPath();
        if (!is_string($path) || $path === '' || !is_readable($path)) return false;
        $signature = file_get_contents($path, false, null, 0, 4);
        if (!is_string($signature) || !in_array($signature, ["PK\x03\x04", "PK\x05\x06"], true)) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) return false;
        try {
            if ($zip->numFiles < 3 || $zip->numFiles > 10000) return false;
            $main = $extension === 'docx' ? 'word/document.xml' : 'ppt/presentation.xml';
            if ($zip->locateName('[Content_Types].xml') === false
                || $zip->locateName('_rels/.rels') === false
                || $zip->locateName($main) === false) {
                return false;
            }
            $expandedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if ($name === '' || str_contains($name, '\\')
                    || str_starts_with($name, '/')
                    || preg_match('#(?:^|/)\.\.(?:/|$)#', $name) === 1) {
                    return false;
                }
                $stat = $zip->statIndex($index);
                $expandedBytes += max(0, (int) ($stat['size'] ?? 0));
                if ($expandedBytes > 104857600) return false;
            }
            $typesStat = $zip->statName('[Content_Types].xml');
            if (!is_array($typesStat) || (int) ($typesStat['size'] ?? 0) > 1048576) return false;
            $types = $zip->getFromName('[Content_Types].xml');
            $marker = $extension === 'docx'
                ? 'wordprocessingml.document.main+xml'
                : 'presentationml.presentation.main+xml';
            $relationships = $zip->getFromName('_rels/.rels');
            $mainXml = $zip->getFromName($main);
            $mainRoot = $extension === 'docx' ? '<w:document' : '<p:presentation';

            return is_string($types)
                && str_contains($types, $marker)
                && is_string($relationships)
                && str_contains($relationships, 'officeDocument')
                && str_contains($relationships, $main)
                && is_string($mainXml)
                && str_contains($mainXml, $mainRoot);
        } finally {
            $zip->close();
        }
    }

    /** Register a project file already stored once by ProjectSubmissionService. */
    public function registerStored(
        User $user,
        Course $course,
        string $path,
        string $disk,
        string $name,
        string $mime,
        int $size,
        string $sha,
        string $clientUploadId,
        int $submissionId
    ): AiInputAttachment {
        return AiInputAttachment::query()->createOrFirst(
            ['user_id' => $user->id, 'client_upload_id' => $clientUploadId],
            [
                'public_id' => (string) Str::uuid(),
                'course_id' => $course->id,
                'purpose' => AiInputAttachment::PURPOSE_PROJECT_SUBMISSION,
                'owner_type' => AiInputAttachment::OWNER_PROJECT_SUBMISSION,
                'owner_id' => $submissionId,
                'storage_disk' => $disk,
                'storage_path' => $path,
                'original_file_name' => $name,
                'mime_type' => strtolower($mime),
                'size_bytes' => $size,
                'sha256' => $sha,
                'status' => AiInputAttachment::READY,
            ]
        );
    }

    /** @param list<string> $publicIds */
    public function claim(
        User $user,
        Course $course,
        array $publicIds,
        string $purpose,
        string $ownerType,
        int $ownerId
    ): Collection {
        $publicIds = array_values(array_unique(array_filter($publicIds)));
        if ($publicIds === []) return collect();

        return DB::transaction(function () use (
            $user, $course, $publicIds, $purpose, $ownerType, $ownerId
        ): Collection {
            if (!User::query()->whereKey($user->id)->where('active', true)
                ->lockForUpdate()->exists()) {
                throw new UnexpectedValueException('AI attachment owner is unavailable.');
            }
            $attachments = AiInputAttachment::query()
                ->whereIn('public_id', $publicIds)
                ->lockForUpdate()
                ->get();
            if ($attachments->count() !== count($publicIds)) {
                throw new UnexpectedValueException('AI attachment identity mismatch.');
            }
            $claimed = collect();
            foreach ($attachments as $attachment) {
                if (
                    (int) $attachment->user_id !== (int) $user->id
                    || (int) $attachment->course_id !== (int) $course->id
                    || $attachment->purpose !== $purpose
                    || $attachment->status !== AiInputAttachment::READY
                ) {
                    throw new UnexpectedValueException('AI attachment ownership conflict.');
                }
                if ($attachment->owner_id && (
                    $attachment->owner_type !== $ownerType
                    || (int) $attachment->owner_id !== $ownerId
                )) {
                    if (!$this->ownerIsTerminal($attachment)) {
                        throw new UnexpectedValueException('AI attachment ownership conflict.');
                    }
                    $attachment = AiInputAttachment::query()->create([
                        ...$attachment->only([
                            'user_id', 'course_id', 'purpose', 'storage_disk', 'storage_path',
                            'original_file_name', 'mime_type', 'size_bytes', 'sha256', 'status',
                            'provider_annotations', 'processed_at',
                        ]),
                        'public_id' => (string) Str::uuid(),
                        'client_upload_id' => (string) Str::uuid(),
                        'owner_type' => $ownerType,
                        'owner_id' => $ownerId,
                    ]);
                    $claimed->push($attachment);
                    continue;
                }
                $attachment->forceFill([
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                ])->save();
                $claimed->push($attachment);
            }
            return $claimed;
        }, 3);
    }

    private function ownerIsTerminal(AiInputAttachment $attachment): bool
    {
        if ($attachment->owner_type === AiInputAttachment::OWNER_COURSE_CHAT_TURN) {
            return CourseChatTurn::query()->whereKey($attachment->owner_id)
                ->whereIn('status', [CourseChatTurn::FAILED, CourseChatTurn::CANCELLED])
                ->exists();
        }
        if ($attachment->owner_type === AiInputAttachment::OWNER_PROJECT_FEEDBACK_MESSAGE) {
            return ProjectFeedbackMessage::query()->whereKey($attachment->owner_id)
                ->whereIn('status', [ProjectFeedbackMessage::FAILED, ProjectFeedbackMessage::CANCELLED])
                ->exists();
        }
        return false;
    }

    public function forOwner(string $ownerType, int $ownerId): Collection
    {
        return AiInputAttachment::query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('status', AiInputAttachment::READY)
            ->orderBy('id')
            ->get();
    }

    /** @return list<array<string,mixed>> */
    public function providerParts(Collection $attachments, bool $preserveFullText = false): array
    {
        $parts = [];
        $maxBytes = max(1024, (int) config('openrouter.attachment_provider_max_bytes', 8388608));
        foreach ($attachments as $attachment) {
            $disk = Storage::disk((string) $attachment->storage_disk);
            if ((int) $attachment->size_bytes > $maxBytes || !$disk->exists($attachment->storage_path)) {
                throw new UnexpectedValueException('AI attachment is unavailable or exceeds the provider limit.');
            }
            $bytes = $disk->get($attachment->storage_path);
            if (!is_string($bytes) || $bytes === '') {
                throw new UnexpectedValueException('AI attachment is empty or unreadable.');
            }
            $mime = strtolower((string) $attachment->mime_type);
            if (str_starts_with($mime, 'image/')) {
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:{$mime};base64," . base64_encode($bytes)],
                ];
                continue;
            }
            if ($mime === 'text/plain') {
                $plain = $this->safeExtractedText($bytes, $preserveFullText);
                $parts[] = [
                    'type' => 'text',
                    'text' => "FILE {$attachment->original_file_name}\n"
                        . $plain,
                ];
                continue;
            }
            if ($mime === 'application/pdf') {
                $parts[] = [
                    'type' => 'file',
                    'file' => [
                        'filename' => (string) $attachment->original_file_name,
                        'file_data' => "data:{$mime};base64," . base64_encode($bytes),
                    ],
                ];
                continue;
            }
            $text = $this->officeTextFromBytes($bytes, $mime, $preserveFullText);
            $text = $this->safeExtractedText($text, $preserveFullText);
            $parts[] = [
                'type' => 'text',
                'text' => "FILE {$attachment->original_file_name}\n" . $text,
            ];
        }
        return $parts;
    }

    /** Validate/count local submission text before admission using the review extraction contract. */
    public function reviewInputCharacterCount(UploadedFile $file): int
    {
        $mime = (string) $this->canonicalMime($file);
        if (str_starts_with($mime, 'image/') || $mime === 'application/pdf') return 0;
        $bytes = file_get_contents($file->getRealPath());
        if (!is_string($bytes)) throw new UnexpectedValueException('Attachment cannot be read.');
        $text = $this->safeExtractedText($mime === 'text/plain'
            ? $bytes : $this->officeTextFromBytes($bytes, $mime, true), true);
        $name = DownloadFilename::safe($file->getClientOriginalName(), 'project-submission', $this->canonicalExtension($mime));
        return mb_strlen("FILE {$name}\n".$text);
    }

    public function markProcessed(Collection $attachments, array $annotations): void
    {
        foreach ($attachments as $attachment) {
            $matched = array_values(array_filter($annotations, static function ($annotation) use ($attachment): bool {
                if (!is_array($annotation)) return false;
                $hash = strtolower(trim((string) data_get($annotation, 'file.hash', '')));
                $name = trim((string) data_get($annotation, 'file.name', data_get($annotation, 'file.filename', '')));
                return ($hash !== '' && hash_equals(strtolower((string) $attachment->sha256), $hash))
                    || ($name !== '' && $name === (string) $attachment->original_file_name);
            }));
            if ($matched === [] && $attachments->count() === 1) $matched = $annotations;
            $attachment->forceFill([
                'provider_annotations' => $matched === [] ? null : $matched,
                'processed_at' => now(),
                'failure_code' => null,
            ])->save();
        }
    }

    public function providerAnnotations(Collection $attachments): array
    {
        return $attachments->flatMap(static fn (AiInputAttachment $attachment): array =>
            is_array($attachment->provider_annotations) ? $attachment->provider_annotations : []
        )->unique(static fn ($annotation): string => hash('sha256', json_encode($annotation) ?: ''))
            ->values()->all();
    }

    public function estimatedInputTokens(Collection $attachments): int
    {
        return (int) $attachments->sum(function (AiInputAttachment $attachment): int {
            $disk = Storage::disk((string) $attachment->storage_disk);
            if (!$disk->exists((string) $attachment->storage_path)) {
                throw new UnexpectedValueException('AI attachment is unavailable.');
            }
            $bytes = $disk->get((string) $attachment->storage_path);
            if (!is_string($bytes) || $bytes === '') {
                throw new UnexpectedValueException('AI attachment is unreadable.');
            }
            return $this->estimateSemanticTokens(
                strtolower((string) $attachment->mime_type),
                $bytes
            );
        });
    }

    public function estimatedUploadedFileTokens(UploadedFile $file): int
    {
        $bytes = file_get_contents($file->getRealPath());
        if (!is_string($bytes) || $bytes === '') {
            throw new UnexpectedValueException('AI attachment is unreadable.');
        }
        $mime = $this->canonicalMime($file);
        if ($mime === null) {
            throw new UnexpectedValueException('Unsupported AI attachment type.');
        }
        return $this->estimateSemanticTokens($mime, $bytes);
    }

    private function estimateSemanticTokens(string $mime, string $bytes): int
    {
        if (str_starts_with($mime, 'image/')) {
            $dimensions = @getimagesizefromstring($bytes);
            if (!is_array($dimensions)) {
                throw new UnexpectedValueException('AI image dimensions are unreadable.');
            }
            $tiles = max(1, (int) ceil(((int) $dimensions[0]) / 512))
                * max(1, (int) ceil(((int) $dimensions[1]) / 512));
            return 100 + ($tiles * 220);
        }
        if ($mime === 'application/pdf') {
            preg_match_all('/\/Type\s*\/Page\b/', $bytes, $matches);
            // Compressed PDFs can hide page dictionaries. The byte-derived
            // floor prevents a large scan from masquerading as one page.
            $pages = max(count($matches[0]), (int) ceil(strlen($bytes) / 200000), 1);
            return min(60000, 300 + ($pages * 1100));
        }
        if ($mime === 'text/plain') {
            return max(80, (int) ceil(mb_strlen($this->safeExtractedText($bytes)) / 3.2));
        }
        $text = $this->safeExtractedText($this->officeTextFromBytes($bytes, $mime));
        return max(100, (int) ceil(mb_strlen($text) / 3.2));
    }

    private function officeTextFromBytes(string $bytes, string $mime, bool $preserveFullText = false): string
    {
        if (!class_exists(ZipArchive::class) || $bytes === '') return '';
        $temporary = tempnam(sys_get_temp_dir(), 'rokn-ai-');
        if (!is_string($temporary)) return '';
        try {
            file_put_contents($temporary, $bytes);
            $zip = new ZipArchive();
            if ($zip->open($temporary) !== true) return '';
            $files = $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ? ['word/document.xml']
                : array_values(array_filter(
                    iterator_to_array($this->zipNames($zip)),
                    static fn (string $name): bool => preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1
                ));
            $chunks = [];
            $expandedBytes = 0;
            if ($preserveFullText && count($files) > 100) {
                $zip->close();
                throw new UnexpectedValueException('The whole presentation exceeds the review extraction limit.');
            }
            foreach (array_slice($files, 0, 100) as $file) {
                $index = $zip->locateName($file);
                $stat = $index === false ? false : $zip->statIndex($index);
                $entryBytes = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
                if ($entryBytes <= 0 || $entryBytes > 2 * 1024 * 1024
                    || $expandedBytes + $entryBytes > 8 * 1024 * 1024) {
                    if ($preserveFullText) {
                        $zip->close();
                        throw new UnexpectedValueException('The whole document cannot be extracted within the review limit.');
                    }
                    continue;
                }
                $xml = $zip->getFromName($file);
                if (is_string($xml)) {
                    $expandedBytes += strlen($xml);
                    $chunks[] = html_entity_decode(strip_tags(str_replace(['</w:p>', '</a:p>'], "\n", $xml)));
                }
            }
            $zip->close();
            return trim(implode("\n", $chunks));
        } finally {
            @unlink($temporary);
        }
    }

    private function safeExtractedText(string $bytes, bool $preserveFullText = false): string
    {
        if ($bytes === '' || !mb_check_encoding($bytes, 'UTF-8')) {
            throw new UnexpectedValueException('Attachment text encoding is unsupported.');
        }
        $text = UnicodeText::clean((string) preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $bytes
        ));
        $text = trim($preserveFullText ? $text : mb_substr($text, 0, 30000));
        if ($text === '') {
            throw new UnexpectedValueException('Attachment contains no readable text.');
        }
        return $text;
    }

    private function zipNames(ZipArchive $zip): \Generator
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name)) yield $name;
        }
    }

    private function assertReplay(
        AiInputAttachment $attachment,
        Course $course,
        string $purpose,
        string $sha,
        int $size
    ): void {
        if (
            (int) $attachment->course_id !== (int) $course->id
            || $attachment->purpose !== $purpose
            || $attachment->status !== AiInputAttachment::READY
            || !hash_equals((string) $attachment->sha256, $sha)
            || (int) $attachment->size_bytes !== $size
        ) {
            throw new UnexpectedValueException('AI upload id was reused for different content.');
        }
    }
}
