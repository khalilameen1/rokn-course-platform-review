<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OperationalIncident;
use App\Models\User;
use App\Services\OperationalRuntimeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class MonitorOperationalRuntime extends Command
{
    protected $signature = 'ops:monitor-runtime';

    protected $description = 'Detect, deduplicate and escalate silent runtime failures';

    public function handle(OperationalRuntimeService $runtime): int
    {
        try {
            $result = $runtime->reconcileIncidents();
        } catch (Throwable $exception) {
            Log::critical('Operational runtime monitor could not inspect the platform.', [
                'exception' => $exception::class,
                'error_fingerprint' => hash('sha256', $exception->getMessage()),
            ]);

            return self::FAILURE;
        }

        foreach ($result['alerts'] as $incident) {
            Log::log($incident->severity === 'critical' ? 'critical' : 'warning', $incident->summary, [
                'incident_code' => $incident->code,
                'category' => $incident->category,
                'affected_count' => $incident->affected_count,
                'first_seen_at' => $incident->first_seen_at?->toIso8601String(),
            ]);
        }
        foreach ($result['resolved'] as $incident) {
            Log::info('Operational incident resolved.', [
                'incident_code' => $incident->code,
                'affected_count' => $incident->affected_count,
            ]);
        }

        if ($result['alerts'] !== []) {
            $this->mailAlerts($result['alerts']);
        }

        return self::SUCCESS;
    }

    /** @param list<OperationalIncident> $alerts */
    private function mailAlerts(array $alerts): void
    {
        $recipients = User::query()
            ->where('role', 'admin')
            ->where('active', true)
            ->whereNotNull('email')
            ->pluck('email')
            ->filter()
            ->unique();
        if ($recipients->isEmpty()) {
            return;
        }

        $body = "ركن رصد عطلًا تشغيليًا قبل وصوله للطلاب\n\n";
        foreach ($alerts as $incident) {
            $body .= sprintf(
                "[%s] %s\nالمتأثرون أو العناصر: %d\nالرمز: %s\n\n",
                $incident->severity === 'critical' ? 'حرج' : 'تنبيه',
                $incident->summary,
                $incident->affected_count,
                $incident->code
            );
        }
        $body .= 'افتح مركز تشغيل المنتج للتفاصيل الآمنة والإجراء المناسب';

        try {
            foreach ($recipients as $email) {
                Mail::raw(
                    $body,
                    static fn ($message) => $message
                        ->to((string) $email)
                        ->subject('تنبيه تشغيل ركن')
                );
            }
        } catch (Throwable $exception) {
            // Logging remains the independent alert path if SMTP itself is the
            // incident. The persisted row keeps the dashboard authoritative.
            Log::error('Operational alert email could not be sent.', [
                'exception' => $exception::class,
                'error_fingerprint' => hash('sha256', $exception->getMessage()),
            ]);
        }
    }
}
