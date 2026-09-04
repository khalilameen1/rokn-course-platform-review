<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\StudentNotificationService;
use App\Services\EngagementMessageService;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Support\BusinessClock;

final class SendLearningNudges extends Command
{
    protected $signature = 'learning:send-nudges {--limit=500 : Maximum students to nudge in this run}';

    protected $description = 'Send one opt-in learning reminder to inactive enrolled students';

    public function handle(): int
    {
        $clock = BusinessClock::utcNow();
        $template = app(EngagementMessageService::class)->publicMessage('learning_nudge');
        if (!$template) {
            $this->info('Learning nudges are disabled.');
            return self::SUCCESS;
        }
        $cooldownHours = max(1, (int) ($template['cooldown_hours'] ?? 24));
        $cooldownBoundary = $clock->copy()->subHours($cooldownHours);
        $cooldownFamily = NotificationDeliveryPolicy::cooldownFamily('learning_nudge');
        $deliveryWindow = (string) floor($clock->timestamp / ($cooldownHours * 3600));
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $sent = 0;

        $students = User::query()
            ->where('role', 'client')
            ->where('active', true)
            ->where('notifications_status', true)
            ->whereHas('enrollments', function ($query): void {
                $query->where('is_active', true)
                    // Completion is an earned immutable fact. A retention
                    // campaign must not tell a learner to continue a course
                    // they have already finished.
                    ->whereNull('completed_curriculum_revision')
                    ->where(function ($expires): void {
                        $expires->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            })
            ->whereDoesntHave('sectionProgress', function ($query) use ($cooldownBoundary): void {
                $query->where('is_completed', true)->where('updated_at', '>=', $cooldownBoundary);
            })
            ->whereDoesntHave('lessonWatchEvidence', function ($query) use ($cooldownBoundary): void {
                // A heartbeat is the authoritative activity signal even when
                // the learner disabled optional watch-history/resume storage.
                $query->where('last_heartbeat_at', '>=', $cooldownBoundary);
            })
            ->whereDoesntHave('studentNotifications', function ($query) use ($cooldownBoundary, $cooldownFamily): void {
                $query->whereIn('notification_type', $cooldownFamily)
                    ->where('created_at', '>=', $cooldownBoundary);
            })
            ->where(function ($query) use ($cooldownBoundary): void {
                $query->whereNull('last_learning_nudge_at')
                    ->orWhere('last_learning_nudge_at', '<=', $cooldownBoundary);
            })
            ->with(['enrollments' => function ($query): void {
                $query->where('is_active', true)
                    ->whereNull('completed_curriculum_revision')
                    ->where(function ($expires): void {
                        $expires->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->with('course')
                    ->orderByDesc('access_granted_at')
                    ->orderByDesc('enrolled_at');
            }])
            // Least-recently-notified first prevents the same low IDs from
            // consuming the daily limit forever as the audience grows.
            ->orderByRaw('CASE WHEN last_learning_nudge_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_learning_nudge_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($students as $student) {
            /** @var CourseEnrollment|null $enrollment */
            // A newer enrollment can be a draft/retired child while an older
            // purchased course remains playable. Do not skip the learner or
            // deep-link a non-public graph merely because that unusable row
            // sorted first.
            $enrollment = $student->enrollments->first(
                fn (CourseEnrollment $candidate): bool =>
                    $candidate->course?->isPublishedForLearning()
            );
            $course = $enrollment?->course;
            if (!$course) {
                continue;
            }

            $courseName = (string) ($course->name_ar ?: $course->name_en ?: 'كورس ركن');
            try {
                $notification = StudentNotificationService::notifyUser(
                    $student,
                    'learning_nudge',
                    'أكمل من مكانك',
                    'Continue learning',
                    "{$courseName}\nمقطع واحد يكفي للعودة",
                    "Continue {$courseName}",
                    '/course/' . $course->id . '/watch',
                    $course::class,
                    (int) $course->id,
                    'learning-nudge:' . $student->id . ':' . $course->id . ':' . $deliveryWindow,
                    ['course' => $courseName]
                );
                if (!$notification) {
                    continue;
                }
                $student->forceFill(['last_learning_nudge_at' => now()])->save();
                $sent++;
            } catch (\Throwable $exception) {
                Log::warning('Learning nudge could not be queued', [
                    'user_id' => $student->id,
                    'course_id' => $course->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Sent {$sent} learning nudge(s).");

        return self::SUCCESS;
    }
}
