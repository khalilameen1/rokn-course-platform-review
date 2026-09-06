<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProjectSubmissionService;
use Illuminate\Console\Command;

class FinalizePendingProjectSubmissions extends Command
{
    protected $signature = 'projects:finalize-pending {--limit=100}';
    protected $description = 'Recover pending project reviews and apply recorded decisions';

    public function handle(ProjectSubmissionService $service): int
    {
        $count = $service->finalizeDue(max(1, (int) $this->option('limit')));
        $this->info("Processed {$count} pending project reviews.");

        return self::SUCCESS;
    }
}
