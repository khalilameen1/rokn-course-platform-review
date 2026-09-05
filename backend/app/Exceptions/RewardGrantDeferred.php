<?php

declare(strict_types=1);

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

final class RewardGrantDeferred extends RuntimeException
{
    public function __construct(public readonly CarbonImmutable $retryAt)
    {
        parent::__construct('Reward grant is temporarily blocked by a configured cap.');
    }
}
