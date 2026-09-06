<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CourseEnrollment;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use LogicException;

/** Immutable project and entitlement facts used by delayed review jobs. */
final class ProjectSubmissionEvaluationSnapshot
{
    public const CURRENT_VERSION = 4;
    public const SUPPORTED_VERSIONS = [3, self::CURRENT_VERSION];

    /** @param array<string,mixed>|null $accessTerms */
    public static function capture(
        Project $project,
        ?CourseSection $section,
        ?CourseEnrollment $enrollment,
        ?array $accessTerms
    ): array {
        $course = $section?->course;
        $snapshot = [
            'version' => self::CURRENT_VERSION,
            'captured_at' => now()->toIso8601String(),
            'course_id' => $section ? (int) $section->course_id : null,
            'section_id' => $section ? (int) $section->id : null,
            'course' => [
                'id' => $course ? (int) $course->id : null,
                'title_ar' => $course?->getRawOriginal('name_ar'),
                'title_en' => $course?->getRawOriginal('name_en'),
            ],
            'project' => [
                'id' => (int) $project->id,
                // A project's learner-visible title belongs to its section.
                // Freeze every stored locale instead of resolving it using
                // the queue worker's request locale later.
                'title' => $section?->getRawOriginal('title'),
                'title_ar' => $section?->getRawOriginal('title_ar'),
                'title_en' => $section?->getRawOriginal('title_en'),
                'updated_at' => $project->updated_at?->toIso8601String(),
                'requirements_text' => (string) $project->requirements_text,
                'requirements_text_ar' => $project->getRawOriginal('requirements_text_ar'),
                'requirements_text_en' => $project->getRawOriginal('requirements_text_en'),
            ],
            'access' => [
                'enrollment_id' => $enrollment ? (int) $enrollment->id : null,
                'access_plan_id' => $enrollment?->access_plan_id
                    ? (int) $enrollment->access_plan_id
                    : null,
                'terms' => $accessTerms,
            ],
        ];
        $snapshot['fingerprint'] = self::fingerprint($snapshot);

        return $snapshot;
    }

