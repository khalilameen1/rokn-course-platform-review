<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The one way to compute EGP amounts.
 *
 * Wraps Brick\Math\BigDecimal (the arbitrary-precision library Laravel already
 * ships) so that every EGP sum, difference, product and comparison is exact
 * and is rounded once, explicitly, HALF_UP to currency precision. Coins are
 * plain integers and never pass through this class; only cash does.
 *
 * Values keep their full precision until `toScale()`, `toDecimalString()` or
 * `toCents()` rounds them, so a provider-reported amount can be compared
 * against a stored one without first rounding it. Legacy decimal columns and
 * JSON payloads receive strings or floats only at the boundary through
 * `toDecimalString()` / `toFloat()`.
 *
 * Reference: brick/math README, "Money and currency" (https://github.com/brick/math).
 */
final class Money
{
    public const SCALE = 2;

    public const ROUNDING = RoundingMode::HalfUp;

    /** Scale kept by a proportional share before the final currency rounding. */
    public const PRORATION_SCALE = 8;

    private function __construct(private readonly BigDecimal $amount) {}

    /**
     * Exact EGP value from a decimal column string, request input, integer
     * or legacy float. `null` is the empty column and reads as zero, matching
     * the `(float) null` it replaces.
     */
    public static function ofEgp(self|BigNumber|string|float|int|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if ($value === null) {
            return self::zero();
        }
        if ($value instanceof BigNumber) {
            return new self($value->toBigDecimal());
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Money cannot be built from a non-finite float.');
            }
            // PHP renders a float with 14 significant digits, the same
            // decimal the legacy round()/number_format() calls acted on.
            $value = (string) $value;
        }
        if (is_string($value)) {
            $value = str_replace([',', ' '], '', trim($value));
            if ($value === '' || ! is_numeric($value)) {
                throw new InvalidArgumentException('Money cannot be built from a non-numeric string.');
            }
        }

        return new self(BigDecimal::of($value));
    }

    /** Null when the value is absent or not numeric instead of throwing. */
    public static function tryOfEgp(mixed $value): ?self
    {
        if ($value === null || $value === '' || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        try {
            return self::ofEgp($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    /** @param iterable<mixed> $values Each value as accepted by ofEgp(). */
    public static function sum(iterable $values): self
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus(self::ofEgp($value)->amount);
        }

        return new self($total);
    }

    public function add(self|string|float|int|null $other): self
    {
        return new self($this->amount->plus(self::ofEgp($other)->amount));
    }

    public function sub(self|string|float|int|null $other): self
    {
        return new self($this->amount->minus(self::ofEgp($other)->amount));
    }

    public function mul(self|string|float|int $factor): self
    {
        return new self($this->amount->multipliedBy(self::ofEgp($factor)->amount));
    }

    /** Division is the one operation that cannot stay exact, so it names its scale. */
    public function div(self|string|float|int $divisor, int $scale = self::SCALE, RoundingMode $rounding = self::ROUNDING): self
    {
        return new self($this->amount->dividedBy(self::ofEgp($divisor)->amount, $scale, $rounding));
    }

    /** This amount's `$part / $whole` share, kept at PRORATION_SCALE so later sums round once. */
    public function prorate(int $part, int $whole): self
    {
        return $this->mul($part)->div($whole, self::PRORATION_SCALE);
    }

    public function toScale(int $scale = self::SCALE, RoundingMode $rounding = self::ROUNDING): self
    {
        return new self($this->amount->toScale($scale, $rounding));
    }

    public function abs(): self
    {
        return new self($this->amount->abs());
    }

    public function negated(): self
    {
        return new self($this->amount->negated());
    }

    public function max(self|string|float|int $other): self
    {
        $other = self::ofEgp($other);

        return $this->amount->isGreaterThanOrEqualTo($other->amount) ? $this : $other;
    }

    public function min(self|string|float|int $other): self
    {
        $other = self::ofEgp($other);

        return $this->amount->isLessThanOrEqualTo($other->amount) ? $this : $other;
    }

    public function compareTo(self|string|float|int|null $other): int
    {
        return $this->amount->compareTo(self::ofEgp($other)->amount);
    }

    /** Numeric equality: 100, "100.0" and "100.00" are the same EGP. */
    public function equals(self|string|float|int|null $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function isPositiveOrZero(): bool
    {
        return $this->amount->isPositiveOrZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isNegativeOrZero(): bool
    {
        return $this->amount->isNegativeOrZero();
    }

    /** True when |this - other| exceeds the tolerance, e.g. a 0.02 EGP settlement slack. */
    public function differsFrom(self|string|float|int|null $other, self|string|float|int $tolerance): bool
    {
        return $this->sub($other)->abs()->compareTo($tolerance) > 0;
    }

    /** The exact value rounded HALF_UP to currency precision as a decimal column string ("100.00"). */
    public function toDecimalString(int $scale = self::SCALE): string
    {
        return (string) $this->amount->toScale($scale, self::ROUNDING);
    }

    /** Currency-precision float for legacy float columns, JSON payloads and `round(..., 2)` call sites. */
    public function toFloat(int $scale = self::SCALE): float
    {
        return $this->amount->toScale($scale, self::ROUNDING)->toFloat();
    }

    /** Whole piastres, HALF_UP, for equality on two checkout quotes. */
    public function toCents(): int
    {
        return $this->amount->toScale(self::SCALE, self::ROUNDING)
            ->multipliedBy(100)
            ->toInt();
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->amount;
    }

    public function __toString(): string
    {
        return $this->toDecimalString();
    }
}
