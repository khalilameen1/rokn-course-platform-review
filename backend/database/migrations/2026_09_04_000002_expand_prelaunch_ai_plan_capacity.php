<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const GUIDED = [
        'chat_message_limit' => 50,
        'chat_token_budget' => 300000,
        'ai_budget_usd' => .80,
        'request_reserve_usd' => .014,
        'max_output_tokens' => 600,
        'project_feedback_token_budget' => 20000,
        'project_feedback_budget_usd' => .25,
        'project_feedback_reserve_usd' => .02,
    ];

    private const MENTOR = [
        'chat_message_limit' => 150,
        'chat_token_budget' => 1000000,
        'ai_budget_usd' => 2.50,
        'request_reserve_usd' => .016,
        'max_output_tokens' => 800,
        'project_feedback_token_budget' => 50000,
        'project_feedback_budget_usd' => .75,
        'project_feedback_reserve_usd' => .025,
        'project_followup_message_limit' => 50,
        'project_followup_token_budget' => 250000,
        'project_followup_budget_usd' => .75,
        'project_followup_reserve_usd' => .015,
    ];

    public function up(): void
    {
        $this->updatePolicy(50, 150, 50);
        $this->updatePlans(self::GUIDED, self::MENTOR);
        $this->updatePrelaunchReceipts(self::GUIDED, self::MENTOR);
    }

    public function down(): void
    {
        $guided = [
            'chat_message_limit' => 25,
            'chat_token_budget' => 12000,
            'ai_budget_usd' => .45,
            'request_reserve_usd' => .015,
            'max_output_tokens' => 320,
            'project_feedback_token_budget' => 6000,
            'project_feedback_budget_usd' => .20,
            'project_feedback_reserve_usd' => .04,
        ];
        $mentor = [
            'chat_message_limit' => 80,
            'chat_token_budget' => 42000,
            'ai_budget_usd' => 1.50,
            'request_reserve_usd' => .025,
            'max_output_tokens' => 480,
            'project_feedback_token_budget' => 16000,
            'project_feedback_budget_usd' => .60,
            'project_feedback_reserve_usd' => .08,
            'project_followup_message_limit' => 20,
            'project_followup_token_budget' => 12000,
            'project_followup_budget_usd' => .30,
            'project_followup_reserve_usd' => .025,
        ];

        $this->updatePolicy(25, 80, 20);
        $this->updatePlans($guided, $mentor);
        $this->updatePrelaunchReceipts($guided, $mentor);
    }

    /** @param array<string,int|float> $guided @param array<string,int|float> $mentor */
    private function updatePlans(array $guided, array $mentor): void
    {
        if (!Schema::hasTable('course_access_plans')) return;

        foreach (['guided' => $guided, 'mentor' => $mentor] as $code => $values) {
            DB::table('course_access_plans')
                ->where('code', $code)
                ->where('price_coins', '>', 0)
                ->where('minimum_paid_coins', '>', 0)
                ->update(array_merge($values, ['updated_at' => now()]));
        }
    }

    private function updatePolicy(int $guidedMessages, int $mentorMessages, int $followups): void
    {
        if (!Schema::hasTable('settings') || !Schema::hasColumn('settings', 'ai_plan_policy')) {
            return;
        }

        DB::table('settings')->orderBy('id')->get(['id', 'ai_plan_policy'])
            ->each(function (object $row) use ($guidedMessages, $mentorMessages, $followups): void {
                $policy = json_decode((string) $row->ai_plan_policy, true);
                if (!is_array($policy)) $policy = [];

                $policy['guided'] = array_merge((array) ($policy['guided'] ?? []), [
                    'chat_message_limit' => $guidedMessages,
                ]);
                $policy['mentor'] = array_merge((array) ($policy['mentor'] ?? []), [
                    'chat_message_limit' => $mentorMessages,
                    'project_followup_message_limit' => $followups,
                ]);

                DB::table('settings')->where('id', $row->id)->update([
                    'ai_plan_policy' => json_encode(
                        $policy,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ]);
            });
    }

    /**
     * The product is still pre-launch. Keep tester receipts aligned with the
     * plan they are exercising so the next APK does not retain the old cap.
     *
     * @param array<string,int|float> $guided
     * @param array<string,int|float> $mentor
     */
    private function updatePrelaunchReceipts(array $guided, array $mentor): void
    {
        if (
            !Schema::hasTable('course_enrollments')
            || !Schema::hasColumn('course_enrollments', 'access_plan_snapshot')
        ) return;

        DB::table('course_enrollments')
            ->whereNotNull('access_plan_snapshot')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($guided, $mentor): void {
                foreach ($rows as $row) {
                    $snapshot = json_decode((string) $row->access_plan_snapshot, true);
                    if (!is_array($snapshot) || empty($snapshot['chat_enabled'])) continue;

                    $code = strtolower(trim((string) ($snapshot['code'] ?? '')));
                    $values = $code === 'guided' ? $guided : ($code === 'mentor' ? $mentor : null);
                    if ($values === null) continue;

                    DB::table('course_enrollments')->where('id', $row->id)->update([
                        'access_plan_snapshot' => json_encode(
                            array_merge($snapshot, $values),
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ),
                        'updated_at' => now(),
                    ]);
                }
            }, 'id');
    }
};
