<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CourseChatTurnService;
use Illuminate\Console\Command;

/**
 * Recovers open course-chat turns (landed answers a dead worker never
 * presented, stalled turns past their cutoff). Scheduled as a sub-minute task
 * (Laravel scheduler, "Sub-Minute Scheduled Tasks") so recovery keeps the
 * seconds-scale timing the status poll used to provide, while the poll itself
 * stays a pure read.
 */
final class RecoverCourseChatTurns extends Command
{
    protected $signature = 'ai:recover-chat-turns {--limit=200}';

    protected $description = 'Deliver landed answers and close stalled course-chat turns';

    public function handle(CourseChatTurnService $turns): int
    {
        $closed = $turns->recoverOpenTurns(max(1, min(1000, (int) $this->option('limit'))));
        $this->info("Recovered {$closed} course-chat turn(s).");

        return self::SUCCESS;
    }
}
