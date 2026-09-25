<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\VerifyRestoreDrill;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RestoreDrillFinancialConsistencyTest extends TestCase
{
    #[DataProvider('receiptCases')]
    public function test_restore_checks_the_same_receipt_contract_as_order_fulfillment(
        ?int $courseId,
        ?int $packageId,
        ?string $billStatus,
        bool $deletedBill,
        int $expectedIssues,
    ): void {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->string('status');
            $table->string('financial_status');
            $table->softDeletes();
        });
        Schema::create('bills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('payment_status');
            $table->softDeletes();
        });

        try {
            DB::table('orders')->insert([
                'id' => 1,
                'course_id' => $courseId,
                'package_id' => $packageId,
                'status' => 'approved',
                'financial_status' => 'settled',
            ]);
            if ($billStatus !== null) {
                DB::table('bills')->insert([
                    'order_id' => 1,
                    'payment_status' => $billStatus,
                    'deleted_at' => $deletedBill ? now() : null,
                ]);
            }
            $before = [DB::table('orders')->get()->toJson(), DB::table('bills')->get()->toJson()];
            $method = new \ReflectionMethod(VerifyRestoreDrill::class, 'financialIssueCount');

            self::assertSame($expectedIssues, $method->invoke(
                app(VerifyRestoreDrill::class),
                DB::connection(),
                Schema::getFacadeRoot(),
            ));
            self::assertSame($before, [DB::table('orders')->get()->toJson(), DB::table('bills')->get()->toJson()]);
        } finally {
            Schema::dropIfExists('bills');
            Schema::dropIfExists('orders');
        }
    }

    public static function receiptCases(): array
    {
        return [
            'package fulfillment does not require a course bill' => [null, 1, null, false, 0],
            'course requires a bill' => [1, null, null, false, 1],
            'paid course bill is valid' => [1, null, 'paid', false, 0],
            'unpaid course bill fails' => [1, null, 'pending', false, 1],
            'deleted course bill fails' => [1, null, 'paid', true, 1],
            'optional paid package bill is valid' => [null, 1, 'paid', false, 0],
            'optional unpaid package bill still fails' => [null, 1, 'pending', false, 1],
            'deleted optional package bill is not required' => [null, 1, 'paid', true, 0],
            'missing order target still fails' => [null, null, null, false, 1],
        ];
    }
}
