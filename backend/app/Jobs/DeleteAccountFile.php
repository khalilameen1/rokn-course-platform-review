<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AccountFileDeletion;
use App\Services\StoredFileReferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class DeleteAccountFile implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $timeout = 90;

    public int $uniqueFor = 14400;

    public array $backoff = [30, 60, 120, 300, 600, 1200, 1800, 3600];

    public function __construct(public int $deletionId)
    {
        $this->onQueue((string) config('queue.channels.media', 'media'));
    }

    public function uniqueId(): string
    {
        return 'account-file-deletion:'.$this->deletionId;
    }

    public function handle(StoredFileReferenceService $references): void
    {
        $deletion = DB::transaction(function (): ?AccountFileDeletion {
            $row = AccountFileDeletion::query()->lockForUpdate()->find($this->deletionId);
            if (! $row || in_array($row->status, [
                AccountFileDeletion::STATUS_COMPLETED,
                AccountFileDeletion::STATUS_SKIPPED,
            ], true)) {
                return null;
            }
            if ($row->available_at?->isFuture()) {
                $this->release(max(1, now()->diffInSeconds($row->available_at)));

                return null;
            }
            $row->forceFill([
                'status' => AccountFileDeletion::STATUS_PROCESSING,
                'attempts' => $row->attempts + 1,
                'last_error' => null,
            ])->save();

            return $row;
        });

        if (! $deletion) {
            return;
        }

        try {
            $path = ltrim((string) $deletion->path, '/');
            if ($path === '' || filter_var($path, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Stored cleanup path is invalid.');
            }
            if ($references->isReferenced((string) $deletion->disk, $path)) {
                $deletion->forceFill([
                    'status' => AccountFileDeletion::STATUS_SKIPPED,
                    'path' => null,
                    'completed_at' => now(),
                    'available_at' => null,
                    'last_error' => 'path_is_referenced',
                ])->save();

                return;
            }
            $disk = Storage::disk((string) $deletion->disk);
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new RuntimeException('Storage refused the deletion.');
            }

            $deletion->forceFill([
                'status' => AccountFileDeletion::STATUS_COMPLETED,
                'path' => null,
                'completed_at' => now(),
                'available_at' => null,
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $deletion->forceFill([
                'status' => AccountFileDeletion::STATUS_PENDING,
                'available_at' => now()->addMinutes(min(60, 2 ** min(6, $deletion->attempts))),
                'last_error' => class_basename($exception),
            ])->save();
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        AccountFileDeletion::query()
            ->whereKey($this->deletionId)
            ->whereNotIn('status', [
                AccountFileDeletion::STATUS_COMPLETED,
                AccountFileDeletion::STATUS_SKIPPED,
            ])
            ->update([
                'status' => AccountFileDeletion::STATUS_FAILED,
                'available_at' => now()->addHour(),
                'last_error' => class_basename($exception),
                'updated_at' => now(),
            ]);
    }
}
