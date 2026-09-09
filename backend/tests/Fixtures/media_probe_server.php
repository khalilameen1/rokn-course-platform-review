<?php

declare(strict_types=1);

// Local HTTP transport fixture: no app boot, credentials or external requests.
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}
fwrite(STDOUT, stream_socket_get_name($server, false).PHP_EOL);
fflush(STDOUT);

for ($index = 0; $index < (int) $argv[1]; $index++) {
    $client = stream_socket_accept($server, 12);
    if ($client === false) {
        exit(2);
    }
    stream_set_timeout($client, 5);
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($client, 8192);
        if ($chunk === false || $chunk === '') {
            exit(3);
        }
        $request .= $chunk;
    }
    [$method, $path] = explode(' ', $request, 3);
    preg_match('/\r\nRange:\s*([^\r\n]+)/i', $request, $range);
    $fallback = str_starts_with($path, '/image-fallback-');
    $status = $method === 'HEAD' && $fallback ? (int) substr($path, -3) : 200;
    $image = str_starts_with($path, '/image-');
    $large = ($image && $method === 'GET') || str_contains($path, '-large');
    $stalled = $path === '/manifest-stalled-prefix';
    $valid = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000\n720p/playlist.m3u8\n";
    $prefix = $image ? str_repeat('i', 8193) : $valid;
    if ($path === '/manifest-exact-limit') {
        $prefix = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000\n";
        $prefix .= str_repeat('a', 8192 - strlen($prefix));
    }
    if ($stalled) {
        $prefix = "#EXTM3U\n";
    }
    if ($large && !$image) {
        $prefix = str_contains($path, 'invalid')
            ? str_pad('<!doctype html>not a playlist', 8193, ' ')
            : substr($valid.str_repeat("# padding\n", 900), 0, 8193);
    }
    $initial = $method === 'HEAD' ? '' : $prefix;
    $encoding = '';
    if ($path === '/manifest-gzip') {
        $initial = gzencode($initial);
        $encoding = "Content-Encoding: gzip\r\n";
    }
    $tailLength = $large || $stalled ? 1024 * 1024 : 0;
    $length = $method === 'HEAD' ? 0 : strlen($initial) + $tailLength;
    $type = $image ? 'image/jpeg' : 'application/vnd.apple.mpegurl';
    fwrite($client, "HTTP/1.1 {$status} Fixture\r\nContent-Type: {$type}\r\n{$encoding}Content-Length: {$length}\r\nConnection: close\r\n\r\n");
    if ($path === '/manifest-fragmented') {
        fwrite($client, substr($initial, 0, -12));
        fflush($client);
        usleep(80_000);
        fwrite($client, substr($initial, -12));
    } else {
        fwrite($client, $initial);
    }
    fflush($client);
    $tailBytes = 0;
    if ($large || $stalled) {
        // A bounded reader should disconnect while the remaining body stalls.
        // The old buffered request waits, then consumes this entire 1 MiB tail.
        stream_set_blocking($client, false);
        $deadline = microtime(true) + ($stalled ? 12 : 2);
        $nextFragment = microtime(true) + 3;
        while (microtime(true) < $deadline && !feof($client)) {
            fread($client, 1);
            if ($stalled && microtime(true) >= $nextFragment) {
                // New bytes must not renew the probe's overall read budget.
                @fwrite($client, ' ');
                @fflush($client);
                $nextFragment += 3;
            }
            usleep(10_000);
        }
        if (!feof($client)) {
            stream_set_blocking($client, true);
            $tail = str_repeat('x', $tailLength);
            while ($tailBytes < $tailLength) {
                $written = @fwrite($client, substr($tail, $tailBytes));
                if (!$written) {
                    break;
                }
                $tailBytes += $written;
            }
            @fflush($client);
        }
    }
    fclose($client);
    fwrite(STDOUT, json_encode([
        'method' => $method,
        'path' => $path,
        'status' => $status,
        'range' => $range[1] ?? null,
        'tail_bytes' => $tailBytes,
    ], JSON_THROW_ON_ERROR).PHP_EOL);
    fflush(STDOUT);
}
fclose($server);
