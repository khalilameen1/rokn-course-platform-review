<?php

declare(strict_types=1);

namespace App\Support;

final class StorageWriteOptions
{
    /**
     * R2/S3 bucket policy owns visibility. Per-object ACL headers are rejected
     * by Laravel Cloud R2, while local disks may still need an explicit mode.
     *
     * @return array<string,string>
     */
    public static function forDisk(string $disk, string $localVisibility): array
    {
        $config = config("filesystems.disks.{$disk}");
        if (! is_array($config)) {
            return [];
        }

        return strtolower((string) ($config['driver'] ?? '')) === 's3'
            ? []
            : ['visibility' => $localVisibility];
    }
}
