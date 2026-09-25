<?php

declare(strict_types=1);

namespace App\Support;

/** Immutable, bounded campaign selector; resolving current recipients belongs to delivery. */
final readonly class NotificationAudience
{
    public const ALL = 'all';
    public const ENROLLED = 'enrolled';
    public const NOT_ENROLLED = 'not_enrolled';
    public const MAX_EXPLICIT_USER_IDS = 500;

    /** @var list<int> */
    public array $userIds;

    /** @var list<int> */
    public array $excludeUserIds;

    /** @param array<int,mixed> $userIds @param array<int,mixed> $excludeUserIds */
    public function __construct(
        public string $selector,
        public ?int $courseId = null,
        array $userIds = [],
        array $excludeUserIds = []
    ) {
        $this->userIds = self::normalizeIds($userIds);
        $this->excludeUserIds = self::normalizeIds($excludeUserIds);

        if (!in_array($selector, [self::ALL, self::ENROLLED, self::NOT_ENROLLED], true)) {
            throw new \InvalidArgumentException('Unsupported notification audience selector.');
        }
        if ($courseId !== null && $courseId <= 0) {
            throw new \InvalidArgumentException('Course selector must contain a positive course ID.');
        }
        if ($selector !== self::ALL && $courseId === null) {
            throw new \InvalidArgumentException('Course ID is required for a course notification audience.');
        }
        if (count($this->userIds) > self::MAX_EXPLICIT_USER_IDS) {
            throw new \InvalidArgumentException('Explicit notification audience exceeds the safe broadcast limit.');
        }
        if (count($this->excludeUserIds) > self::MAX_EXPLICIT_USER_IDS) {
            throw new \InvalidArgumentException('Explicit notification exclusions exceed the safe broadcast limit.');
        }
    }

    /** Compare a persisted selection without requiring legacy rows to be stored in sorted order. */
    public function matchesRecipients(array $userIds, array $excludeUserIds): bool
    {
        return $this->userIds === self::normalizeIds($userIds)
            && $this->excludeUserIds === self::normalizeIds($excludeUserIds);
    }

    /** @param array<int,mixed> $ids @return list<int> */
    private static function normalizeIds(array $ids): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)),
            static fn (int $id): bool => $id > 0));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
