<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BunnyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BunnyVideoStatusTest extends TestCase
{
    #[DataProvider('videoStatuses')]
    public function test_get_video_model_status_contract(
        int $status,
        bool $playable,
        bool $failure,
        bool $uploaded
    ): void {
        self::assertSame($playable, BunnyService::providerVideoStatusIsPlayable($status));
        self::assertSame($failure, BunnyService::providerVideoStatusIsFailure($status));
        self::assertSame($uploaded, BunnyService::providerVideoStatusConfirmsUpload($status));
    }

    public static function videoStatuses(): array
    {
        return [
            'created' => [0, false, false, false],
            'uploaded' => [1, false, false, true],
            'processing' => [2, false, false, true],
            'transcoding, not webhook finished' => [3, false, false, true],
            'finished' => [4, true, false, true],
            'error' => [5, false, true, false],
            'upload failed, not webhook upload started' => [6, false, true, false],
            'JIT segmenting' => [7, false, false, true],
            'JIT playlists created, not webhook upload failed' => [8, false, false, true],
            'webhook captions generated is not a video status' => [9, false, false, false],
            'webhook title generated is not a video status' => [10, false, false, false],
            'missing' => [-1, false, false, false],
            'unknown' => [99, false, false, false],
        ];
    }
}
