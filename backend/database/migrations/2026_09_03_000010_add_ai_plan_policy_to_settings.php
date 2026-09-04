<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('settings', 'ai_plan_policy')) {
            Schema::table('settings', fn (Blueprint $table) =>
                $table->json('ai_plan_policy')->nullable()
            );
        }

        $policy = [
            'basic' => [
                'chat_enabled' => false,
                'chat_message_limit' => 0,
                'chat_attachments_enabled' => false,
                'project_feedback_level' => 'pass_only',
                'project_followup_message_limit' => 0,
            ],
            'guided' => [
                'chat_enabled' => true,
                'chat_message_limit' => 50,
                'chat_attachments_enabled' => true,
                'project_feedback_level' => 'report',
                'project_followup_message_limit' => 0,
            ],
            'mentor' => [
                'chat_enabled' => true,
                'chat_message_limit' => 150,
                'chat_attachments_enabled' => true,
                'project_feedback_level' => 'enhanced',
                'project_followup_message_limit' => 50,
            ],
        ];

        DB::table('settings')->whereNull('ai_plan_policy')->update([
            'ai_plan_policy' => json_encode(
                $policy,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ]);

        if (!Schema::hasTable('course_access_plans')) return;

        DB::table('course_access_plans')->where('code', 'basic')->update([
            'chat_enabled' => false,
            'chat_message_limit' => 0,
            'chat_token_budget' => 0,
            'chat_attachments_enabled' => false,
            'chat_attachment_max_files' => 0,
            'ai_budget_usd' => 0,
            'request_reserve_usd' => 0,
            'max_output_tokens' => 260,
            'model_override' => null,
            'project_feedback_level' => 'pass_only',
            'project_feedback_token_budget' => 0,
            'project_feedback_budget_usd' => 0,
            'project_feedback_reserve_usd' => 0,
            'project_followup_message_limit' => 0,
            'project_followup_token_budget' => 0,
            'project_followup_budget_usd' => 0,
            'project_followup_reserve_usd' => 0,
            'project_followup_attachments_enabled' => false,
            'project_followup_attachment_max_files' => 0,
            'project_output_enabled' => false,
        ]);

        $this->applyPaidTier('guided', [
            'chat_enabled' => true,
            'chat_message_limit' => 50,
            'chat_token_budget' => 300000,
            'chat_attachments_enabled' => true,
            'chat_attachment_max_files' => 2,
            'ai_budget_usd' => .80,
            'request_reserve_usd' => .014,
            'max_output_tokens' => 600,
            'project_feedback_level' => 'report',
            'project_feedback_token_budget' => 20000,
            'project_feedback_budget_usd' => .25,
            'project_feedback_reserve_usd' => .02,
            'project_followup_message_limit' => 0,
            'project_followup_token_budget' => 0,
            'project_followup_budget_usd' => 0,
            'project_followup_reserve_usd' => 0,
            'project_followup_attachments_enabled' => false,
            'project_followup_attachment_max_files' => 0,
            'project_output_enabled' => false,
            'model_override' => null,
        ]);
        $this->applyPaidTier('mentor', [
            'chat_enabled' => true,
            'chat_message_limit' => 150,
            'chat_token_budget' => 1000000,
            'chat_attachments_enabled' => true,
            'chat_attachment_max_files' => 3,
            'ai_budget_usd' => 2.5,
            'request_reserve_usd' => .016,
            'max_output_tokens' => 800,
            'project_feedback_level' => 'enhanced',
            'project_feedback_token_budget' => 50000,
            'project_feedback_budget_usd' => .75,
            'project_feedback_reserve_usd' => .025,
            'project_followup_message_limit' => 50,
            'project_followup_token_budget' => 250000,
            'project_followup_budget_usd' => .75,
            'project_followup_reserve_usd' => .015,
            'project_followup_attachments_enabled' => true,
            'project_followup_attachment_max_files' => 3,
            'project_output_enabled' => true,
            'model_override' => null,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'ai_plan_policy')) {
            Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('ai_plan_policy'));
        }
    }

    /** @param array<string,mixed> $values */
    private function applyPaidTier(string $code, array $values): void
    {
        DB::table('course_access_plans')
            ->where('code', $code)
            ->where('price_coins', '>', 0)
            ->where('minimum_paid_coins', '>', 0)
            ->update($values);

        DB::table('course_access_plans')
            ->where('code', $code)
            ->where(function ($query): void {
                $query->where('price_coins', '<=', 0)
                    ->orWhere('minimum_paid_coins', '<=', 0);
            })
            ->update([
                'chat_enabled' => false,
                'chat_message_limit' => 0,
                'chat_token_budget' => 0,
                'chat_attachments_enabled' => false,
                'chat_attachment_max_files' => 0,
                'ai_budget_usd' => 0,
                'request_reserve_usd' => 0,
                'max_output_tokens' => (int) ($values['max_output_tokens'] ?? 320),
                'model_override' => null,
                'project_feedback_level' => 'pass_only',
                'project_feedback_token_budget' => 0,
                'project_feedback_budget_usd' => 0,
                'project_feedback_reserve_usd' => 0,
                'project_followup_message_limit' => 0,
                'project_followup_token_budget' => 0,
                'project_followup_budget_usd' => 0,
                'project_followup_reserve_usd' => 0,
                'project_followup_attachments_enabled' => false,
                'project_followup_attachment_max_files' => 0,
                'project_output_enabled' => false,
            ]);
    }
};