    /** Reject any row that does not carry the current immutable contract. */
    public static function fromSubmission(ProjectSubmission $submission): ?array
    {
        $snapshot = $submission->evaluation_snapshot;
        if (!is_array($snapshot)) {
            return null;
        }
        $version = (int) ($snapshot['version'] ?? 0);
        if (!in_array($version, self::SUPPORTED_VERSIONS, true)) {
            return null;
        }
        if ((int) data_get($snapshot, 'project.id') !== (int) $submission->project_id) {
            return null;
        }
        $contextIds = [
            (int) ($snapshot['course_id'] ?? 0),
            (int) ($snapshot['section_id'] ?? 0),
            (int) data_get($snapshot, 'access.enrollment_id', 0),
        ];
        $hasCompleteContext = $contextIds[0] > 0
            && $contextIds[1] > 0
            && $contextIds[2] > 0;
        $hasNoContext = $contextIds[0] === 0
            && $contextIds[1] === 0
            && $contextIds[2] === 0;
        // Standalone service-level submissions are allowed only when all
        // course/enrollment references are absent. Partial context is unsafe.
        if (!$hasCompleteContext && !$hasNoContext) {
            return null;
        }
        $requiredProjectKeys = ['requirements_text'];
        foreach ($requiredProjectKeys as $key) {
            if (!array_key_exists($key, (array) ($snapshot['project'] ?? []))) {
                return null;
            }
        }
        if ($version >= 2) {
            foreach (['id', 'title_ar', 'title_en'] as $key) {
                if (!array_key_exists($key, (array) ($snapshot['course'] ?? []))) {
                    return null;
                }
            }
            foreach (['title', 'title_ar', 'title_en'] as $key) {
                if (!array_key_exists($key, (array) ($snapshot['project'] ?? []))) {
                    return null;
                }
            }
            if ((int) data_get($snapshot, 'course.id') !== (int) ($snapshot['course_id'] ?? 0)) {
                return null;
            }
        }
        $planId = data_get($snapshot, 'access.access_plan_id');
        $terms = data_get($snapshot, 'access.terms');
        if ($planId !== null) {
            try {
                CourseAccessPlanSnapshot::assertValidForPlan(
                    (int) $planId,
                    is_array($terms) ? $terms : null
                );
            } catch (LogicException) {
                return null;
            }
        }
        $fingerprint = trim((string) ($snapshot['fingerprint'] ?? ''));
        if ($fingerprint === '' || (!hash_equals($fingerprint, self::fingerprint($snapshot))
            && !($version === 3 && self::matchesLegacyFingerprint($snapshot, $fingerprint)))) {
            return null;
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    private static function fingerprint(array $snapshot): string
    {
        unset($snapshot['fingerprint']);
        if ((int) ($snapshot['version'] ?? 0) >= 4) {
            $snapshot = self::canonicalObjects($snapshot);
        }

        return hash('sha256', json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /** Object key order is not a JSON fact; list item order still is. */
    private static function canonicalObjects(array $value): array
    {
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::canonicalObjects($child);
            }
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    /**
     * V3 signed PHP insertion order, which MySQL JSON does not preserve.
     * Reconstruct only the historical writer's known layouts and compare to
     * the ORIGINAL digest. Never replace a digest or consult mutable live data.
     */
    private static function matchesLegacyFingerprint(array $snapshot, string $fingerprint): bool
    {
        $ordered = self::inWriterOrder($snapshot, [
            'version', 'captured_at', 'course_id', 'section_id', 'course', 'project', 'access', 'fingerprint',
        ]);
        foreach ([
            'course' => ['id', 'title_ar', 'title_en'],
            'project' => ['id', 'title', 'title_ar', 'title_en', 'updated_at',
                'requirements_text', 'requirements_text_ar', 'requirements_text_en'],
            'access' => ['enrollment_id', 'access_plan_id', 'terms'],
        ] as $key => $keys) {
            if (!is_array($ordered[$key] ?? null)) {
                return false;
            }
            $ordered[$key] = self::inWriterOrder($ordered[$key], $keys);
        }
        // Production capture normally read terms from an already-persisted
        // enrollment, so their current MySQL JSON ordering is the writer order.
        if (hash_equals($fingerprint, self::fingerprint($ordered))) {
            return true;
        }
        if (!is_array($ordered['access']['terms'] ?? null)) {
            return false;
        }
        // Capture can also receive a freshly-created CourseAccessPlanService
        // receipt before its first database round trip (including SQLite).
        $ordered['access']['terms'] = self::inWriterOrder($ordered['access']['terms'], [
            'version', 'plan_id', 'code', 'name_ar', 'price_coins', 'minimum_paid_coins', 'sort_order',
            'chat_enabled', 'chat_message_limit', 'chat_token_budget',
            'chat_attachments_enabled', 'chat_attachment_max_files',
            'project_followup_attachments_enabled', 'project_followup_attachment_max_files',
            'ai_budget_usd', 'request_reserve_usd', 'project_feedback_token_budget',
            'project_feedback_budget_usd', 'project_feedback_reserve_usd',
            'project_followup_message_limit', 'project_followup_token_budget',
            'project_followup_budget_usd', 'project_followup_reserve_usd',
            'max_output_tokens', 'model_override', 'project_feedback_level',
            'project_output_enabled', 'certificate_enabled', 'purchased_at',
        ]);
        return hash_equals($fingerprint, self::fingerprint($ordered));
    }

    private static function inWriterOrder(array $value, array $keys): array
    {
        $ordered = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                $ordered[$key] = $value[$key];
            }
        }
        // Keep unknown fields in the digest: dropping one would conceal tampering.
        return $ordered + $value;
    }

    private function __construct()
    {
    }
}
