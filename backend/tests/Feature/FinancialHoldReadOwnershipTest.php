<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\FinancialEntitlementHold;
use App\Models\Order;
use App\Models\User;
use App\Services\AiEntitlementBudgetService;
use App\Services\CertificateService;
use App\Services\CourseEntitlementService;
use App\Services\FinancialEntitlementHoldReadService;
use App\Services\FinancialProvenanceService;
use App\Services\PortfolioUploadAccessService;
use App\Services\UserPathProgressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FinancialHoldReadOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertExitCode(0);
        foreach ([FinancialProvenanceService::class, AiEntitlementBudgetService::class] as $service) {
            $this->app->bind($service, static function () use ($service): never {
                throw new \LogicException('A hold reader must not resolve ' . $service);
            });
        }
    }

    public function test_read_only_consumers_no_longer_resolve_financial_writers_or_ai_reservations(): void
    {
        foreach ([CourseEntitlementService::class, CertificateService::class,
            PortfolioUploadAccessService::class, UserPathProgressService::class] as $service) {
            self::assertInstanceOf($service, app($service));
        }
    }

    public static function holdScopes(): array
    {
        return [
            'base purchase course hold' => ['course', 'base', ['course'], 'active', true],
            'upgrade cannot impersonate base' => ['course', 'upgrade', ['course'], 'active', false],
            'current plan hold' => ['plan', 'upgrade', ['course', 'plan'], 'active', true],
            'old plan hold' => ['plan', 'base', ['course', 'plan'], 'active', false],
            'chat hold excludes learning' => ['chat', 'upgrade', ['course'], 'active', false],
            'chat hold blocks variable cost' => ['chat', 'upgrade', ['course', 'chat', 'plan'], 'active', true],
            'resolved hold' => ['course', 'base', ['course'], 'resolved', false],
            'no requested scope' => ['course', 'base', [], 'active', false],
        ];
    }

    #[DataProvider('holdScopes')]
    public function test_hold_reads_preserve_scope_and_purchased_order_identity_without_writes(
        string $scope, string $heldOrder, array $requestedScopes, string $status, bool $expected
    ): void {
        $user = new User();
        $user->forceFill(['name' => 'Hold reader', 'email' => Str::uuid() . '@test.rokn',
            'password' => bcrypt('test'), 'active' => true, 'role' => 'client'])->save();
        $course = Course::factory()->make();
        $course->forceFill(['tenant_id' => 1, 'is_coming_soon' => false])->save();
        $orders = [];
        foreach (['base', 'upgrade'] as $kind) {
            $orders[$kind] = Order::query()->create([
                'user_id' => $user->id, 'course_id' => $course->id,
                'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
                'amount' => 100, 'final_amount' => 100, 'status' => Order::STATUS_APPROVED,
            ]);
        }
        $enrollment = new CourseEnrollment();
        $enrollment->forceFill([
            'user_id' => $user->id, 'course_id' => $course->id,
            'order_id' => $orders['base']->id, 'access_plan_order_id' => $orders['upgrade']->id,
        ]);
        $hold = FinancialEntitlementHold::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'course_id' => $course->id,
            'course_order_id' => $orders[$heldOrder]->id, 'source_order_id' => $orders['base']->id,
            'status' => $status, 'entitlement_scope' => $scope, 'reason' => 'test', 'held_at' => now(),
        ])->fresh();
        $before = $hold->getAttributes();
        DB::connection()->enableQueryLog();

        self::assertSame($expected, app(FinancialEntitlementHoldReadService::class)
            ->enrollmentHasActiveHold($enrollment, $requestedScopes));
        self::assertSame($before, $hold->fresh()->getAttributes());
        foreach (DB::getQueryLog() as $query) {
            self::assertDoesNotMatchRegularExpression('/\b(insert|update|delete|replace)\b/i', $query['query']);
        }
    }
}
