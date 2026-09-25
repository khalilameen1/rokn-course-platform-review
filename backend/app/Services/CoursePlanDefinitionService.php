<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseAccessPlan;
use App\Models\Setting;

/** Owns new-offer defaults and global runtime policy, never purchased receipts. */
final readonly class CoursePlanDefinitionService
{
    public const AI_RUNTIME_FIELDS = [
        'chat_enabled', 'chat_message_limit', 'chat_token_budget',
        'chat_attachments_enabled', 'chat_attachment_max_files',
        'ai_budget_usd', 'request_reserve_usd', 'max_output_tokens',
        'model_override', 'project_feedback_level',
        'project_feedback_token_budget', 'project_feedback_budget_usd',
        'project_feedback_reserve_usd', 'project_followup_message_limit',
        'project_followup_token_budget', 'project_followup_budget_usd',
        'project_followup_reserve_usd', 'project_followup_attachments_enabled',
        'project_followup_attachment_max_files', 'project_output_enabled',
    ];

    /** @return array<string,array<string,mixed>> */
    public function globalAiPolicy(): array
    {
        return (array) (Setting::query()->value('ai_plan_policy') ?? []);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $tier
     * @param array<string,mixed> $template
     * @return array<string,mixed>
     */
    public function applyAiPolicy(array $row, array $tier, array $template): array
    {
        $paid = (int) ($row['price_coins'] ?? 0) > 0
            && (int) ($row['minimum_paid_coins'] ?? 0) > 0;
        $chatLimit = max(0, (int) ($tier['chat_message_limit'] ?? 0));
        $chat = $paid
            && !empty($tier['chat_enabled'])
            && $chatLimit > 0
            && (int) ($template['chat_token_budget'] ?? 0) >= (int) ($template['max_output_tokens'] ?? 0)
            && (float) ($template['ai_budget_usd'] ?? 0) > 0
            && (float) ($template['request_reserve_usd'] ?? 0) > 0;
        $feedback = $paid ? (string) ($tier['project_feedback_level'] ?? 'pass_only') : 'pass_only';
        if (!in_array($feedback, ['pass_only', 'report', 'enhanced'], true)) {
            $feedback = 'pass_only';
        }
        $followupLimit = max(0, (int) ($tier['project_followup_message_limit'] ?? 0));
        if ($feedback !== 'pass_only' && (
            (int) ($template['project_feedback_token_budget'] ?? 0) < (int) ($template['max_output_tokens'] ?? 0)
            || (float) ($template['project_feedback_budget_usd'] ?? 0) <= 0
            || (float) ($template['project_feedback_reserve_usd'] ?? 0) <= 0
        )) {
            $feedback = 'pass_only';
        }
        if ($feedback === 'enhanced' && (
            $followupLimit === 0
            || (int) ($template['project_followup_token_budget'] ?? 0) < (int) ($template['max_output_tokens'] ?? 0)
            || (float) ($template['project_followup_budget_usd'] ?? 0) <= 0
            || (float) ($template['project_followup_reserve_usd'] ?? 0) <= 0
        )) {
            $feedback = 'report';
        }

        $row['chat_enabled'] = $chat;
        $row['chat_message_limit'] = $chat ? $chatLimit : 0;
        $row['chat_token_budget'] = $chat ? (int) $template['chat_token_budget'] : 0;
        $row['chat_attachments_enabled'] = $chat && !empty($tier['chat_attachments_enabled']);
        $row['chat_attachment_max_files'] = $row['chat_attachments_enabled']
            ? (int) $template['chat_attachment_max_files'] : 0;
        $row['ai_budget_usd'] = $chat ? $template['ai_budget_usd'] : 0;
        $row['request_reserve_usd'] = $chat ? $template['request_reserve_usd'] : 0;
        $row['max_output_tokens'] = (int) $template['max_output_tokens'];
        $row['model_override'] = null;

        $hasReport = in_array($feedback, ['report', 'enhanced'], true);
        $row['project_feedback_level'] = $feedback;
        $row['project_feedback_token_budget'] = $hasReport
            ? (int) $template['project_feedback_token_budget'] : 0;
        $row['project_feedback_budget_usd'] = $hasReport
            ? $template['project_feedback_budget_usd'] : 0;
        $row['project_feedback_reserve_usd'] = $hasReport
            ? $template['project_feedback_reserve_usd'] : 0;

        $enhanced = $feedback === 'enhanced';
        $row['project_followup_message_limit'] = $enhanced ? $followupLimit : 0;
        $row['project_followup_token_budget'] = $enhanced
            ? (int) $template['project_followup_token_budget'] : 0;
        $row['project_followup_budget_usd'] = $enhanced
            ? $template['project_followup_budget_usd'] : 0;
        $row['project_followup_reserve_usd'] = $enhanced
            ? $template['project_followup_reserve_usd'] : 0;
        $row['project_followup_attachments_enabled'] = $enhanced
            && !empty($template['project_followup_attachments_enabled']);
        $row['project_followup_attachment_max_files'] = $row['project_followup_attachments_enabled']
            ? (int) $template['project_followup_attachment_max_files'] : 0;
        $row['project_output_enabled'] = $enhanced
            && !empty($template['project_output_enabled']);

        return $row;
    }

    public function defaultName(string $code): string
    {
        return match ($code) {
            CourseAccessPlan::GUIDED => 'Plus',
            CourseAccessPlan::MENTOR => 'Pro',
            default => 'Basic',
        };
    }

    public function defaults(int $base): array
    {
        $round = static fn (float $value): int => (int) (ceil(max(0, $value) / 50) * 50);
        $guided = (array) config('course_plans.ai_tiers.guided');
        $mentor = (array) config('course_plans.ai_tiers.mentor');
        $guidedCost = (float) $guided['ai_budget_usd']
            + (float) $guided['project_feedback_budget_usd'];
        $mentorCost = (float) $mentor['ai_budget_usd']
            + (float) $mentor['project_feedback_budget_usd']
            + (float) $mentor['project_followup_budget_usd'];
        $guidedPrice = $base + $this->costToCoins($guidedCost, $round);
        $mentorPrice = max(
            $base + $this->costToCoins($mentorCost, $round),
            $guidedPrice + 1000
        );
        return [
            [
                'code' => 'basic',
                'name_ar' => 'Basic',
                'name_en' => 'Basic',
                'price_coins' => $base,
                'minimum_paid_coins' => 0,
                'chat_enabled' => false,
                'chat_message_limit' => 0,
                'chat_token_budget' => 0,
                'chat_attachments_enabled' => false,
                'chat_attachment_max_files' => 0,
                'project_followup_attachments_enabled' => false,
                'project_followup_attachment_max_files' => 0,
                'ai_budget_usd' => 0,
                'request_reserve_usd' => 0,
                'max_output_tokens' => 260,
                'project_feedback_token_budget' => 0,
                'project_feedback_budget_usd' => 0,
                'project_feedback_reserve_usd' => 0,
                'project_followup_message_limit' => 0,
                'project_followup_token_budget' => 0,
                'project_followup_budget_usd' => 0,
                'project_followup_reserve_usd' => 0,
                'project_feedback_level' => 'pass_only',
                'project_output_enabled' => false,
                'projects_enabled' => false,
                'certificate_enabled' => false,
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'guided',
                'name_ar' => 'Plus',
                'name_en' => 'Plus',
                'price_coins' => $guidedPrice,
                'minimum_paid_coins' => $this->costToCoins($guidedCost, $round),
                'chat_enabled' => true,
                'chat_message_limit' => (int) $guided['chat_message_limit'],
                'chat_token_budget' => (int) $guided['chat_token_budget'],
                'chat_attachments_enabled' => true,
                'chat_attachment_max_files' => (int) $guided['chat_attachment_max_files'],
                'project_followup_attachments_enabled' => false,
                'project_followup_attachment_max_files' => 0,
                'ai_budget_usd' => (float) $guided['ai_budget_usd'],
                'request_reserve_usd' => (float) $guided['request_reserve_usd'],
                'max_output_tokens' => (int) $guided['max_output_tokens'],
                'project_feedback_token_budget' => (int) $guided['project_feedback_token_budget'],
                'project_feedback_budget_usd' => (float) $guided['project_feedback_budget_usd'],
                'project_feedback_reserve_usd' => (float) $guided['project_feedback_reserve_usd'],
                'project_followup_message_limit' => 0,
                'project_followup_token_budget' => 0,
                'project_followup_budget_usd' => 0,
                'project_followup_reserve_usd' => 0,
                'project_feedback_level' => 'report',
                'project_output_enabled' => false,
                'certificate_enabled' => true,
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'code' => 'mentor',
                'name_ar' => 'Pro',
                'name_en' => 'Pro',
                'price_coins' => $mentorPrice,
                'minimum_paid_coins' => $this->costToCoins($mentorCost, $round),
                'chat_enabled' => true,
                'chat_message_limit' => (int) $mentor['chat_message_limit'],
                'chat_token_budget' => (int) $mentor['chat_token_budget'],
                'chat_attachments_enabled' => true,
                'chat_attachment_max_files' => (int) $mentor['chat_attachment_max_files'],
                'project_followup_attachments_enabled' => true,
                'project_followup_attachment_max_files' => (int) $mentor['project_followup_attachment_max_files'],
                'ai_budget_usd' => (float) $mentor['ai_budget_usd'],
                'request_reserve_usd' => (float) $mentor['request_reserve_usd'],
                'max_output_tokens' => (int) $mentor['max_output_tokens'],
                'project_feedback_token_budget' => (int) $mentor['project_feedback_token_budget'],
                'project_feedback_budget_usd' => (float) $mentor['project_feedback_budget_usd'],
                'project_feedback_reserve_usd' => (float) $mentor['project_feedback_reserve_usd'],
                'project_followup_message_limit' => (int) $mentor['project_followup_message_limit'],
                'project_followup_token_budget' => (int) $mentor['project_followup_token_budget'],
                'project_followup_budget_usd' => (float) $mentor['project_followup_budget_usd'],
                'project_followup_reserve_usd' => (float) $mentor['project_followup_reserve_usd'],
                'project_feedback_level' => 'enhanced',
                'project_output_enabled' => true,
                'certificate_enabled' => true,
                'is_active' => true,
                'sort_order' => 30,
            ],
        ];
    }

    private function costToCoins(float $maximumProviderCostUsd, callable $round): int
    {
        $coinValue = max(0.000001, (float) config('course_plans.net_usd_per_paid_coin', .001));
        $safety = max(1, (float) config('course_plans.ai_cost_safety_multiplier', 2));

        return max(50, $round(($maximumProviderCostUsd * $safety) / $coinValue));
    }
}
