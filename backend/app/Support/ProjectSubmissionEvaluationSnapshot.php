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
    public const CURRENT_VERSION = 3;
    public const SUPPORTED_VERSIONS = [self::CURRENT_VERSION];

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
        if ($fingerprint === '' || !hash_equals($fingerprint, self::fingerprint($snapshot))) {
            return null;
        }

        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    private static function fingerprint(array $snapshot): string
    {
        unset($snapshot['fingerprint']);

        return hash('sha256', json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    private function __construct()
    {
    }
}
