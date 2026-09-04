<?php

declare(strict_types=1);

namespace App\Services;

final readonly class KashierConfigurationService
{
    /** @return array{mode:string,api_key:string,secret_key:string,mid:string,base_url:string} */
    public function get(): array
    {
        $mode = strtolower(trim((string) config('kashier.mode')));
        if (!in_array($mode, ['live', 'test'], true)) {
            throw new \RuntimeException('KASHIER_MODE must be explicitly set to live or test.');
        }

        $prefix = $mode === 'live' ? 'KASHIER_LIVE' : 'KASHIER_TEST';
        $apiKey = trim((string) config("kashier.{$mode}.api_key"));
        $secretKey = trim((string) config("kashier.{$mode}.secret_key"));
        $mid = trim((string) config("kashier.{$mode}.mid"));
        $baseUrl = trim((string) config("kashier.{$mode}.base_url"));
        $missing = [];

        if ($apiKey === '') {
            $missing[] = $prefix . '_API_KEY';
        }
        if ($secretKey === '') {
            $missing[] = $prefix . '_SECRET_KEY';
        }
        if ($mid === '') {
            $missing[] = $prefix . '_MID';
        }
        if ($baseUrl === '') {
            $missing[] = 'Kashier checkout base URL';
        }
        if ($missing !== []) {
            throw new \RuntimeException('Missing Kashier configuration: ' . implode(', ', $missing));
        }

        return [
            'mode' => $mode,
            'api_key' => $apiKey,
            'secret_key' => $secretKey,
            'mid' => $mid,
            'base_url' => $baseUrl,
        ];
    }
}
