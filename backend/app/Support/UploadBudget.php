<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use InvalidArgumentException;

/** One elapsed-time budget shared by every file in a submission attempt. */
final readonly class UploadBudget
{
    /** @param Closure():float $clock */
    private function __construct(private float $deadline, private Closure $clock)
    {
    }

    /** @param (Closure():float)|null $clock Monotonic seconds; injectable for deterministic tests. */
    public static function start(float $seconds, ?Closure $clock = null): self
    {
        if (!is_finite($seconds) || $seconds <= 0) {
            throw new InvalidArgumentException('An upload budget must be positive and finite.');
        }
        $clock ??= static fn (): float => hrtime(true) / 1_000_000_000;

        return new self($clock() + $seconds, $clock);
    }

    public function remainingSeconds(): float
    {
        return max(0.0, $this->deadline - ($this->clock)());
    }
}
