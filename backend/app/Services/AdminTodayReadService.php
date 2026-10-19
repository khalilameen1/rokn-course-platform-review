<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeedbackReport;
use App\Models\Order;
use App\Models\User;
use App\Support\ReportPeriod;
use Illuminate\Support\Facades\DB;

/** Uses the financial report's captured amounts; never converts coins into cash. */
final readonly class AdminTodayReadService
{
    public function __construct(private PaymentChannelReportService $payments) {}

    /** @return array{gross:float,registrations:int,open_feedback:int,failed_jobs:int} */
    public function read(): array
    {
        $today = ReportPeriod::fromKey('today');
        $cash = $this->payments->summary(scope: $today->apply(Order::query(), 'approved_at'));

        return [
            'gross' => (float) $cash['egp']['confirmed_gross_amount'],
            'registrations' => $today->apply(User::query()->students(), 'created_at')->count(),
            'open_feedback' => FeedbackReport::query()->whereNotIn('status', ['resolved', 'closed', 'dismissed'])->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }
}
