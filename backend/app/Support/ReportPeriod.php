<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/** One frozen, half-open UTC window shared by reports and their comparisons. */
final readonly class ReportPeriod
{
    private function __construct(
        public string $key,
        public ?CarbonImmutable $start,
        public ?CarbonImmutable $end,
    ) {
    }

    public static function labels(): array
    {
        return ['today' => 'اليوم', '7d' => 'آخر أسبوع', '30d' => 'آخر شهر',
            '90d' => 'آخر 3 شهور', 'all' => 'كل الأوقات'];
    }

    public static function fromKey(string $key = 'all', ?CarbonImmutable $now = null): self
    {
        if (!array_key_exists($key, self::labels())) {
            throw new InvalidArgumentException('Unsupported report period.');
        }
        if ($key === 'all') {
            return new self($key, null, null);
        }
        $end = ($now ?? BusinessClock::utcNow())->utc()->startOfSecond();
        $start = $key === 'today'
            ? $end->setTimezone(BusinessClock::timezoneName())->startOfDay()->utc()
            : $end->subDays((int) $key);

        return new self($key, $start, $end);
    }

    public function label(): string
    {
        return self::labels()[$this->key];
    }

    public function description(): string
    {
        return $this->start === null ? 'كل البيانات المسجلة دون مقارنة زمنية'
            : BusinessClock::format($this->start).' — '.BusinessClock::format($this->end)
                .' ('.BusinessClock::timezoneName().')';
    }

    public function previous(): ?self
    {
        if ($this->start === null || $this->end === null) {
            return null;
        }
        if ($this->key === 'today') {
            $start = $this->start->setTimezone(BusinessClock::timezoneName())->subDay()->utc();

            // Same local clock time yesterday, including DST changes. Adding
            // today's elapsed seconds can otherwise overlap today's window.
            return new self($this->key, $start,
                $this->end->setTimezone(BusinessClock::timezoneName())->subDay()->utc());
        }

        return new self($this->key,
            $this->start->subSeconds($this->end->getTimestamp() - $this->start->getTimestamp()),
            $this->start);
    }

    public function apply(EloquentBuilder|Builder $query, string $column): EloquentBuilder|Builder
    {
        if ($this->start !== null) {
            $query->where($column, '>=', $this->start)->where($column, '<', $this->end);
        }

        return $query;
    }

    public function contains(DateTimeInterface|string|null $instant): bool
    {
        if ($instant === null) {
            return false;
        }
        $instant = CarbonImmutable::parse($instant)->utc();

        return $this->start === null || ($instant >= $this->start && $instant < $this->end);
    }

    /** Missing amounts and zero baselines never turn into fabricated growth. */
    public static function compare(?float $current, ?float $previous): array
    {
        $status = 'unavailable';
        $percentage = null;
        if ($current !== null && $previous !== null) {
            if ($current === $previous) {
                $status = 'unchanged';
                $percentage = 0.0;
            } elseif ($previous == 0.0) {
                $status = 'new';
            } else {
                $percentage = round(($current - $previous) / abs($previous) * 100, 1);
                $status = $current > $previous ? 'up' : 'down';
            }
        }

        return compact('current', 'previous', 'percentage', 'status');
    }
}
