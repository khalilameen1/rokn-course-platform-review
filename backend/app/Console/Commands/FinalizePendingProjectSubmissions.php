<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProjectSubmissionEvaluationScheduler;
use Illuminate\Console\Command;

class FinalizePendingProjectSubmissions extends Command
{
    protected $signature = 'projects:finalize-pending {--limit=100}';
    protected $description = 'Requeue due project evaluations without granting progression';

    public function handle(ProjectSubmissionEvaluationScheduler $service): int
    {
        $count = $service->recoverDue(max(1, (int) $this->option('limit')));
        $this->info("Processed {$count} pending project reviews.");

        return self::SUCCESS;
    }
}
