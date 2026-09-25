<?php

declare(strict_types=1);

namespace App\Support;

/** Authored direct-inbox input; delivery policy and rendering belong to the service. */
final readonly class StudentNotificationIntent
{
    /** @param array<string, mixed> $templateVariables */
    public function __construct(
        public string $notificationType,
        public string $titleAr,
        public string $titleEn,
        public string $messageAr,
        public string $messageEn,
        public ?string $link = null,
        public ?string $notifiableType = null,
        public ?int $notifiableId = null,
        public ?string $deliveryKey = null,
        public array $templateVariables = [],
        public ?string $imageUrl = null
    ) {
    }
}
