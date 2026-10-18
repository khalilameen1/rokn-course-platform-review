<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class InsufficientWalletBalanceException extends RuntimeException
{
    public int $required;

    public int $balance;

    public function __construct(int $required, int $balance)
    {
        parent::__construct('Insufficient wallet balance');
        $this->required = $required;
        $this->balance = $balance;
    }
}
