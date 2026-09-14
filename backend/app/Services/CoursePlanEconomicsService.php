<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;

/** Commercial planning only; accounting still uses settled transaction lots. */
final class CoursePlanEconomicsService
{
    public function promotionPercent(): int
    {
        return min(20, max(0, (int) (Setting::query()->value('max_course_promotion_percent')
            ?? config('course_plans.max_promotion_percent', 20))));
    }

    /**
     * delivery_cost_usd explicitly allocates production, hosting, support and
     * mandatory project checks per enrollment. Provider budgets are added once
     * with their safety factor. net_usd_per_paid_coin is ALREADY after fees,
     * indirect taxes and FX: applying those deductions again is incorrect.
     *
     * @return array<string, mixed>
     */
    public function evaluate(array $terms, ?int $promotionPercent = null): array
    {
        $promotionPercent ??= $this->promotionPercent();
        $margin = (float) config('course_plans.target_contribution_margin_percent', 40) / 100;
        $netCoinValue = (float) config('course_plans.net_usd_per_paid_coin', 0);
        $safety = (float) config('course_plans.ai_cost_safety_multiplier', 0);
        $delivery = $terms['delivery_cost_usd'] ?? null;
        $problems = [];
        if (!config('course_plans.economics_configured') || !is_finite($netCoinValue) || $netCoinValue <= 0) {
            $problems[] = 'صافي قيمة العملة المدفوعة غير معتمد';
        }
        if (!is_numeric($delivery) || !is_finite((float) $delivery) || (float) $delivery < 0) {
            $problems[] = 'تكلفة تقديم الاشتراك غير محددة';
        }
        if (!is_finite($safety) || $safety < 1 || !is_finite($margin) || $margin < 0 || $margin >= 1
            || $promotionPercent < 0 || $promotionPercent >= 100) {
            $problems[] = 'إعدادات الهامش أو الاحتياطي أو الخصم غير صالحة للتسعير';
        }
        $providerBudget = 0.0;
        if (!empty($terms['chat_enabled'])) {
            $providerBudget += max(0, (float) ($terms['ai_budget_usd'] ?? 0));
        }
        if (in_array($terms['project_feedback_level'] ?? '', ['report', 'enhanced'], true)) {
            $providerBudget += max(0, (float) ($terms['project_feedback_budget_usd'] ?? 0));
        }
        if (($terms['project_feedback_level'] ?? '') === 'enhanced') {
            $providerBudget += max(0, (float) ($terms['project_followup_budget_usd'] ?? 0));
        }

        $result = [
            'configured' => $problems === [],
            'problems' => $problems,
            'promotion_percent' => $promotionPercent,
            'target_margin_percent' => $margin * 100,
            'provider_budget_usd' => $providerBudget,
            'fully_loaded_cost_usd' => null,
            'required_paid_coins' => null,
            'required_price_coins' => null,
            'meets_floor' => false,
        ];
        if ($problems !== []) return $result;

        $cost = (float) $delivery + $providerBudget * $safety;
        // Round UP, and require enough purchased revenue after full promotion.
        $paidFloor = (int) ceil(round($cost / ((1 - $margin) * $netCoinValue), 8));
        $priceFloor = (int) ceil(round($paidFloor / (1 - $promotionPercent / 100), 8));
        $result['fully_loaded_cost_usd'] = $cost;
        $result['required_paid_coins'] = $paidFloor;
        $result['required_price_coins'] = $priceFloor;
        $result['meets_floor'] = (int) ($terms['price_coins'] ?? 0) >= $priceFloor
            && (int) ($terms['minimum_paid_coins'] ?? 0) >= $paidFloor;

        return $result;
    }

    public function assertCommercialFloor(array $terms, string $code, ?int $promotionPercent = null): void
    {
        $quote = $this->evaluate($terms, $promotionPercent);
        if (!$quote['configured']) {
            if (config('course_plans.enforce_commercial_floor') && (int) ($terms['price_coins'] ?? 0) > 0) {
                throw ValidationException::withMessages([
                    "access_plans.{$code}.delivery_cost_usd" => $quote['problems'],
                ]);
            }
            return;
        }
        if (!$quote['meets_floor']) {
            throw ValidationException::withMessages([
                "access_plans.{$code}.price_coins" => [
                    "السعر لا يقل عن {$quote['required_price_coins']} والحد المدفوع لا يقل عن {$quote['required_paid_coins']} عملة لتغطية التكلفة والهامش بعد الخصم",
                ],
            ]);
        }
    }
}
