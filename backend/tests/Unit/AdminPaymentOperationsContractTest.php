<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminPaymentOperationsContractTest extends TestCase
{
    public function test_dashboard_uses_one_read_owner_and_canonical_operation_states(): void
    {
        $controller = $this->source('app/Http/Controllers/Admin/OrdersController.php');
        $read = $this->source('app/Services/AdminPaymentOperationsReadService.php');
        $request = $this->source('app/Http/Requests/Admin/OrderIndexRequest.php');

        self::assertStringContainsString('AdminPaymentOperationsReadService', $controller);
        self::assertStringNotContainsString('Order::with(', $controller);
        foreach (['created', 'pending', 'paid', 'failed', 'expired', 'cancelled'] as $state) {
            self::assertStringContainsString("'{$state}'", $read);
            self::assertStringContainsString("'{$state}'", $request);
        }
        self::assertStringContainsString('->paginate(25)', $read);
        self::assertStringContainsString("'user', 'course', 'package'", $read);
    }

    public function test_provider_orders_have_no_manual_status_or_bulk_backdoor(): void
    {
        $routes = $this->source('routes/web.php');
        $controller = $this->source('app/Http/Controllers/Admin/OrdersController.php');
        $list = $this->source('resources/views/admin/orders/partials/index/orders-table.blade.php');
        $lifecycle = $this->source('app/Services/OrderLifecycleService.php');

        self::assertStringNotContainsString('orders/bulk-status', $routes);
        self::assertStringNotContainsString('function bulkUpdateStatus', $controller);
        self::assertStringNotContainsString("Route::resource('bills'", $routes);
        self::assertStringNotContainsString("Route::resource('payment-methods'", $routes);
        self::assertStringNotContainsString('updateOrderStatus', $list);
        self::assertStringNotContainsString('orders.update-status', $routes);
        self::assertStringContainsString(
            'Only a pending manual order can be approved by an administrator.',
            $lifecycle
        );
    }

    public function test_package_history_is_rendered_from_orders_with_channel_and_net(): void
    {
        $package = $this->source('resources/views/admin/packages/show.blade.php');
        $controller = $this->source('app/Http/Controllers/Admin/PackageController.php');

        self::assertStringContainsString("'orders' => \$payments->packageOrders", $controller);
        self::assertStringContainsString('$order->gateway_net_amount', $package);
        self::assertStringContainsString('$paymentMethodLabels[$order->payment_method]', $package);
        self::assertStringNotContainsString('$user->pivot->price', $package);
    }

    private function source(string $relative): string
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$relative);
        self::assertIsString($source, $relative);

        return $source;
    }
}
