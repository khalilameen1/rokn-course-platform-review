<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Order;
use App\Services\CourseFinancialLedgerReportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The admin home and product-operations dashboards used to walk every wallet
 * course order in PHP. The grouped SQL aggregate must report exactly what the
 * former per-order walk reported, including every ledger-integrity edge case.
 */
final class CourseFinancialLedgerSummaryAggregationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        $this->seedLedger();
    }

    protected function tearDown(): void
    {
        foreach (['wallet_transactions', 'orders', 'courses', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_sql_aggregate_matches_former_per_order_walk(): void
    {
        $service = app(CourseFinancialLedgerReportService::class);
        $start = CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-10-08 00:00:00', 'UTC');
        // Same instants expressed in the business timezone must not shift the window.
        $cairoStart = $start->setTimezone('Africa/Cairo');
        $cairoEnd = $end->setTimezone('Africa/Cairo');

        foreach ([
            [null, null, null],
            [null, $start, $end],
            [null, $cairoStart, $cairoEnd],
            [null, $start, null],
            [collect([10, 12]), $start, $end],
            [collect([11]), null, null],
            [collect(), $start, $end],
            [collect([999]), $start, $end],
        ] as [$courseIds, $periodStart, $periodEnd]) {
            self::assertSame(
                $this->legacyCourseSummaries($service, $courseIds, $periodStart, $periodEnd)->all(),
                $service->courseSummaries($courseIds, $periodStart, $periodEnd)->all()
            );
        }

        // Guard against both paths agreeing on an empty or trivial result.
        $summary = $service->courseSummaries(null, $start, $end);
        self::assertSame([12, 10, 11], $summary->keys()->all());
        self::assertSame([
            'total_buy_count' => 5,
            'current_period_buy_count' => 3,
            'total_coins' => 210,
            'paid_coins' => 160,
            'reward_coins' => 50,
            'incomplete_orders' => 1,
        ], $summary->get(10));
        self::assertSame([
            'total_buy_count' => 3,
            'current_period_buy_count' => 0,
            'total_coins' => 0,
            'paid_coins' => 0,
            'reward_coins' => 0,
            'incomplete_orders' => 2,
        ], $summary->get(11));
        self::assertSame(5, $summary->get(12)['incomplete_orders']);
        self::assertSame(1, $summary->get(12)['total_buy_count'] - $summary->get(12)['incomplete_orders']);
    }

    public function test_aggregate_uses_one_query_regardless_of_order_volume(): void
    {
        $service = app(CourseFinancialLedgerReportService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $service->courseSummaries(null, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-08'));

        self::assertCount(1, DB::getQueryLog());
    }

    /**
     * Verbatim copy of the former chunked implementation, kept only as the
     * equivalence oracle for the database aggregate.
     *
     * @param  Collection<int,int>|null  $courseIds
     * @return Collection<int,array<string,int>>
     */
    private function legacyCourseSummaries(
        CourseFinancialLedgerReportService $service,
        ?Collection $courseIds,
        ?CarbonInterface $periodStart,
        ?CarbonInterface $periodEnd
    ): Collection {
        $query = Order::query()
            ->whereNotNull('course_id')
            ->whereIn('payment_method', [
                Order::PAYMENT_METHOD_WALLET,
                Order::PAYMENT_METHOD_WALLET_COINS,
            ])
            ->financiallyEffective()
            ->when($courseIds !== null, fn ($orders) => $orders->whereIn('course_id', $courseIds))
            ->orderBy('id');

        $summary = collect();
        // A small chunk forces the multi-chunk path of the former walk.
        $query->chunkById(3, function ($orders) use ($service, $summary, $periodStart, $periodEnd): void {
            $allocations = $service->allocationsForOrders($orders);
            foreach ($orders as $order) {
                $courseId = (int) $order->course_id;
                $row = $summary->get($courseId, [
                    'total_buy_count' => 0,
                    'current_period_buy_count' => 0,
                    'total_coins' => 0,
                    'paid_coins' => 0,
                    'reward_coins' => 0,
                    'incomplete_orders' => 0,
                ]);
                $allocation = $allocations->get((int) $order->id, [
                    'total_coins' => 0,
                    'paid_coins' => 0,
                    'reward_coins' => 0,
                    'complete' => false,
                ]);
                $row['total_buy_count']++;
                if (
                    $periodStart !== null
                    && $periodEnd !== null
                    && $order->approved_at !== null
                    && $order->approved_at->greaterThanOrEqualTo($periodStart)
                    && $order->approved_at->lessThan($periodEnd)
                ) {
                    $row['current_period_buy_count']++;
                }
                $row['total_coins'] += (int) $allocation['total_coins'];
                $row['paid_coins'] += (int) $allocation['paid_coins'];
                $row['reward_coins'] += (int) $allocation['reward_coins'];
                if (! (bool) $allocation['complete']) {
                    $row['incomplete_orders']++;
                }
                $summary->put($courseId, $row);
            }
        });

        return $summary;
    }

    private function seedLedger(): void
    {
        $now = '2026-10-05 12:00:00';
        DB::table('users')->insert([
            ['id' => 1, 'name_ar' => 'أ', 'email' => 'a@example.test', 'password' => 'x', 'role' => 'client', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name_ar' => 'ب', 'email' => 'b@example.test', 'password' => 'x', 'role' => 'client', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('courses')->insert([
            ['id' => 10, 'name_ar' => 'عشرة', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 11, 'name_ar' => 'أحد عشر', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'name_ar' => 'اثنا عشر', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $tx = fn (int $id, int $user, int $amount, int $paid, int $reward, array $over = []): array => $over + [
            'id' => $id,
            'public_id' => sprintf('00000000-0000-4000-8000-%012d', $id),
            'user_id' => $user,
            'direction' => 'debit',
            'category' => 'course_purchase',
            'bucket' => 'mixed',
            'amount' => $amount,
            'paid_amount' => $paid,
            'reward_amount' => $reward,
            'balance_after' => 0,
            'paid_balance_after' => 0,
            'reward_balance_after' => 0,
            'source_type' => Course::class,
            'source_id' => 10,
            'idempotency_key' => 'k'.$id,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::table('wallet_transactions')->insert([
            $tx(501, 1, 70, 50, 20),
            $tx(502, 1, 40, 40, 0, ['category' => 'course_chat_upgrade']),
            $tx(503, 1, 30, 30, 0),                                   // wrong user for its order
            $tx(504, 1, 25, 25, 0, ['source_id' => 11]),              // linked by two live orders
            $tx(508, 1, 60, 10, 10, ['source_id' => 12]),             // amount != paid + reward
            $tx(509, 1, 60, 60, 0, ['source_id' => 11]),              // wrong course
            $tx(510, 1, 60, 60, 0, ['source_id' => 12, 'direction' => 'credit']),
            $tx(511, 1, 60, 60, 0, ['source_id' => 12, 'source_type' => 'App\\Models\\Package']),
            $tx(512, 1, 50, 40, 10),
            $tx(513, 1, 0, 0, 0, ['source_id' => 12]),                // zero debit
            $tx(514, 1, 50, 30, 20),
            $tx(515, 1, 45, 45, 0, ['source_id' => 12, 'category' => 'course_full_track_upgrade']),
        ]);

        $order = fn (int $id, int $course, ?int $walletTx, string $approvedAt, array $over = []): array => $over + [
            'id' => $id,
            'user_id' => 1,
            'course_id' => $course,
            'wallet_transaction_id' => $walletTx,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => 0,
            'discount_amount' => 0,
            'final_amount' => 0,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'total_coins' => null,
            'paid_coins' => null,
            'reward_coins' => null,
            'approved_at' => $approvedAt,
            'reversed_at' => null,
            'deleted_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::table('orders')->insert([
            // Course 12 is seen first, so it must stay first in the result.
            $order(1, 12, 508, '2026-09-01 10:00:00'),
            $order(2, 12, 509, '2026-09-01 10:00:00'),
            $order(3, 12, 510, '2026-09-01 10:00:00'),
            $order(4, 10, 501, '2026-10-02 10:00:00', ['total_coins' => 70, 'paid_coins' => 50, 'reward_coins' => 20]),
            $order(5, 10, 502, '2026-09-20 10:00:00', ['payment_method' => Order::PAYMENT_METHOD_WALLET]),
            $order(6, 10, 503, '2026-10-03 10:00:00', ['user_id' => 2]),
            $order(7, 11, 504, '2026-09-01 10:00:00'),
            $order(8, 11, 504, '2026-09-01 10:00:00', ['status' => Order::STATUS_PENDING, 'financial_status' => 'pending']),
            $order(9, 11, null, '2026-09-01 10:00:00'),               // no ledger link, no coin claim
            $order(10, 11, null, '2026-09-01 10:00:00', ['total_coins' => 30]),
            $order(11, 12, 511, '2026-09-01 10:00:00'),
            $order(12, 12, null, '2026-10-02 10:00:00', ['payment_method' => Order::PAYMENT_METHOD_KASHIER]),
            $order(13, 10, null, '2026-10-02 10:00:00', ['reversed_at' => '2026-10-04 10:00:00', 'total_coins' => 9]),
            $order(14, 10, 514, '2026-10-02 10:00:00', ['deleted_at' => $now]),
            $order(15, 10, 512, '2026-10-08 00:00:00'),               // exactly at period end: excluded
            $order(16, 10, 514, '2026-10-01 00:00:00'),               // exactly at period start: included
            $order(17, 12, 513, '2026-09-01 10:00:00'),
            $order(18, 12, 515, '2026-09-01 10:00:00'),
            $order(19, 10, null, '2026-10-02 10:00:00', ['status' => Order::STATUS_PENDING]),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable()->index();
            $table->string('payment_method');
            $table->decimal('amount', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->decimal('final_amount', 12, 2);
            $table->string('status');
            $table->string('financial_status');
            $table->unsignedInteger('total_coins')->nullable();
            $table->unsignedInteger('paid_coins')->nullable();
            $table->unsignedInteger('reward_coins')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('direction');
            $table->string('category');
            $table->string('bucket');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('paid_amount')->default(0);
            $table->unsignedInteger('reward_amount')->default(0);
            $table->integer('balance_after');
            $table->integer('paid_balance_after');
            $table->integer('reward_balance_after');
            $table->nullableMorphs('source');
            $table->string('idempotency_key');
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }
}
