<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_binary_float_sums_settle_exactly_in_egp(): void
    {
        $sum = Money::sum(['0.1', '0.2']);

        self::assertTrue($sum->equals('0.3'));
        self::assertSame('0.30', $sum->toDecimalString());
        self::assertSame(0.3, $sum->toFloat());
        self::assertSame(30, $sum->toCents());

        // Legacy floats arrive at 14 significant digits and round once, HALF_UP.
        self::assertSame('0.30', Money::ofEgp(0.1 + 0.2)->toDecimalString());
        self::assertSame('1.01', Money::ofEgp(1.005)->toDecimalString());
        self::assertSame('2.68', Money::ofEgp('2.675')->toDecimalString());
        self::assertSame('-2.68', Money::ofEgp('-2.675')->toDecimalString());
        self::assertSame('100.00', Money::sum(['33.33333333', '33.33333333', '33.33333334'])->toDecimalString());
    }

    public function test_decimal_columns_and_provider_strings_are_read_exactly(): void
    {
        self::assertTrue(Money::ofEgp('100.50')->equals(100.5));
        self::assertTrue(Money::ofEgp(null)->isZero());
        self::assertTrue(Money::ofEgp('1,250.00')->equals(1250));
        self::assertTrue(Money::ofEgp(' 12.5 ')->equals('12.50'));
        self::assertSame('1250.00', Money::tryOfEgp('1,250')?->toDecimalString());
        self::assertNull(Money::tryOfEgp('abc'));
        self::assertNull(Money::tryOfEgp(''));
        self::assertNull(Money::tryOfEgp(null));
        self::assertNull(Money::tryOfEgp(true));
        self::assertNull(Money::tryOfEgp(['1']));

        $this->expectException(InvalidArgumentException::class);
        Money::ofEgp('not money');
    }

    public function test_tolerance_comparison_accepts_the_slack_and_rejects_one_piastre_more(): void
    {
        $captured = Money::ofEgp('100.00');

        self::assertFalse($captured->differsFrom('100.02', '0.02'));
        self::assertFalse($captured->differsFrom('99.98', '0.02'));
        self::assertTrue($captured->differsFrom('100.03', '0.02'));
        self::assertTrue($captured->differsFrom('99.97', '0.02'));
        // Sub-piastre provider noise stays inside the Kashier amount tolerance.
        self::assertFalse($captured->differsFrom('100.005', '0.009'));
        self::assertTrue($captured->differsFrom('100.01', '0.009'));
    }

    public function test_arithmetic_keeps_precision_until_rounded_once(): void
    {
        $price = Money::ofEgp('149.99');

        self::assertSame('134.99', $price->mul(90)->div(100)->toDecimalString());
        self::assertSame('49.99666667', (string) $price->prorate(1, 3)->toBigDecimal());
        self::assertSame('50.00', Money::ofEgp(100)->prorate(1, 2)->toDecimalString());
        self::assertSame('33.33', Money::ofEgp(100)->prorate(1, 3)->toDecimalString());
        self::assertSame('100.00', Money::sum([
            Money::ofEgp(100)->prorate(1, 3),
            Money::ofEgp(100)->prorate(1, 3),
            Money::ofEgp(100)->prorate(1, 3),
        ])->toDecimalString());
        self::assertSame(3, Money::ofEgp('25')->mul(500)->div('4200', 0, RoundingMode::Up)->toBigDecimal()->toInt());
        self::assertSame('0.01', Money::ofEgp('0.004')->max('0.01')->toDecimalString());
        self::assertSame('0.00', Money::ofEgp('-5')->max(0)->toDecimalString());
        self::assertSame(1, Money::ofEgp('10')->compareTo('9.999'));
        self::assertTrue(Money::ofEgp('0.00')->isZero());
        self::assertTrue(Money::ofEgp('0.01')->isPositive());
        self::assertTrue(Money::ofEgp('-0.01')->isNegativeOrZero());
        self::assertSame('12.3457', Money::ofEgp('12.34565')->toDecimalString(4));
        self::assertSame(12.3457, Money::ofEgp('12.34565')->toFloat(4));
    }
}
