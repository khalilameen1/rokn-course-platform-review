<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CourseAccessPlan;
use LogicException;

/**
 * Runtime guard for the immutable commercial terms copied onto an order and
 * its current enrollment. MySQL also requires a non-null snapshot whenever a
 * plan is present; this class verifies the versioned payload on every write so
 * SQLite tests and application-created records enforce the same contract.
 */
final class CourseAccessPlanSnapshot
{
    public const CURRENT_VERSION = 6;
    public const SUPPORTED_VERSIONS = [1, 2, 3, 4, 5, self::CURRENT_VERSION];

    /** @var list<string> */
    private const REQUIRED_KEYS = [
        'version',
        'plan_id',
        'code',
        'name_ar',
        'price_coins',
        'chat_enabled',
        'chat_message_limit',
        'chat_token_budget',
        'ai_budget_usd',
        'request_reserve_usd',
        'project_feedback_token_budget',
        'project_feedback_budget_usd',
        'project_feedback_reserve_usd',
        'max_output_tokens',
        'model_override',
        'project_feedback_level',
        'project_output_enabled',
        'certificate_enabled',
        'purchased_at',
    ];

    /**
     * A null plan is the explicit legacy-access state. A plan-backed write,
     * however, must always carry the complete immutable receipt it promises.
     */
    public static function assertValidForPlan(?int $accessPlanId, mixed $snapshot): void
    {
        if ($accessPlanId === null) {
            return;
        }

        if ($accessPlanId <= 0 || !is_array($snapshot)) {
            throw new LogicException('A plan-backed course entitlement requires a valid snapshot.');
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $snapshot)) {
                throw new LogicException("The access-plan snapshot is missing [{$key}].");
            }
        }

        $version = (int) $snapshot['version'];
        if (!in_array($version, self::SUPPORTED_VERSIONS, true)) {
            throw new LogicException('Unsupported access-plan snapshot version.');
        }
        if ($version >= 2) {
            if (!array_key_exists('sort_order', $snapshot) || (int) $snapshot['sort_order'] < 0) {
                throw new LogicException('The access-plan snapshot requires a valid sort order.');
            }
            if (
                !array_key_exists('minimum_paid_coins', $snapshot)
                || !is_numeric($snapshot['minimum_paid_coins'])
                || (int) $snapshot['minimum_paid_coins'] < 0
            ) {
                throw new LogicException('The access-plan snapshot requires a valid paid-coin floor.');
            }
            foreach ([
                'ai_budget_usd',
                'request_reserve_usd',
                'project_feedback_budget_usd',
                'project_feedback_reserve_usd',
            ] as $moneyKey) {
                if (
                    !is_string($snapshot[$moneyKey])
                    || preg_match('/^\d+\.\d{6}$/D', $snapshot[$moneyKey]) !== 1
                ) {
                    throw new LogicException(
                        "Version 2 access-plan money [{$moneyKey}] must use a fixed six-decimal string."
                    );
                }
            }
        }
        if ($version >= 3) {
            foreach ([
                'project_followup_message_limit',
                'project_followup_token_budget',
                'project_followup_budget_usd',
                'project_followup_reserve_usd',
            ] as $key) {
                if (!array_key_exists($key, $snapshot)) {
                    throw new LogicException("The access-plan snapshot is missing [{$key}].");
                }
            }
            foreach (['project_followup_budget_usd', 'project_followup_reserve_usd'] as $moneyKey) {
                if (
                    !is_string($snapshot[$moneyKey])
                    || preg_match('/^\d+\.\d{6}$/D', $snapshot[$moneyKey]) !== 1
                ) {
                    throw new LogicException(
                        "Version 3 access-plan money [{$moneyKey}] must use a fixed six-decimal string."
                    );
                }
            }
            $enhanced = (string) $snapshot['project_feedback_level']
                === CourseAccessPlan::FEEDBACK_ENHANCED;
            $followupIsValid = (int) $snapshot['project_followup_message_limit'] > 0
                && (int) $snapshot['project_followup_token_budget'] >= (int) $snapshot['max_output_tokens']
                && (float) $snapshot['project_followup_budget_usd'] > 0
                && (float) $snapshot['project_followup_reserve_usd'] > 0
                && (float) $snapshot['project_followup_reserve_usd']
                    <= (float) $snapshot['project_followup_budget_usd'];
            $followupIsEmpty = (int) $snapshot['project_followup_message_limit'] === 0
                && (int) $snapshot['project_followup_token_budget'] === 0
                && (float) $snapshot['project_followup_budget_usd'] === 0.0
                && (float) $snapshot['project_followup_reserve_usd'] === 0.0;
            if (($enhanced && !$followupIsValid) || (!$enhanced && !$followupIsEmpty)) {
                throw new LogicException('The access-plan snapshot contains an invalid project follow-up contract.');
            }
        }
        if ($version >= 4) {
            foreach (['chat_attachments_enabled', 'chat_attachment_max_files'] as $key) {
                if (!array_key_exists($key, $snapshot)) {
                    throw new LogicException("The access-plan snapshot is missing [{$key}].");
                }
            }
            $enabled = (bool) $snapshot['chat_attachments_enabled'];
            $maximum = (int) $snapshot['chat_attachment_max_files'];
            if (($enabled && (!$snapshot['chat_enabled'] || $maximum < 1 || $maximum > 5))
                || (!$enabled && $maximum !== 0)) {
                throw new LogicException('The access-plan snapshot contains an invalid attachment contract.');
            }
        }
        if ($version >= 5) {
            foreach (['project_followup_attachments_enabled', 'project_followup_attachment_max_files'] as $key) {
                if (!array_key_exists($key, $snapshot)) {
                    throw new LogicException("The access-plan snapshot is missing [{$key}].");
                }
            }
            $projectEnabled = (bool) $snapshot['project_followup_attachments_enabled'];
            $projectMaximum = (int) $snapshot['project_followup_attachment_max_files'];
            $enhanced = (string) $snapshot['project_feedback_level'] === CourseAccessPlan::FEEDBACK_ENHANCED;
            if (($projectEnabled && (!$enhanced || $projectMaximum < 1 || $projectMaximum > 5))
                || (!$projectEnabled && $projectMaximum !== 0)) {
                throw new LogicException('The access-plan snapshot contains an invalid project attachment contract.');
            }
        }
        if ($version >= 6) {
            if (!array_key_exists('projects_enabled', $snapshot)
                || !is_bool($snapshot['projects_enabled'])) {
                throw new LogicException('The access-plan snapshot requires an explicit project entitlement.');
            }
            if (!$snapshot['projects_enabled'] && (
                (bool) $snapshot['certificate_enabled']
                || (string) $snapshot['project_feedback_level'] !== CourseAccessPlan::FEEDBACK_PASS_ONLY
                || (bool) $snapshot['project_output_enabled']
            )) {
                throw new LogicException('A watch-only contract cannot include projects or a certificate.');
            }
        }
        if ((int) $snapshot['plan_id'] !== $accessPlanId) {
            throw new LogicException('The access-plan snapshot does not match its plan.');
        }
        if (!in_array((string) $snapshot['code'], CourseAccessPlan::CODES, true)) {
            throw new LogicException('The access-plan snapshot contains an unknown plan code.');
        }
        if (trim((string) $snapshot['name_ar']) === '') {
            throw new LogicException('The access-plan snapshot requires a display name.');
        }
        if (!is_numeric($snapshot['price_coins']) || (int) $snapshot['price_coins'] < 0) {
            throw new LogicException('The access-plan snapshot contains an invalid price.');
        }
        if (!in_array(
            (string) $snapshot['project_feedback_level'],
            CourseAccessPlan::PROJECT_FEEDBACK_LEVELS,
            true
        )) {
            throw new LogicException('The access-plan snapshot contains an invalid feedback level.');
        }
        if (trim((string) $snapshot['purchased_at']) === '') {
            throw new LogicException('The access-plan snapshot requires its purchase timestamp.');
        }

        foreach ([
            'chat_message_limit',
            'chat_token_budget',
            'ai_budget_usd',
            'request_reserve_usd',
            'project_feedback_token_budget',
            'project_feedback_budget_usd',
            'project_feedback_reserve_usd',
            'max_output_tokens',
            ...($version >= 3 ? [
                'project_followup_message_limit',
                'project_followup_token_budget',
                'project_followup_budget_usd',
                'project_followup_reserve_usd',
            ] : []),
            ...($version >= 4 ? ['chat_attachment_max_files'] : []),
            ...($version >= 5 ? ['project_followup_attachment_max_files'] : []),
        ] as $numericKey) {
            if (!is_numeric($snapshot[$numericKey]) || (float) $snapshot[$numericKey] < 0) {
                throw new LogicException(
                    "The access-plan snapshot contains an invalid [{$numericKey}] value."
                );
            }
        }

        self::assertFeatureContract($snapshot, $version);
    }

    /** @param array<string,mixed> $snapshot */
    private static function assertFeatureContract(array $snapshot, int $version): void
    {
        $maxOutput = max(0, (int) $snapshot['max_output_tokens']);
        $chatEnabled = (bool) $snapshot['chat_enabled'];
        $chatIsValid = (int) $snapshot['chat_message_limit'] > 0
            && (int) $snapshot['chat_token_budget'] >= $maxOutput
            && (float) $snapshot['ai_budget_usd'] > 0
            && (float) $snapshot['request_reserve_usd'] > 0
            && (float) $snapshot['request_reserve_usd'] <= (float) $snapshot['ai_budget_usd'];
        $chatIsEmpty = (int) $snapshot['chat_message_limit'] === 0
            && (int) $snapshot['chat_token_budget'] === 0
            && (float) $snapshot['ai_budget_usd'] === 0.0
            && (float) $snapshot['request_reserve_usd'] === 0.0;
        if (($chatEnabled && !$chatIsValid) || (!$chatEnabled && !$chatIsEmpty)) {
            throw new LogicException('The access-plan snapshot contains an invalid course-chat contract.');
        }

        $feedbackLevel = (string) $snapshot['project_feedback_level'];
        $reportEnabled = in_array(
            $feedbackLevel,
            [CourseAccessPlan::FEEDBACK_REPORT, CourseAccessPlan::FEEDBACK_ENHANCED],
            true
        );
        $reportIsValid = (int) $snapshot['project_feedback_token_budget'] >= $maxOutput
            && (float) $snapshot['project_feedback_budget_usd'] > 0
            && (float) $snapshot['project_feedback_reserve_usd'] > 0
            && (float) $snapshot['project_feedback_reserve_usd']
                <= (float) $snapshot['project_feedback_budget_usd'];
        $reportIsEmpty = (int) $snapshot['project_feedback_token_budget'] === 0
            && (float) $snapshot['project_feedback_budget_usd'] === 0.0
            && (float) $snapshot['project_feedback_reserve_usd'] === 0.0;
        if (($reportEnabled && !$reportIsValid) || (!$reportEnabled && !$reportIsEmpty)) {
            throw new LogicException('The access-plan snapshot contains an invalid project-report contract.');
        }
        if ((bool) $snapshot['project_output_enabled'] && $feedbackLevel !== CourseAccessPlan::FEEDBACK_ENHANCED) {
            throw new LogicException('Enhanced project output requires the enhanced project contract.');
        }

        if ($version >= 2) {
            $price = max(0, (int) $snapshot['price_coins']);
            $minimumPaid = (int) $snapshot['minimum_paid_coins'];
            if ($minimumPaid > $price) {
                throw new LogicException('The access-plan paid-coin floor exceeds its price.');
            }
            if (($chatEnabled || $reportEnabled) && $minimumPaid <= 0) {
                throw new LogicException('A variable-cost access plan requires a positive paid-coin floor.');
            }
        }
    }

    private function __construct()
    {
    }
}
