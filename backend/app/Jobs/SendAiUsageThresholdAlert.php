<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class SendAiUsageThresholdAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public array $backoff = [15, 60, 180];

    public function __construct(
        public string $metric,
        public string $period,
        public int $actual,
        public int $threshold,
        public ?int $adminId = null
    ) {
        $this->onQueue((string) config('queue.channels.operations', 'operations'));
    }

    public function handle(): void
    {
        $labels = [
            'daily_requests' => 'طلبات OpenRouter اليومية',
            'daily_tokens' => 'توكنز OpenRouter اليومية',
            'monthly_tokens' => 'توكنز OpenRouter الشهرية',
            'unanswered_provider_requests' => 'طلبات OpenRouter بلا نتيجة مؤكدة',
        ];
        $label = $labels[$this->metric] ?? $this->metric;
        $body = $this->metric === 'unanswered_provider_requests'
            ? "تكررت نتائج OpenRouter غير المؤكدة لحساب واحد\n"
                ."الاشتراك: {$this->period}\n"
                ."الطلبات داخل النافذة: {$this->actual}\n"
                ."حد الحماية: {$this->threshold}\n"
                ."توقف Rokn AI مؤقتًا لهذا الاشتراك فقط\n"
                .'سيعود تلقائيًا بعد مدة التهدئة'
            : "تجاوز استخدام ركن حد التنبيه\n"
                ."المؤشر: {$label}\n"
                ."الفترة: {$this->period}\n"
                ."الحالي: {$this->actual}\n"
                ."حد التنبيه: {$this->threshold}\n"
                ."لم تتوقف الخدمة تلقائيًا\nراجع تقرير التكلفة والطلاب الأعلى استهلاكًا";

        Log::warning('AI platform usage crossed an operations threshold.', [
            'metric' => $this->metric,
            'period' => $this->period,
            'actual' => $this->actual,
            'threshold' => $this->threshold,
        ]);

        User::query()
            ->where('role', 'admin')
            ->where('active', true)
            ->whereNotNull('email')
            ->when($this->adminId, fn ($query) => $query->whereKey($this->adminId))
            ->pluck('email')
            ->filter()
            ->unique()
            ->each(function ($email) use ($body): void {
                $messageId = hash('sha256', implode('|', [
                    'ai-usage-threshold',
                    $this->metric,
                    $this->period,
                    (string) $this->threshold,
                    (string) $email,
                ])).'@rokn.app';
                Mail::raw(
                    $body,
                    static function ($message) use ($email, $messageId): void {
                        $message->to((string) $email)
                            ->subject('تنبيه استهلاك OpenRouter من ركن');
                        $message->getSymfonyMessage()
                            ->getHeaders()
                            ->addIdHeader('Message-ID', $messageId);
                    }
                );
            });
    }
}
