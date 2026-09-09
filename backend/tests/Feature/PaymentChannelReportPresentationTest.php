<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\PaymentChannelReportService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentChannelReportPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_provider_settlement_is_pending_not_zero_or_estimated_current_net(): void
    {
        $this->payment(['gateway_gross_amount' => 10, 'gateway_fee_amount' => null, 'gateway_net_amount' => null]);
        $table = $this->renderTable();

        self::assertSame('بانتظار التسوية', $this->cell($table, 'Kashier', 5));
        self::assertSame('بانتظار التسوية', $this->cell($table, 'Kashier', 6));
        self::assertSame('بانتظار التسوية', $this->total($table, 5));
        self::assertSame('بانتظار التسوية', $this->total($table, 6));
        self::assertSame(7, $table->query('//thead/tr/th')->length);
        self::assertStringNotContainsString('الصافي الحالي', $table->document->textContent);
        self::assertStringNotContainsString('مؤكد، أو تقديري', $table->document->textContent);
    }

    public function test_partial_confirmed_amounts_are_retained_and_identified_as_partial_in_row_and_egp_total(): void
    {
        $this->payment(['gateway_gross_amount' => 10, 'gateway_fee_amount' => 1, 'gateway_net_amount' => 9]);
        $this->payment(['gateway_gross_amount' => 20, 'gateway_fee_amount' => null, 'gateway_net_amount' => null]);
        $table = $this->renderTable();

        foreach ([[$this->cell($table, 'Kashier', 5), '1.00'], [$this->cell($table, 'Kashier', 6), '9.00'],
            [$this->total($table, 5), '1.00'], [$this->total($table, 6), '9.00']] as [$text, $amount]) {
            self::assertStringContainsString($amount, $text);
            self::assertStringContainsString('جزئي', $text);
        }
        self::assertStringNotContainsString('29.00', $table->document->textContent);
    }

    public function test_no_activity_is_a_real_zero_not_a_missing_settlement(): void
    {
        $table = $this->renderTable();
        foreach ([4, 5, 6] as $column) {
            self::assertStringStartsWith('0.00', $this->cell($table, 'Kashier', $column));
            self::assertStringNotContainsString('بانتظار', $this->cell($table, 'Kashier', $column));
            self::assertSame('0.00', $this->total($table, $column));
        }
    }

    public function test_explicit_zero_fees_are_confirmed_and_do_not_require_nonzero_amounts(): void
    {
        $this->payment(['gateway_gross_amount' => 10, 'gateway_fee_amount' => 0, 'gateway_net_amount' => 10]);
        $table = $this->renderTable();
        self::assertSame('0.00', $this->cell($table, 'Kashier', 5));
        self::assertSame('0.00', $this->total($table, 5));
        self::assertStringNotContainsString('بانتظار', $this->cell($table, 'Kashier', 6));
        self::assertStringNotContainsString('جزئي', $this->cell($table, 'Kashier', 6));
    }

    public function test_fees_and_net_have_independent_evidence_counts(): void
    {
        $this->payment(['gateway_gross_amount' => 10, 'gateway_fee_amount' => null, 'gateway_net_amount' => 9]);
        $this->payment(['payment_method' => Order::PAYMENT_METHOD_APP_STORE,
            'gateway_gross_amount' => 10, 'gateway_fee_amount' => 1, 'gateway_net_amount' => null]);
        $table = $this->renderTable();
        self::assertSame('بانتظار التسوية', $this->cell($table, 'Kashier', 5));
        self::assertStringStartsWith('9.00', $this->cell($table, 'Kashier', 6));
        self::assertSame('1.00', $this->cell($table, 'App Store', 5));
        self::assertSame('بانتظار التسوية', $this->cell($table, 'App Store', 6));
        self::assertStringContainsString('جزئي', $this->total($table, 5));
        self::assertStringContainsString('جزئي', $this->total($table, 6));
    }

    public function test_test_and_catalog_amounts_do_not_turn_into_confirmed_fees_or_confirmation_counts(): void
    {
        $this->payment(['gateway_gross_amount' => 10, 'gateway_fee_amount' => 0, 'gateway_net_amount' => 10]);
        $this->payment(['gateway_gross_amount' => 10, 'gateway_fee_amount' => 88, 'gateway_net_amount' => 77,
            'gateway_settlement_status' => 'catalog_estimate']);
        $this->payment(['gateway_gross_amount' => 10, 'gateway_fee_amount' => 99, 'gateway_net_amount' => 66,
            'gateway_settlement_status' => 'test_purchase']);
        $report = app(PaymentChannelReportService::class)->summary();
        self::assertSame(1, $report['egp']['confirmed_fee_count']);
        self::assertSame(0.0, $report['egp']['confirmed_fee_amount']);
        $table = $this->renderTable();
        self::assertSame('0.00 جزئي · 1 من 2 عملية', $this->cell($table, 'Kashier', 5));
        self::assertSame('0.00 جزئي · 1 من 2 عملية', $this->total($table, 5));
        self::assertSame('10.00 جزئي · 1 من 2 عملية', $this->cell($table, 'Kashier', 6));
        self::assertSame('10.00 جزئي · 1 من 2 عملية', $this->total($table, 6));
    }

    public function test_foreign_and_unknown_currency_rows_do_not_fill_pending_egp_settlement_with_other_money(): void
    {
        $this->payment(['gateway_gross_amount' => 10, 'gateway_fee_amount' => null, 'gateway_net_amount' => null]);
        $this->payment(['payment_method' => Order::PAYMENT_METHOD_APP_STORE, 'gateway_currency' => 'USD',
            'gateway_gross_amount' => 100, 'gateway_fee_amount' => 2, 'gateway_net_amount' => 98]);
        $this->payment(['payment_method' => Order::PAYMENT_METHOD_GOOGLE_PLAY, 'gateway_currency' => null,
            'gateway_gross_amount' => null, 'gateway_fee_amount' => null, 'gateway_net_amount' => null,
            'gateway_settlement_status' => 'catalog_estimate']);
        $table = $this->renderTable();

        self::assertSame('10.00', $this->total($table, 4));
        self::assertSame('بانتظار التسوية', $this->total($table, 5));
        self::assertSame('بانتظار التسوية', $this->total($table, 6));
        self::assertStringContainsString('98.00', $this->cell($table, 'App Store', 6));
        self::assertStringContainsString('بانتظار تأكيد التحصيل', $this->cell($table, 'Google Play', 4));
        self::assertStringContainsString('العملة بانتظار كشف المزود', $table->document->textContent);
        self::assertStringContainsString('لا تُجمع العملات المختلفة', $table->document->textContent);
    }

    private function payment(array $attributes): void
    {
        $user = User::forceCreate(['name_ar' => 'طالب', 'email' => Str::uuid().'@example.test', 'role' => 'client', 'active' => true]);
        $package = Package::create(['name_ar' => 'باقة', 'name_en' => 'Package', 'price' => 10, 'coins' => 100]);
        Order::create($attributes + [
            'user_id' => $user->id, 'package_id' => $package->id, 'package_coins' => 100,
            'order_ref' => 'PRESENTATION-'.Str::uuid(), 'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'amount' => 10, 'discount_amount' => 0, 'final_amount' => 10, 'total_coins' => 100,
            'gateway_currency' => 'EGP', 'gateway_settlement_status' => 'settled',
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now(),
        ]);
    }

    private function renderTable(): DOMXPath
    {
        $html = view('admin.orders.partials.index.payment-channel-report', [
            'paymentChannelReport' => app(PaymentChannelReportService::class)->summary(),
        ])->render();
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($document);
    }

    private function cell(DOMXPath $table, string $label, int $column): string
    {
        return $this->text($table, '//tbody/tr[td[1]//strong[contains(text(), "'.$label.'")]]/td['.$column.']');
    }

    private function total(DOMXPath $table, int $column): string
    {
        return $this->text($table, '//tfoot/tr/th['.$column.']');
    }

    private function text(DOMXPath $table, string $selector): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $table->evaluate('string('.$selector.')')));
    }
}
