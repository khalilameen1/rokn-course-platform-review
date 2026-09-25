<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BunnyStoragePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BunnyStoragePathTest extends TestCase
{
    #[DataProvider('paths')]
    public function test_storage_paths_share_one_canonical_policy(string $input, ?string $expected): void
    {
        self::assertSame($expected, BunnyStoragePath::normalize($input, 'assets.example.test'));
    }

    public static function paths(): array
    {
        return [
            ['portfolio/work.jpg', 'portfolio/work.jpg'],
            ['/portfolio/work.jpg', 'portfolio/work.jpg'],
            [' https://assets.example.test/portfolio/work.jpg ', 'portfolio/work.jpg'],
            ['https://ASSETS.example.test/portfolio/work.jpg', 'portfolio/work.jpg'],
            ['https://foreign.example.test/portfolio/work.jpg', null],
            ['http://assets.example.test/portfolio/work.jpg', null],
            ['https://user@assets.example.test/portfolio/work.jpg', null],
            ['https://assets.example.test/portfolio/work.jpg?token=old', null],
            ['https://assets.example.test/portfolio/work.jpg#preview', null],
            ['portfolio/%2e%2e/private.jpg', null],
            ['portfolio/../private.jpg', null],
            ['portfolio\\work.jpg', null],
            ["portfolio/work\0.jpg", null],
            ['', null],
        ];
    }

    public function test_a_full_url_needs_an_explicit_delivery_host_but_a_relative_path_does_not(): void
    {
        self::assertNull(BunnyStoragePath::normalize('https://assets.example.test/work.jpg', null));
        self::assertSame('work.jpg', BunnyStoragePath::normalize('work.jpg', null));
    }
}
