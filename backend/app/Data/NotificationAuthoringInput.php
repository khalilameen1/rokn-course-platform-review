<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Http\UploadedFile;

/** Validated dashboard input; author identity comes from the authenticated caller. */
final readonly class NotificationAuthoringInput
{
    private function __construct(
        public int $authorId,
        public string $requestId,
        public string $titleAr,
        public string $messageAr,
        public ?string $titleEn,
        public ?string $messageEn,
        public ?int $targetStudentId,
        public ?int $courseId,
        public string $audience,
        public ?string $kind,
        public ?string $actionLabel,
        public ?string $actionLink,
        public ?string $sendAt,
        public ?UploadedFile $image
    ) {
    }

    /**
     * Only call with fields already validated by the input boundary.
     * This factory neither grants authoring permission nor selects recipients.
     *
     * @param array<string,mixed> $fields
     */
    public static function fromValidated(int $authorId, array $fields): self
    {
        return new self(
            authorId: $authorId,
            requestId: (string) $fields['authoring_request_id'],
            titleAr: (string) $fields['title_ar'],
            messageAr: (string) $fields['message_ar'],
            titleEn: $fields['title_en'] ?? null,
            messageEn: $fields['message_en'] ?? null,
            targetStudentId: !empty($fields['user_id']) ? (int) $fields['user_id'] : null,
            courseId: !empty($fields['course_id']) ? (int) $fields['course_id'] : null,
            audience: (string) ($fields['audience'] ?? 'all'),
            kind: $fields['notification_kind'] ?? null,
            actionLabel: $fields['action_label'] ?? null,
            actionLink: $fields['action_link'] ?? null,
            sendAt: $fields['send_at'] ?? null,
            image: $fields['image'] ?? null
        );
    }
}
