<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** MySQL applies each ALTER atomically but commits DDL implicitly. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ([
            ['orders', 'orders_access_plan_snapshot_check', false],
            ['course_enrollments', 'enrollments_access_plan_snapshot_check', true],
        ] as [$table, $constraint, $requiresPlanOrder]) {
            $schema = 'CONVERT(0x'
                . bin2hex($this->snapshotJsonSchema())
                . ' USING utf8mb4)';
            $planOrder = $requiresPlanOrder ? 'AND access_plan_order_id IS NOT NULL ' : '';
            $expression = '(access_plan_id IS NULL OR ('
                . 'access_plan_snapshot IS NOT NULL '
                . $planOrder
                . "AND JSON_SCHEMA_VALID({$schema}, access_plan_snapshot) = 1 "
                . "AND CAST(JSON_UNQUOTE(JSON_EXTRACT(access_plan_snapshot, '$.plan_id')) "
                . 'AS UNSIGNED) = access_plan_id))';
            $drop = $this->checkExists($table, $constraint)
                ? "DROP CHECK `{$constraint}`, "
                : '';

            DB::statement(
                "ALTER TABLE `{$table}` {$drop}ADD CONSTRAINT `{$constraint}` CHECK ({$expression})"
            );
        }
    }

    public function down(): void
    {
        // Snapshots are immutable financial receipts. Once v4/v5 rows exist,
        // restoring the older constraint would make legitimate data invalid.
    }

    private function checkExists(string $table, string $constraint): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::connection()->getDatabaseName())
            ->where('table_name', DB::getTablePrefix() . $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'CHECK')
            ->exists();
    }

    private function snapshotJsonSchema(): string
    {
        $fixedMoney = [];
        foreach ([
            'ai_budget_usd',
            'request_reserve_usd',
            'project_feedback_budget_usd',
            'project_feedback_reserve_usd',
        ] as $key) {
            $fixedMoney[$key] = [
                'type' => 'string',
                'pattern' => '^[0-9]+\.[0-9]{6}$',
            ];
        }
        $v1Money = array_fill_keys(array_keys($fixedMoney), [
            'type' => 'number',
            'minimum' => 0,
        ]);
        $followupMoney = [
            'project_followup_budget_usd' => [
                'type' => 'string',
                'pattern' => '^[0-9]+\.[0-9]{6}$',
            ],
            'project_followup_reserve_usd' => [
                'type' => 'string',
                'pattern' => '^[0-9]+\.[0-9]{6}$',
            ],
        ];
        $v2Required = ['sort_order', 'minimum_paid_coins'];
        $v3Required = [
            ...$v2Required,
            'project_followup_message_limit',
            'project_followup_token_budget',
            'project_followup_budget_usd',
            'project_followup_reserve_usd',
        ];
        $v4Required = [
            ...$v3Required,
            'chat_attachments_enabled',
            'chat_attachment_max_files',
        ];
        $v5Required = [
            ...$v4Required,
            'project_followup_attachments_enabled',
            'project_followup_attachment_max_files',
        ];
        $fixedProperties = array_merge([
            'sort_order' => ['type' => 'integer', 'minimum' => 0],
            'minimum_paid_coins' => ['type' => 'integer', 'minimum' => 0],
        ], $fixedMoney);
        $followupProperties = array_merge($fixedProperties, [
            'project_followup_message_limit' => ['type' => 'integer', 'minimum' => 0],
            'project_followup_token_budget' => ['type' => 'integer', 'minimum' => 0],
        ], $followupMoney);
        $chatAttachmentProperties = array_merge($followupProperties, [
            'chat_attachments_enabled' => ['type' => 'boolean'],
            'chat_attachment_max_files' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5],
        ]);

        return (string) json_encode([
            'type' => 'object',
            'required' => [
                'version', 'plan_id', 'code', 'name_ar', 'price_coins',
                'chat_enabled', 'chat_message_limit', 'chat_token_budget',
                'ai_budget_usd', 'request_reserve_usd',
                'project_feedback_token_budget', 'project_feedback_budget_usd',
                'project_feedback_reserve_usd', 'max_output_tokens',
                'model_override', 'project_feedback_level',
                'project_output_enabled', 'certificate_enabled', 'purchased_at',
            ],
            'properties' => [
                'version' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5]],
                'plan_id' => ['type' => 'integer', 'minimum' => 1],
                'code' => ['type' => 'string', 'enum' => ['basic', 'guided', 'mentor']],
                'name_ar' => ['type' => 'string', 'minLength' => 1],
                'price_coins' => ['type' => 'integer', 'minimum' => 0],
                'minimum_paid_coins' => ['type' => 'integer', 'minimum' => 0],
                'chat_enabled' => ['type' => 'boolean'],
                'chat_message_limit' => ['type' => 'integer', 'minimum' => 0],
                'chat_token_budget' => ['type' => 'integer', 'minimum' => 0],
                'sort_order' => ['type' => 'integer', 'minimum' => 0],
                'ai_budget_usd' => ['type' => ['number', 'string']],
                'request_reserve_usd' => ['type' => ['number', 'string']],
                'project_feedback_token_budget' => ['type' => 'integer', 'minimum' => 0],
                'project_feedback_budget_usd' => ['type' => ['number', 'string']],
                'project_feedback_reserve_usd' => ['type' => ['number', 'string']],
                'project_followup_message_limit' => ['type' => 'integer', 'minimum' => 0],
                'project_followup_token_budget' => ['type' => 'integer', 'minimum' => 0],
                'project_followup_budget_usd' => ['type' => ['number', 'string']],
                'project_followup_reserve_usd' => ['type' => ['number', 'string']],
                'chat_attachments_enabled' => ['type' => 'boolean'],
                'chat_attachment_max_files' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5],
                'project_followup_attachments_enabled' => ['type' => 'boolean'],
                'project_followup_attachment_max_files' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5],
                'max_output_tokens' => ['type' => 'integer', 'minimum' => 1],
                'model_override' => ['type' => ['string', 'null']],
                'project_feedback_level' => [
                    'type' => 'string',
                    'enum' => ['pass_only', 'report', 'enhanced'],
                ],
                'project_output_enabled' => ['type' => 'boolean'],
                'certificate_enabled' => ['type' => 'boolean'],
                'purchased_at' => ['type' => 'string', 'minLength' => 1],
            ],
            'oneOf' => [
                [
                    'properties' => array_merge(
                        ['version' => ['type' => 'integer', 'enum' => [1]]],
                        $v1Money
                    ),
                ],
                [
                    'required' => $v2Required,
                    'properties' => array_merge(
                        ['version' => ['type' => 'integer', 'enum' => [2]]],
                        $fixedProperties
                    ),
                ],
                [
                    'required' => $v3Required,
                    'properties' => array_merge(
                        ['version' => ['type' => 'integer', 'enum' => [3]]],
                        $followupProperties
                    ),
                ],
                [
                    'required' => $v4Required,
                    'properties' => array_merge(
                        ['version' => ['type' => 'integer', 'enum' => [4]]],
                        $chatAttachmentProperties
                    ),
                ],
                [
                    'required' => $v5Required,
                    'properties' => array_merge(
                        ['version' => ['type' => 'integer', 'enum' => [5]]],
                        $chatAttachmentProperties,
                        [
                            'project_followup_attachments_enabled' => ['type' => 'boolean'],
                            'project_followup_attachment_max_files' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'maximum' => 5,
                            ],
                        ]
                    ),
                ],
            ],
            'additionalProperties' => true,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
};
