<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FinancialAnomaly;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

final class SendFinancialAnomalyAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public array $backoff = [15, 60, 180];

    public function __construct(
        public int $anomalyId,
        public ?int $adminId = null,
        public ?string $occurrence = null
    ) {
        $this->onQueue((string) config('queue.channels.operations', 'operations'));
    }

    public function handle(): void
    {
        $anomaly = FinancialAnomaly::query()
            ->with(['user:id,name,email', 'course:id,name_ar,name_en'])
            ->find($this->anomalyId);
        if (! $anomaly || $anomaly->status !== FinancialAnomaly::STATUS_OPEN) {
            return;
        }

        $recipients = User::query()
            ->where('role', 'admin')
            ->where('active', true)
            ->whereNotNull('email')
            ->when($this->adminId, fn ($query) => $query->whereKey($this->adminId))
            ->pluck('email')
            ->filter()
            ->unique();
        foreach ($recipients as $email) {
            $messageId = hash(
                'sha256',
                'financial-anomaly|'.$anomaly->public_id.'|'
                    .(string) $this->occurrence.'|'.(string) $email
            ).'@rokn.app';
            Mail::raw(
                "تم إيقاف AI لهذه الفئة على حساب الطالب فقط لحين المراجعة\n"
                .'الطالب: '.($anomaly->user?->email ?: '#'.$anomaly->user_id)."\n"
                .'الكورس: '.($anomaly->course?->name_ar ?: $anomaly->course?->name_en)."\n"
                .'المفروض مدفوع: '.$anomaly->expected_paid_coins." عملة\n"
                .'الفعلي المدفوع: '.$anomaly->actual_paid_coins." عملة\n"
                .'رقم التنبيه: '.$anomaly->public_id,
                static function ($message) use ($email, $messageId): void {
                    $message->to((string) $email)
                        ->subject('تنبيه مالي من ركن: شراء أقل من الحد المدفوع');
                    $message->getSymfonyMessage()
                        ->getHeaders()
                        ->addIdHeader('Message-ID', $messageId);
                }
            );
        }
    }
}
