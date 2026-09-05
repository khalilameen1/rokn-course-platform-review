<?php

declare(strict_types=1);

// One local request per process. No credentials, network dependency or app boot.
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error);
    exit(1);
}
fwrite(STDOUT, stream_socket_get_name($server, false).PHP_EOL);
fflush(STDOUT);
$client = stream_socket_accept($server, 5);
if ($client === false) {
    exit(2);
}
stream_set_timeout($client, 5);
$request = '';
while (!str_contains($request, "\r\n\r\n")) {
    $part = fread($client, 8192);
    if ($part === false || $part === '') {
        exit(3);
    }
    $request .= $part;
}
[$headers, $body] = explode("\r\n\r\n", $request, 2);
preg_match('/Content-Length:\s*(\d+)/i', $headers, $match);
$remaining = (int) ($match[1] ?? 0) - strlen($body);
while ($remaining > 0) {
    $part = fread($client, $remaining);
    if ($part === false || $part === '') {
        exit(4);
    }
    $remaining -= strlen($part);
}
$scenario = $argv[1];
fwrite(STDOUT, 'REQUEST'.PHP_EOL);
fflush(STDOUT);
$send = static function (string $bytes) use ($client): void {
    @fwrite($client, $bytes);
    @fflush($client);
};
$event = static function (array $payload) use ($send): void {
    $send('data: '.json_encode($payload, JSON_THROW_ON_ERROR)."\n\n");
};
if ($scenario === 'slow_headers') {
    usleep(7_000_000);
}
if ($scenario === 'headers_then_silence') {
    usleep(2_000_000);
}
if ($scenario === 'json_fallback') {
    $send("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n");
    $send(json_encode(['id' => 'local-json', 'choices' => [[
        'message' => ['content' => 'Buffered JSON answer'],
    ]], 'usage' => ['cost' => 0.01]], JSON_THROW_ON_ERROR));
    fclose($client);
    fclose($server);
    exit(0);
}
if ($scenario === 'redirect') {
    $location = 'http://'.stream_socket_get_name($server, false).'/second-generation';
    $send("HTTP/1.1 307 Temporary Redirect\r\nLocation: {$location}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($client);
    fclose($server);
    exit(0);
}
if ($scenario === 'http_error') {
    $send("HTTP/1.1 429 Too Many Requests\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n");
    $send('{"error":{"code":"429","message":"Local rejection"}}');
    fclose($client);
    fclose($server);
    exit(0);
}
$send("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nConnection: close\r\n\r\n");
if ($scenario === 'error_before_content') {
    $event(['error' => ['code' => 429, 'message' => 'Local rejection']]);
    fclose($client);
    fclose($server);
    exit(0);
}
$event(['id' => 'local-generation', 'choices' => [[
    'delta' => ['content' => 'First small fragment'], 'finish_reason' => null,
]]]);
if (in_array($scenario, ['error_after_content', 'malformed_after_content'], true)) {
    if ($scenario === 'error_after_content') {
        $event(['error' => ['code' => 429, 'message' => 'Local interruption']]);
    } else {
        $send("data: {broken json}\n\n");
    }
    fclose($client);
    fclose($server);
    exit(0);
}
if ($scenario === 'interrupted') {
    usleep(300_000);
    fclose($client);
    fclose($server);
    exit(0);
}
if ($scenario === 'silent_body') {
    usleep(7_000_000);
}
if ($scenario === 'headers_then_silence') {
    usleep(7_000_000);
}
$ticks = $scenario === 'drip' ? 14 : 2;
for ($i = 0; $i < $ticks; ++$i) {
    usleep(500_000);
    $send(": heartbeat\n\n");
}
$event(['choices' => [[
    'delta' => ['content' => ' and final answer'], 'finish_reason' => 'stop',
    'annotations' => [],
]], 'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 3, 'total_tokens' => 5, 'cost' => 0.012]]);
$send("data: [DONE]\n\n");
if ($scenario === 'done_keep_alive') {
    usleep(7_000_000);
}
fclose($client);
fclose($server);
