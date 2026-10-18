<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\OrderLifecycleService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminPaymentOperationsContractTest extends TestCase
{
    use RefreshDatabase;

    private const STATES = ['created', 'pending', 'paid', 'failed', 'expired', 'cancelled'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_dashboard_offers_and_accepts_only_the_canonical_operation_states(): void
    {
        $admin = $this->admin();
        [$user, $package] = $this->fixtures();
        $paid = $this->order($user, $package, [
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ]);

        $html = $this->actingAs($admin, 'web')->get(route('admin.orders.index'))->assertOk()->getContent();
        $offered = [];
        foreach ($this->xpath($html)->query('//select[@name="state"]/option[@value!=""]') as $option) {
            $offered[] = $option->getAttribute('value');
        }
        self::assertSame(self::STATES, $offered);

        foreach (self::STATES as $state) {
            $response = $this->actingAs($admin, 'web')
                ->get(route('admin.orders.index', ['state' => $state]))
                ->assertOk();
            $orders = $response->viewData('orders');
            self::assertInstanceOf(LengthAwarePaginator::class, $orders);
            foreach ($orders as $order) {
                // Only the read owner decorates orders with an operation state.
                self::assertSame($state, $order->payment_operation_state);
            }
            self::assertSame($state === 'paid', in_array($paid->id, $orders->getCollection()->modelKeys(), true));
        }

        $this->actingAs($admin, 'web')
            ->from(route('admin.orders.index'))
            ->get(route('admin.orders.index', ['state' => Order::STATUS_APPROVED]))
            ->assertRedirect(route('admin.orders.index'))
            ->assertSessionHasErrors('state');
    }

    public function test_dashboard_pages_orders_in_25s_with_their_relations_loaded(): void
    {
        $admin = $this->admin();
        [$user, $package] = $this->fixtures();
        for ($i = 0; $i < 26; $i++) {
            $this->order($user, $package, []);
        }

        $orders = $this->actingAs($admin, 'web')
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->viewData('orders');

        self::assertSame(25, $orders->perPage());
        self::assertCount(25, $orders->items());
        self::assertSame(26, $orders->total());
        foreach (['user', 'course', 'package'] as $relation) {
            self::assertTrue($orders->first()->relationLoaded($relation), $relation);
        }
    }

    public function test_provider_orders_have_no_manual_status_or_bulk_backdoor(): void
    {
        $orderWrites = [];
        $backdoors = [];
        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $name = (string) $route->getName();
            if (str_starts_with($name, 'admin.bills.') || str_starts_with($name, 'admin.payment-methods.')
                || (str_contains($route->uri(), 'orders')
                    && (str_contains($route->uri(), 'bulk-status') || str_contains($route->uri(), 'update-status')))) {
                $backdoors[] = $route->uri();
            }
            if (str_starts_with($route->uri(), 'dashboard/orders')
                && array_diff($route->methods(), ['GET', 'HEAD']) !== []) {
                $orderWrites[] = $name;
            }
        }
        self::assertSame([], $backdoors);
        sort($orderWrites);
        self::assertSame([
            'admin.orders.compensate-course',
            'admin.orders.record-settlement',
            'admin.orders.resolve-financial-review',
        ], $orderWrites);

        $admin = $this->admin();
        [$user, $package] = $this->fixtures();
        $pending = $this->order($user, $package, []);
        foreach (['put', 'patch'] as $method) {
            $this->actingAs($admin, 'web')
                ->{$method}('/dashboard/orders/'.$pending->id, ['status' => Order::STATUS_APPROVED])
                ->assertStatus(405);
        }
        $this->actingAs($admin, 'web')->get(route('admin.orders.index'))
            ->assertOk()
            ->assertDontSee('updateOrderStatus', false);
        self::assertSame(Order::STATUS_PENDING, $pending->fresh()->status);

        $closed = $this->order($user, $package, [
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
            'status' => Order::STATUS_CANCELLED,
        ]);
        try {
            app(OrderLifecycleService::class)->approve($closed, $admin->id);
            self::fail('An administrator must not approve a closed order.');
        } catch (\DomainException $exception) {
            self::assertSame('Only a pending manual order can be approved by an administrator.', $exception->getMessage());
        }
        self::assertSame(Order::STATUS_CANCELLED, $closed->fresh()->status);
        self::assertSame(0, (int) $user->fresh()->wallet_coins);
    }

    public function test_package_history_is_rendered_from_orders_with_channel_and_net(): void
    {
        $admin = $this->admin();
        [$user, $package] = $this->fixtures();
        $settled = $this->order($user, $package, [
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
            'gateway_gross_amount' => 100,
            'gateway_fee_amount' => 4.5,
            'gateway_net_amount' => 95.5,
        ]);
        $awaiting = $this->order($user, $package, ['order_ref' => 'PKG-AWAITING']);
        [, $otherPackage] = $this->fixtures();
        $foreign = $this->order($user, $otherPackage, []);

        $response = $this->actingAs($admin, 'web')
            ->get(route('admin.packages.show', $package))
            ->assertOk();
        $orders = $response->viewData('orders');
        self::assertSame('orders_page', $orders->getPageName());
        self::assertEqualsCanonicalizing([$settled->id, $awaiting->id], $orders->getCollection()->modelKeys());

        $xpath = $this->xpath($response->getContent());
        $settledRow = $this->rowFor($xpath, $settled);
        self::assertStringContainsString('Kashier — أندرويد مباشر/الويب', $settledRow);
        self::assertStringContainsString('95.50 EGP', $settledRow);
        self::assertStringContainsString('بانتظار التسوية', $this->rowFor($xpath, $awaiting));
        self::assertSame(0, $xpath->query('//a[@href="'.route('admin.orders.show', $foreign).'"]')->length);
    }

    private function rowFor(DOMXPath $xpath, Order $order): string
    {
        $rows = $xpath->query('//tr[.//a[@href="'.route('admin.orders.show', $order).'"]]');
        self::assertSame(1, $rows->length, 'Order #'.$order->id.' must have one history row.');

        return preg_replace('/\s+/u', ' ', (string) $rows->item(0)->textContent);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($document);
    }

    private function admin(): User
    {
        return User::query()->forceCreate([
            'name' => 'Payments Admin', 'email' => 'payments-admin@rokn.test', 'role' => 'admin', 'active' => true,
        ]);
    }

    /** @return array{User, Package} */
    private function fixtures(): array
    {
        $user = User::query()->forceCreate([
            'name' => 'Payments Student',
            'email' => 'payments-student-'.uniqid().'@rokn.test',
            'role' => 'client',
            'active' => true,
        ]);
        $package = Package::query()->create([
            'name_ar' => 'باقة الدفع', 'name_en' => 'Payments package', 'price' => 100, 'coins' => 500,
        ]);

        return [$user, $package];
    }

    /** @param array<string, mixed> $overrides */
    private function order(User $user, Package $package, array $overrides): Order
    {
        return Order::query()->create(array_merge([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'package_coins' => $package->coins,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'order_ref' => 'PKG-'.uniqid(),
            'amount' => 100,
            'discount_amount' => 0,
            'final_amount' => 100,
            'gateway_currency' => 'EGP',
            'total_coins' => $package->coins,
            'status' => Order::STATUS_PENDING,
            'financial_status' => Order::FINANCIAL_PENDING,
        ], $overrides));
    }
}
