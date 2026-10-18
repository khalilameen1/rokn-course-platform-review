<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What a direct wallet purchase of a course ended in. The kind decides the
 * HTTP answer; the data is the payload the shipped course-details screen
 * reads for that answer.
 */
final readonly class CoursePurchaseOutcome
{
    /** The course was unlocked now, or an identical purchase was replayed. */
    public const PURCHASED = 'purchased';

    /** The learner already holds the course on another plan. */
    public const ACCESS_CHANGED = 'access_changed';

    /** The wallet cannot fund the selected plan; data carries the shortfall. */
    public const INSUFFICIENT_COINS = 'insufficient_coins';

    /** The selected plan disappeared while the shortfall was being priced. */
    public const PLAN_UNAVAILABLE = 'plan_unavailable';

    /** @param array<string, mixed>|null $data */
    public function __construct(
        public string $kind,
        public ?array $data,
        public bool $alreadyEnrolled = false,
        public ?string $refusal = null
    ) {}
}
