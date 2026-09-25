<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;

/** Authored input, not a rendered or persisted campaign. Construct with named arguments. */
final readonly class NotificationCampaignIntent
{
    public string $deliveryKey;
    public ?DateTimeImmutable $scheduledAt;
    public ?int $authoredBy;

    public function __construct(
        public string $notificationType,
        string $deliveryKey,
        public NotificationAudience $audience,
        public string $titleAr,
        public string $titleEn,
        public string $messageAr,
        public string $messageEn,
        public ?string $notifiableType = null,
        public ?int $notifiableId = null,
        public ?string $link = null,
        public ?string $imageUrl = null,
        public ?string $actionLabelAr = null,
        public ?string $actionLabelEn = null,
        ?DateTimeInterface $scheduledAt = null,
        ?int $authoredBy = null
    ) {
        $deliveryKey = trim($deliveryKey);
        $this->deliveryKey = $deliveryKey === ''
            ? (string) Str::uuid()
            : (strlen($deliveryKey) > 64 ? hash('sha256', $deliveryKey) : $deliveryKey);
        $this->scheduledAt = $scheduledAt === null
            ? null
            : DateTimeImmutable::createFromInterface($scheduledAt);
        $this->authoredBy = $authoredBy !== null && $authoredBy > 0 ? $authoredBy : null;
    }

    public function hasExplicitImage(): bool
    {
        return trim((string) $this->imageUrl) !== '';
    }
}
