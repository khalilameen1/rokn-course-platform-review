<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessInternalSignal;
use App\Models\InternalSignal;
use App\Models\CourseAuthoringRevision;
use App\Models\Project;
use App\Models\RewardRule;
use App\Support\DatabaseCapabilities;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transactional hand-off for important work that stays inside Rokn.
 *
 * The caller records the signal in the same transaction as its authoritative
 * state change. Queue delivery is only an accelerator; the scheduler owns
 * recovery, so a queue outage can never erase the intent.
 */
final class InternalSignalService
{
    public function __construct(
        private readonly CurriculumCompletionService $curriculumCompletion
    ) {
    }

    public function record(
        string $type,
        string $identity,
        array $payload,
        ?string $aggregateType = null,
        string|int|null $aggregateId = null
    ): InternalSignal {
        $type = trim($type);
        $identity = trim($identity);
        if ($type === '' || $identity === '') {
            throw new \InvalidArgumentException('Internal signals require a type and stable identity.');
        }

        if ($type === 'course.completed') {
            $userId = (int) ($payload['user_id'] ?? 0);
            $courseId = (int) ($payload['course_id'] ?? 0);
            if ($userId > 0 && $courseId > 0) {
                $revision = $this->curriculumCompletion->markCompleted(
                    $userId,
                    $courseId,
                    isset($payload['curriculum_revision'])
                        ? (int) $payload['curriculum_revision']
                        : null
                );
                if ($revision !== null) {
                    $payload['curriculum_revision'] = $revision;
                    // The first earned revision is grandfathered forever. A
                    // caller replaying after a later publication therefore
                    // resolves to the original completion identity.
                    $identity = "user:{$userId}:course:{$courseId}:revision:{$revision}";
                }
            }
        }

        $signalKey = hash('sha256', $type . '|' . $identity);
        if (in_array($type, ['course.completed', 'project.passed.first_reward'], true)) {
            // The reward contract belongs to the accepted achievement, not to
            // whatever an admin edits the rule to before the durable signal is
            // eventually handled. An idempotent replay reuses the winner's
            // snapshot so a later rule edit cannot create a payload conflict.
            $existingPayload = InternalSignal::query()
                ->where('signal_key', $signalKey)
                ->value('payload');
            if (is_string($existingPayload)) {
                $existingPayload = json_decode($existingPayload, true);
            }
            if (is_array($existingPayload)) {
                if (array_key_exists('reward_contract', $existingPayload)) {
                    $payload['reward_contract'] = $existingPayload['reward_contract'];
                }
                if (
                    $type === 'project.passed.first_reward'
                    && array_key_exists('course_id', $existingPayload)
                    && !array_key_exists('course_id', $payload)
                ) {
                    $payload['course_id'] = $existingPayload['course_id'];
                }
            } elseif (!array_key_exists('reward_contract', $payload)) {
                $event = $type === 'course.completed'
                    ? 'course_completed'
                    : 'first_project_passed';
                $payload['reward_contract'] = $this->rewardContract($event);
            }
            if (
                $type === 'project.passed.first_reward'
                && !is_array($existingPayload)
                && !isset($payload['course_id'])
            ) {
                $project = Project::query()->find((int) ($payload['project_id'] ?? 0));
                $course = $project?->course;
                if ($course && DatabaseCapabilities::hasTable('course_authoring_revisions')) {
                    $canonicalId = CourseAuthoringRevision::query()
                        ->where('revision_course_id', $course->id)
                        ->where('status', CourseAuthoringRevision::ARCHIVED)
                        ->latest('id')
                        ->value('canonical_course_id');
                    $payload['course_id'] = (int) ($canonicalId ?: $course->id);
                } elseif ($course) {
                    $payload['course_id'] = (int) $course->id;
                }
            }
        }

        $normalizedPayload = $this->normalize($payload);
        $encoded = json_encode(
            $normalizedPayload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $fingerprint = hash('sha256', $encoded);
        $attributes = [
            'signal_key' => $signalKey,
            'type' => $type,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId === null ? null : (string) $aggregateId,
            'payload_fingerprint' => $fingerprint,
            'payload' => $normalizedPayload,
            'status' => InternalSignal::STATUS_PENDING,
            'available_at' => now(),
        ];

        try {
            $signal = InternalSignal::query()->firstOrCreate(
                ['signal_key' => $signalKey],
                $attributes
            );
        } catch (QueryException $exception) {
            $signal = InternalSignal::query()->where('signal_key', $signalKey)->first();
            if (!$signal) {
                throw $exception;
            }
        }

        if (
            !hash_equals((string) $signal->payload_fingerprint, $fingerprint)
            || !hash_equals((string) $signal->type, $type)
        ) {
            throw new \UnexpectedValueException('Internal signal identity was reused for a different payload.');
        }

        if ($signal->status !== InternalSignal::STATUS_HANDLED) {
            $dispatch = static function () use ($signal): void {
                try {
                    ProcessInternalSignal::dispatch((int) $signal->id, (string) $signal->type)
                        ->onQueue(ProcessInternalSignal::queueForType((string) $signal->type));
                    InternalSignal::query()
                        ->whereKey($signal->id)
                        ->where('status', InternalSignal::STATUS_PENDING)
                        ->update(['dispatched_at' => now(), 'updated_at' => now()]);
                } catch (\Throwable $exception) {
                    // The row is the hand-off. Queue dispatch is only a fast
                    // path and must not turn the committed user action into a
                    // false failure; the scheduler will pick it up.
                    Log::warning('Immediate internal signal dispatch failed.', [
                        'signal_id' => $signal->id,
                        'signal_type' => $signal->type,
                        'exception' => $exception::class,
                    ]);
                }
            };
            DB::transactionLevel() > 0 ? DB::afterCommit($dispatch) : $dispatch();
        }

        return $signal;
    }

    private function normalize(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalize($value);
            }
        }

        return $payload;
    }

    /** @return array<string,int|null> */
    private function rewardContract(string $event): array
    {
        $rule = RewardRule::activeFor($event);
        if (!$rule) {
            return [
                'rule_id' => 0,
                'coins_amount' => 0,
                'interval_count' => 1,
                'daily_cap' => null,
                'rolling_30_day_cap' => 0,
            ];
        }

        return [
            'rule_id' => (int) $rule->id,
            'coins_amount' => max(0, (int) $rule->coins_amount),
            'interval_count' => max(1, (int) $rule->interval_count),
            'daily_cap' => $rule->daily_cap === null ? null : max(0, (int) $rule->daily_cap),
            'rolling_30_day_cap' => max(
                0,
                (int) ($rule->rolling_30_day_cap ?? $rule->coins_amount)
            ),
        ];
    }
}
