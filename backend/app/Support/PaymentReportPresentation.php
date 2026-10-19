<?php

declare(strict_types=1);

namespace App\Support;

/** Formats the existing channel report; never estimates missing provider money. */
final class PaymentReportPresentation
{
    public static function amount(array $row, string $kind): string
    {
        if ($row['live_count'] > 0 && $row['confirmed_'.$kind.'_count'] === 0) {
            return $kind === 'gross' ? 'بانتظار تأكيد التحصيل' : 'بانتظار التسوية';
        }

        return number_format($row['confirmed_'.$kind.'_amount'], 2);
    }

    public static function detail(array $row, string $kind): ?string
    {
        $notes = [];
        $confirmed = $row['confirmed_'.$kind.'_count'];
        if ($confirmed > 0 && $confirmed < $row['live_count']) {
            $notes[] = 'جزئي · '.$confirmed.' من '.$row['live_count'].' عملية';
        }
        if ($kind === 'gross' && $row['catalog_estimated_gross_count'] > 0) {
            $notes[] = '+ '.number_format($row['catalog_estimated_gross_amount'], 2).' تقدير كتالوج خارج الإجمالي · '.$row['catalog_estimated_gross_count'].' عملية';
        }

        return implode(' · ', $notes) ?: null;
    }

    public static function egp(array $report): array
    {
        return $report['egp'] + ['confirmed_net_count' => (int) collect($report['rows'])->where('currency', 'EGP')->sum('confirmed_net_count')];
    }
}
