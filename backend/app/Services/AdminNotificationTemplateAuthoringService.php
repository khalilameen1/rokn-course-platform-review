<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminNotification;
use App\Support\BusinessClock;
use App\Support\NotificationTemplateEditorVersion;
use App\Support\RoknAppLink;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Saved templates and their images; campaign delivery has a separate owner. */
final class AdminNotificationTemplateAuthoringService
{
    public function __construct(
        private readonly StoredFileUploadService $uploads,
        private readonly StoredFileDeletionService $cleanup
    ) {
    }

    /** @param array<string, mixed> $validated
     *  @param Closure(AdminNotification):void $complete Completes the receipt in this transaction.
     */
    public function create(array $validated, string $requestId, ?UploadedFile $image, Closure $complete): AdminNotification
    {
        $payload = $this->payload($validated);
        $path = $this->stageImage($image, 'admin-message-template|'.strtolower($requestId));
        try {
            return DB::transaction(function () use ($payload, $requestId, $image, $path, $complete): AdminNotification {
                $notification = AdminNotification::query()->where('authoring_request_id', $requestId)
                    ->lockForUpdate()->first();
                if ($notification) {
                    if (!$this->sameCreatePayload($notification, $payload, $image)) {
                        throw ValidationException::withMessages([
                            'authoring_request_id' => ["تغيّرت بيانات القالب\nأعد فتح النموذج ثم أرسل"],
                        ]);
                    }
                } else {
                    $notification = AdminNotification::query()->create($payload + ['authoring_request_id' => $requestId]);
                }
                if ($path !== null) {
                    $notification->allPhotos()->firstOrCreate(['type' => 'featured'], ['path' => $path]);
                }
                $complete($notification);

                return $notification;
            }, 3);
        } catch (\Throwable $exception) {
            if ($path !== null) $this->cleanup->deleteOrQueue('public', $path);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $validated */
    public function update(int $id, array $validated, string $editorVersion, ?UploadedFile $image, bool $removeImage): void
    {
        $payload = $this->payload($validated);
        $path = $this->stageImage($image, implode('|', ['admin-message-template-update', $id, $editorVersion]));
        try {
            DB::transaction(function () use ($id, $payload, $editorVersion, $path, $removeImage): void {
                $notification = AdminNotification::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $this->assertCurrentVersion($notification, $editorVersion, 'الحفظ');
                if ($notification->isSystemTemplate()) {
                    $payload['system_key'] = $notification->system_key;
                }
                $notification->update($payload);
                if ($path !== null) {
                    $oldPhotos = $notification->allPhotos()->where('type', 'featured')->lockForUpdate()->get();
                    $newPhoto = $notification->allPhotos()->firstOrCreate(['path' => $path, 'type' => 'featured']);
                    $oldPhotos->where('id', '!=', $newPhoto->id)->each->delete();
                } elseif ($removeImage) {
                    $notification->allPhotos()->where('type', 'featured')->lockForUpdate()->get()->each->delete();
                }
            }, 3);
        } catch (\Throwable $exception) {
            if ($path !== null) $this->cleanup->deleteOrQueue('public', $path);
            throw $exception;
        }
    }

    /** Returns true when a system template was disabled, false when a manual template was deleted. */
    public function deleteOrDisable(int $id, string $editorVersion): bool
    {
        return DB::transaction(function () use ($id, $editorVersion): bool {
            $notification = AdminNotification::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($notification, $editorVersion, 'الإيقاف أو الحذف');
            if ($notification->isSystemTemplate()) {
                $notification->update(['is_active' => false]);
                return true;
            }
            $notification->delete();
            return false;
        }, 3);
    }

    private function stageImage(?UploadedFile $image, string $identityPrefix): ?string
    {
        if ($image === null) return null;
        $path = $this->uploads->storeTrackedUpload(
            $image, 'admin_notifications', 'public', 60,
            $identityPrefix.'|'.hash_file('sha256', $image->getRealPath())
        );
        if ($path === '') throw new \RuntimeException('Notification template image storage failed');

        return $path;
    }

    private function assertCurrentVersion(AdminNotification $notification, string $version, string $operation): void
    {
        if (!hash_equals(NotificationTemplateEditorVersion::for($notification), $version)) {
            throw ValidationException::withMessages([
                'editor_version' => "تغيّر القالب منذ فتح الصفحة\nأعد تحميله قبل {$operation}",
            ]);
        }
    }

    private function payload(array $validated): array
    {
        $payload = Arr::except($validated, ['image', 'remove_image', 'authoring_request_id', 'editor_version']);
        $payload['title_en'] = trim((string) ($payload['title_en'] ?? '')) ?: $payload['title_ar'];
        $payload['description_en'] = trim((string) ($payload['description_en'] ?? '')) ?: $payload['description_ar'];
        foreach (['starts_at', 'ends_at'] as $field) {
            $payload[$field] = BusinessClock::localInputToUtc($payload[$field] ?? null);
        }
        $payload['link'] = RoknAppLink::normalize($payload['link'] ?? null);
        $payload['is_active'] = (bool) ($payload['is_active'] ?? false);
        $payload['is_dismissible'] = (bool) ($payload['is_dismissible'] ?? false);

        return $payload;
    }

    private function sameCreatePayload(
        AdminNotification $notification,
        array $payload,
        ?UploadedFile $image
    ): bool {
        foreach ([
            'system_key', 'surface', 'title_ar', 'title_en', 'description_ar', 'description_en',
            'action_label_ar', 'action_label_en', 'secondary_action_label_ar',
            'secondary_action_label_en', 'link',
        ] as $field) {
            if ((string) ($notification->{$field} ?? '') !== (string) ($payload[$field] ?? '')) {
                return false;
            }
        }
        foreach (['priority', 'cooldown_hours'] as $field) {
            if ((int) $notification->{$field} !== (int) ($payload[$field] ?? 0)) return false;
        }
        foreach (['is_active', 'is_dismissible'] as $field) {
            if ((bool) $notification->{$field} !== (bool) ($payload[$field] ?? false)) return false;
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            $stored = $notification->{$field}?->getTimestamp();
            $submitted = ($payload[$field] ?? null)?->getTimestamp();
            if ($stored !== $submitted) return false;
        }

        $photo = $notification->photo()->first();
        if ($image === null) return $photo === null;
        // A prior worker can die after the template row commits but before
        // its deterministic image is attached. Let the same intent finish it.
        if (!$photo) return true;

        return $this->trackedImageMatches(
            (string) $photo->path,
            $image,
            'admin-message-template|' . strtolower((string) $notification->authoring_request_id)
        );
    }

    private function trackedImageMatches(string $path, UploadedFile $image, string $identityPrefix): bool
    {
        $storedIdentity = pathinfo($path, PATHINFO_FILENAME);
        $contentHash = hash_file('sha256', $image->getRealPath());

        return $storedIdentity !== '' && hash_equals(
            $storedIdentity,
            hash('sha256', $identityPrefix . '|' . $contentHash)
        );
    }


}
