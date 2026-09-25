<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\UploadedFile;

/** Validated course intent. Null selections mean omitted; an empty list means clear. */
final readonly class CourseAuthoringEdit
{
    /**
     * @param array<string,mixed> $attributes
     * @param array<string,array<string,mixed>>|null $planOffers
     * @param list<int>|null $classificationIds
     * @param list<int>|null $teacherIds
     */
    private function __construct(
        public array $attributes,
        public ?UploadedFile $image,
        public ?string $requestId,
        public ?int $expectedVersion,
        public bool $publishingRequested,
        public ?bool $catalogVisible,
        public ?bool $mainCourse,
        public ?array $planOffers,
        public ?array $classificationIds,
        public ?array $teacherIds,
        public bool $grantChatAttachments,
        public bool $grantProjectAttachments
    ) {
    }

    /**
     * Call only after the HTTP/input boundary has validated and authorized fields.
     * CourseRequest strips moderator-owned versus administrator-owned plan fields
     * before this point. Application permissions are still checked by the writer.
     *
     * @param array<string,mixed> $fields
     */
    public static function fromValidated(array $fields): self
    {
        $attributes = array_diff_key($fields, array_flip([
            'image', 'classification_ids', 'classification_ids_present',
            'teacher_ids', 'teacher_ids_present', 'access_plans',
            'authoring_version', 'authoring_request_id',
            'grant_chat_attachments_to_current_enrollments',
            'grant_project_followup_attachments_to_current_enrollments',
            'is_main_course', 'is_coming_soon', 'is_catalog_visible',
            'price', 'publishing_intent',
        ]));

        return new self(
            attributes: $attributes,
            image: $fields['image'] ?? null,
            requestId: $fields['authoring_request_id'] ?? null,
            expectedVersion: isset($fields['authoring_version']) ? (int) $fields['authoring_version'] : null,
            publishingRequested: ($fields['publishing_intent'] ?? null) === 'publish',
            catalogVisible: self::submittedBoolean($fields, 'is_catalog_visible'),
            mainCourse: self::submittedBoolean($fields, 'is_main_course'),
            // Explicit null was submitted: let the offer owner reject an empty
            // three-tier set rather than silently treating it as no change.
            planOffers: array_key_exists('access_plans', $fields) ? (array) $fields['access_plans'] : null,
            classificationIds: self::selection($fields, 'classification_ids'),
            teacherIds: self::selection($fields, 'teacher_ids'),
            grantChatAttachments: self::submittedBoolean($fields, 'grant_chat_attachments_to_current_enrollments') ?? false,
            grantProjectAttachments: self::submittedBoolean($fields, 'grant_project_followup_attachments_to_current_enrollments') ?? false
        );
    }

    /** @return list<int>|null */
    private static function selection(array $fields, string $key): ?array
    {
        if (!array_key_exists($key, $fields) && !self::submittedBoolean($fields, $key.'_present')) {
            return null;
        }

        return array_values(array_map('intval', (array) ($fields[$key] ?? [])));
    }

    private static function submittedBoolean(array $fields, string $key): ?bool
    {
        return array_key_exists($key, $fields)
            ? filter_var($fields[$key], FILTER_VALIDATE_BOOLEAN)
            : null;
    }
}
