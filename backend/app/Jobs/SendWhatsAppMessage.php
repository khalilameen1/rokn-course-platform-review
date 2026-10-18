<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

final class SendWhatsAppMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 25;

    public int $uniqueFor = 600;

    public array $backoff = [15, 60, 180];

    public function __construct(
        private readonly string $phone,
        private readonly string $message
    ) {
        $this->onQueue((string) config('queue.channels.notifications', 'notifications'));
    }

    public function uniqueId(): string
    {
        return hash('sha256', $this->phone."\0".$this->message);
    }

    public function handle(WhatsAppService $whatsApp): void
    {
        if (! $whatsApp->sendTextMessage($this->phone, $this->message)) {
            throw new RuntimeException('WhatsApp provider did not accept the message.');
        }
    }
}
