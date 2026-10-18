<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class AuditOrderState extends Command
{
    protected $signature = 'orders:audit {--limit=5000 : Maximum rows to inspect}';

    protected $description = 'Report order, bill and entitlement inconsistencies without mutating money or access';

    public function handle(): int
    {
        $limit = max(1, min(50000, (int) $this->option('limit')));
        $issues = [];

        Order::query()
            ->with(['bill:id,order_id,payment_status', 'user:id', 'course:id', 'package:id'])
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Order $order) use (&$issues): void {
                $expectedBill = $order->status === Order::STATUS_APPROVED && ! $order->reversed_at
                    ? 'paid'
                    : ($order->status === Order::STATUS_PENDING ? 'pending' : 'cancelled');
                $shapeValid = ((bool) $order->course_id xor (bool) $order->package_id)
                    && (bool) $order->user;
                if (! $shapeValid) {
                    $issues[] = ['order_id' => $order->id, 'issue' => 'invalid_order_shape'];
                }
                if (
                    ($order->course_id && ! $order->bill)
                    || ($order->bill && $order->bill->payment_status !== $expectedBill)
                ) {
                    $issues[] = [
                        'order_id' => $order->id,
                        'issue' => 'bill_status_mismatch',
                        'expected' => $expectedBill,
                        'actual' => $order->bill?->payment_status,
                    ];
                }
                if (
                    $order->status === Order::STATUS_APPROVED
                    && $order->financial_status !== Order::FINANCIAL_SETTLED
                    && $order->financial_status !== Order::FINANCIAL_REVIEW_REQUIRED
                ) {
                    $issues[] = [
                        'order_id' => $order->id,
                        'issue' => 'financial_status_mismatch',
                        'actual' => $order->financial_status,
                    ];
                }
            });

        if ($issues !== []) {
            Log::critical('Financial order audit found inconsistencies', [
                'count' => count($issues),
                'sample' => array_slice($issues, 0, 50),
            ]);
            $this->error('Found '.count($issues).' financial state inconsistency(s).');

            return self::FAILURE;
        }

        $this->info('Order financial state is consistent.');

        return self::SUCCESS;
    }
}
