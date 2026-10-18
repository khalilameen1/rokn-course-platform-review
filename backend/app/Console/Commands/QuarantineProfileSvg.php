<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class QuarantineProfileSvg extends Command
{
    protected $signature = 'security:quarantine-profile-svg {--execute : Copy to private quarantine, remove public source and clear the profile path}';

    protected $description = 'Audit and quarantine legacy local SVG profile images';

    public function handle(): int
    {
        $public = Storage::disk('public');
        $quarantine = Storage::disk('security-quarantine');
        $execute = (bool) $this->option('execute');
        $found = 0;
        $failed = 0;

        User::query()->whereNotNull('profile_image')->orderBy('id')->chunkById(200,
            function ($users) use ($public, $quarantine, $execute, &$found, &$failed): void {
                foreach ($users as $user) {
                    $path = ltrim(trim((string) $user->getRawOriginal('profile_image')), '/');
                    if ($path === '' || filter_var($path, FILTER_VALIDATE_URL) || ! $this->isSvg($public, $path)) {
                        continue;
                    }

                    $found++;
                    if (! $execute) {
                        $this->line("User #{$user->id}: {$path}");

                        continue;
                    }

                    $target = 'profile-svg/'.$user->id.'-'.basename($path);
                    try {
                        if ($public->exists($path)) {
                            $stream = $public->readStream($path);
                            if (! is_resource($stream)) {
                                throw new \RuntimeException('could not read source');
                            }
                            try {
                                if (! $quarantine->put($target, $stream)) {
                                    throw new \RuntimeException('quarantine write failed');
                                }
                            } finally {
                                fclose($stream);
                            }
                            if (! $quarantine->exists($target) || $quarantine->size($target) !== $public->size($path)) {
                                throw new \RuntimeException('quarantine verification failed');
                            }
                            if (! $public->delete($path)) {
                                throw new \RuntimeException('public source deletion failed');
                            }
                        }

                        $currentUser = User::query()
                            ->whereKey($user->id)
                            ->where('profile_image', $user->getRawOriginal('profile_image'))
                            ->first();
                        if ($currentUser) {
                            // Instructor identity is projected into cached
                            // catalogue cards. Keep the compare-before-write
                            // guard while allowing the model's after-commit
                            // invalidation to run.
                            $currentUser->forceFill(['profile_image' => null])->save();
                        }
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error("User #{$user->id}: {$exception->getMessage()}");
                    }
                }
            }
        );

        $this->info($execute
            ? "Quarantined {$found}; failed {$failed}."
            : "Found {$found} local SVG profile image(s). Run with --execute to quarantine them.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function isSvg($disk, string $path): bool
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            return true;
        }
        if (! $disk->exists($path)) {
            return false;
        }

        try {
            if (strtolower((string) $disk->mimeType($path)) === 'image/svg+xml') {
                return true;
            }
            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                return false;
            }
            try {
                $prefix = (string) fread($stream, 4096);
            } finally {
                fclose($stream);
            }

            return preg_match('/<svg(?:\s|>)/i', $prefix) === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
