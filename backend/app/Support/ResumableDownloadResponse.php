<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class ResumableDownloadResponse
{
    /** @param array{disk:FilesystemAdapter,disk_name:string,path:string,name:string,mime:string,expires_at:\DateTimeInterface} $file */
    public static function make(array $file): Response
    {
        $driver = strtolower(trim((string) config("filesystems.disks.{$file['disk_name']}.driver")));
        if ($driver !== 'local') {
            $url = $file['disk']->temporaryUrl(
                $file['path'],
                $file['expires_at'],
                [
                    'ResponseContentType' => $file['mime'],
                    'ResponseContentDisposition' => DownloadFilename::disposition($file['name']),
                ]
            );

            return new RedirectResponse($url, 302, [
                'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer',
            ]);
        }

        $response = new BinaryFileResponse(
            $file['disk']->path($file['path']),
            200,
            [
                'Content-Type' => $file['mime'],
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
                'Accept-Ranges' => 'bytes',
            ],
            false,
            null,
            false,
            true
        );
        $response->headers->set(
            'Content-Disposition',
            DownloadFilename::disposition($file['name'])
        );

        return $response;
    }
}
