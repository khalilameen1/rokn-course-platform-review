<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Validated authoring intent, independent of the mutable HTTP request.
 * Patch keys preserve omission versus explicit null/false. The media claim is
 * not trusted yet: CourseSectionMediaService verifies it for the current actor.
 */
final readonly class CourseSectionEdit
{
    /**
     * @param array{description_ar?:?string,description_en?:?string,duration_minutes?:?int,is_opened?:bool} $lessonChanges
     * @param array{requirements_text_ar?:?string,requirements_text_en?:?string,is_graduation_project?:bool} $projectChanges
     * @param list<string>|null $projectSubmissionTypes Null means not submitted.
     */
    public function __construct(
        public string $type,
        public int $moduleId,
        public ?string $titleAr,
        public int $expectedVersion,
        public ?string $titleEn = null,
        public ?int $order = null,
        public array $lessonChanges = [],
        public array $projectChanges = [],
        public ?array $projectSubmissionTypes = null,
        public ?string $videoClaim = null,
        public ?UploadedFile $thumbnail = null,
        public ?string $requestId = null
    ) {
        if (!in_array($type, ['lesson', 'project'], true)) {
            throw new InvalidArgumentException('Unsupported course section type.');
        }
        if (array_diff(array_keys($lessonChanges), [
            'description_ar', 'description_en', 'duration_minutes', 'is_opened',
        ]) !== [] || array_diff(array_keys($projectChanges), [
            'requirements_text_ar', 'requirements_text_en', 'is_graduation_project',
        ]) !== []) {
            throw new InvalidArgumentException('Unsupported course section content field.');
        }
    }
}
