<?php

declare(strict_types=1);

/** @return resource */
function startLocalHttpFixture(string $readyFile)
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($server === false) {
        fwrite(STDERR, $error);
        exit(1);
    }
    $address = stream_socket_get_name($server, false);
    // Stdout is request telemetry only. Publish startup state atomically so
    // the parent cannot confuse delayed diagnostics with a socket address.
    fwrite(STDOUT, $address.PHP_EOL);
    fflush(STDOUT);
    if (file_put_contents($readyFile.'.tmp', $address, LOCK_EX) === false
        || !rename($readyFile.'.tmp', $readyFile)) {
        fwrite(STDERR, 'Cannot publish local HTTP fixture readiness.');
        fclose($server);
        exit(1);
    }

    return $server;
}
