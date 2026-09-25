<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\UploadBudget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadBudgetTest extends TestCase
{
    public function test_elapsed_time_is_shared_without_resetting_between_reads(): void
    {
        $elapsed = 100.0;
        $budget = UploadBudget::start(16, static function () use (&$elapsed): float { return $elapsed; });
        self::assertSame(16.0, $budget->remainingSeconds());
        $elapsed += 7.5;
        self::assertSame(8.5, $budget->remainingSeconds());
        self::assertSame(8.5, $budget->remainingSeconds());
        $elapsed += 10;
        self::assertSame(0.0, $budget->remainingSeconds());
    }

    public static function invalidDurations(): array
    {
        return ['zero' => [0.0], 'negative' => [-1.0], 'infinity' => [INF], 'not a number' => [NAN]];
    }

    #[DataProvider('invalidDurations')]
    public function test_invalid_duration_cannot_create_an_unbounded_attempt(float $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UploadBudget::start($seconds);
    }
}
