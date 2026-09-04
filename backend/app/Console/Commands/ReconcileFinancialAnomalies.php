<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CourseEnrollment;
use App\Services\FinancialAnomalyService;
use Illuminate\Console\Command;

final class ReconcileFinancialAnomalies extends Command
{
    protected $signature = 'finance:reconcile-entitlement-anomalies {--limit=1000}';
    protected $description = 'Reconcile paid-floor anomalies outside learner read requests';

    public function handle(FinancialAnomalyService $anomalies): int
    {
        $remaining = max(1, min(10000, (int) $this->option('limit')));
        CourseEnrollment::query()->where('is_active', true)->orderBy('id')
            ->with(['order.courseCode', 'accessPlanOrder', 'accessPlan'])
            ->chunkById(200, function ($rows) use ($anomalies, &$remaining): bool {
                foreach ($rows as $enrollment) {
                    if ($remaining-- <= 0) return false;
                    $anomalies->allowsVariableCostFeatures($enrollment);
                }
                return $remaining > 0;
            });
        return self::SUCCESS;
    }
}
