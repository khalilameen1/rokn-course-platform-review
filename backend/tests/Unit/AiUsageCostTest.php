<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AiUsageCost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiUsageCostTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_the_reservation_and_settlement_ledger_share_the_same_precision(mixed $amount, int $micros, string $formatted): void
    {
        self::assertSame($micros, AiUsageCost::micros($amount));
        self::assertSame($formatted, AiUsageCost::format($micros));
        self::assertSame($micros, AiUsageCost::micros($formatted));
    }

    public static function amounts(): array
    {
        return [[null, 0, '0.000000'], [-1, 0, '0.000000'], [0, 0, '0.000000'],
            ['0.000001', 1, '0.000001'], ['0.012500', 12500, '0.012500'],
            ['1.2345678', 1234568, '1.234568']];
    }
}
