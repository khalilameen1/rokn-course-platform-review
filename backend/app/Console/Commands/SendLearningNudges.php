<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\StudentNotificationIntent;

use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\StudentNotificationService;
use App\Services\EngagementMessageService;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Support\BusinessClock;
use App\Support\LearningReminderSchedule;

final class SendLearningNudges extends Command
{
    protected $signature = 'learning:send-nudges
        {--limit=500 : Maximum students loaded per batch}
        {--chunks=1 : Maximum batches per run; later batches run only while the previous one was full}';

    protected $description = 'Send one opt-in learning reminder to inactive enrolled students';

    public function handle(StudentNotificationService $notifications): int
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

        // Same shipped scheduling principle as Loop Habit Tracker: the stored
        // chosen hour owns the next reminder. This is Carbon/Query Builder
        // integration, not a copy of Loop's GPL Kotlin alarm implementation.
        // Filter due recipients BEFORE limit, so another timezone cannot starve
        // the learners whose local reminder window is open.
        // The probe reads only users_learning_reminder_due's leading columns
        // (a covering, loose index scan). It may list a zone whose only users
        // are staff or inactive; the recipient query below still filters them.
        $due = [];
        foreach (DB::table('users')->where('notifications_status', true)->distinct()
            ->pluck('learning_reminder_timezone') as $timezone) {
            $hour = LearningReminderSchedule::dueHour((string) $timezone, $clock);
            if ($hour !== null) $due[] = ['timezone' => (string) $timezone, 'hour' => $hour];
        }
        if ($due === []) {
            $this->info('No learning reminder window is open.');
            return self::SUCCESS;
        }

        $recipients = fn (array $seen) => User::query()
            ->where('role', 'client')
            ->where('active', true)
            ->where('notifications_status', true)
            ->where(function ($query) use ($due): void {
                foreach ($due as $preference) {
                    $query->orWhere(function ($window) use ($preference): void {
                        $window->where('learning_reminder_timezone', $preference['timezone'])
                            ->where('learning_reminder_hour', $preference['hour']);
                    });
                }
            })
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
            // A learner skipped earlier in this run (no playable course or a
            // suppressed delivery) must not be reloaded by the next batch.
            ->when($seen !== [], fn ($query) => $query->whereNotIn('id', $seen))
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

        // Bounded batch loop: the heavy recipient query repeats only while the
        // previous batch was full, so a drained reminder window costs one
        // query per scheduled run while a busy one keeps the same hourly
        // capacity at a lower schedule frequency.
        $chunks = max(1, min(20, (int) $this->option('chunks')));
        $seen = [];
        for ($chunk = 0; $chunk < $chunks; $chunk++) {
            $students = $recipients($seen);
            foreach ($students as $student) {
                $seen[] = (int) $student->id;
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
                    $notification = $notifications->notifyUser(
                        $student,
                        new StudentNotificationIntent(
                            notificationType: 'learning_nudge',
                            titleAr: 'أكمل من مكانك',
                            titleEn: 'Continue learning',
                            messageAr: "{$courseName}\nمقطع واحد يكفي للعودة",
                            messageEn: "Continue {$courseName}",
                            link: '/course/' . $course->id . '/watch',
                            notifiableType: $course::class,
                            notifiableId: (int) $course->id,
                            deliveryKey: 'learning-nudge:' . $student->id . ':' . $course->id . ':' . $deliveryWindow,
                            templateVariables: ['course' => $courseName]
                        )
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
            if ($students->count() < $limit) {
                break;
            }
        }

        $this->info("Sent {$sent} learning nudge(s).");

        return self::SUCCESS;
    }
}
