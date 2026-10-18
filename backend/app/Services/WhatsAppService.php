<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

final class WhatsAppService
{
    private string $apiUrl;

    private string $apiKey;

    private string $device;

    public function __construct()
    {
        $this->apiUrl = trim((string) config('whatsapp.whatspie.api_url'));
        $this->apiKey = trim((string) config('whatsapp.whatspie.api_key'));
        $this->device = trim((string) config('whatsapp.whatspie.device'));
    }

    /**
     * Send a message via WhatsApp using Whatspie API
     */
    public function sendTextMessage(string $phone, string $message): bool
    {
        return $this->sendMessage($phone, $message);
    }

    public static function generateCode(): string
    {
        $length = max(4, min(10, (int) config('whatsapp.verification.code_length', 6)));
        $upperBound = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $upperBound), $length, '0', STR_PAD_LEFT);
    }

    protected function sendMessage(string $phone, string $message): bool
    {
        $formattedPhone = (string) preg_replace('/\D+/', '', $phone);

        $data = [
            'device' => $this->device,
            'receiver' => $formattedPhone,
            'type' => 'chat',
            'params' => [
                'text' => $message,
            ],
            'simulate_typing' => 1,
        ];

        if ($this->apiUrl === '' || $this->apiKey === '' || $this->device === '') {
            Log::warning('WhatsApp delivery skipped because the provider is not configured');

            return false;
        }

        $curl = null;
        try {
            $payload = json_encode($data, JSON_THROW_ON_ERROR);
            $curl = curl_init();
            if ($curl === false) {
                Log::error('WhatsApp API client initialization failed');

                return false;
            }

            curl_setopt_array($curl, [
                CURLOPT_URL => $this->apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_CONNECTTIMEOUT => max(
                    1,
                    (int) config('whatsapp.whatspie.connect_timeout_seconds', 5)
                ),
                CURLOPT_TIMEOUT => max(1, (int) config('whatsapp.whatspie.timeout_seconds', 15)),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$this->apiKey,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
            ]);

            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

            if ($response === false || curl_errno($curl) !== 0) {
                Log::error('WhatsApp API request failed');

                return false;
            }

            if ($status < 200 || $status >= 300) {
                Log::warning('WhatsApp API rejected a message', ['status' => $status]);

                return false;
            }

            Log::info('WhatsApp message sent');

            return true;
        } catch (Throwable $exception) {
            Log::error('WhatsApp API exception', ['exception' => $exception::class]);

            return false;
        } finally {
            if ($curl !== null && $curl !== false) {
                curl_close($curl);
            }
        }
    }
}
