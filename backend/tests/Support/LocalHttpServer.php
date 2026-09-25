<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Owns fixture startup and cleanup, independently of HTTP timing assertions. */
final class LocalHttpServer
{
    private string $directory;
    private string $address;
    public readonly Process $process;

    /** @param list<string> $arguments */
    public function __construct(string $script, array $arguments = [])
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rokn-http-'.bin2hex(random_bytes(12));
        if (!mkdir($this->directory, 0700)) {
            throw new RuntimeException('Cannot create the local HTTP fixture directory.');
        }
        $readyFile = $this->directory.DIRECTORY_SEPARATOR.'ready';
        $this->process = new Process([PHP_BINARY, '-n', $script, ...$arguments, $readyFile]);
        $this->process->setTimeout(20);

        try {
            $this->process->start();
            $deadline = hrtime(true) + 10_000_000_000;
            do {
                clearstatcache(true, $readyFile);
                if (is_file($readyFile)) {
                    $address = trim((string) file_get_contents($readyFile));
                    if (preg_match('/^127\.0\.0\.1:[1-9][0-9]{0,4}$/D', $address) !== 1) {
                        throw new RuntimeException('Invalid local HTTP fixture address.');
                    }
                    $this->address = $address;
                    return;
                }
                // Do not use stdout as a readiness protocol. Symfony uses file
                // handles for Windows output; delivery can lag behind listening.
                usleep(10_000);
            } while (hrtime(true) < $deadline);

            throw new RuntimeException('Local HTTP fixture did not become ready: '.$this->process->getErrorOutput());
        } catch (Throwable $exception) {
            $this->stop();
            throw $exception;
        }
    }

    public function address(): string
    {
        return $this->address;
    }

    public function stop(): void
    {
        $this->process->stop(0);
        foreach (['ready', 'ready.tmp'] as $name) {
            $path = $this->directory.DIRECTORY_SEPARATOR.$name;
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }
}
