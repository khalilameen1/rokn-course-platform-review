<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\HeaderUtils;

final class DownloadFilename
{
    public static function safe(
        mixed $value,
        string $fallback = 'rokn-file',
        ?string $requiredExtension = null
    ): string {
        $name = basename(str_replace('\\', '/', UnicodeText::clean($value, false)));
        $name = preg_replace('/[^\p{L}\p{M}\p{N}\p{S}._ -]+/u', '_', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");
        $name = UnicodeText::limit($name, 180);
        if ($name === '') {
            $name = $fallback;
        }

        $extension = strtolower(trim((string) $requiredExtension, ". \t\n\r\0\x0B"));
        $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : '';
        if ($extension !== '' && ! str_ends_with(strtolower($name), '.'.$extension)) {
            $name = rtrim($name, '.').'.'.$extension;
        }

        return $name;
    }

    public static function disposition(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $fallback = 'rokn-file'.($extension !== '' ? '.'.strtolower($extension) : '');

        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            $fallback
        );
    }
}
