<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminNotificationRequest;
use App\Models\AdminNotification;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\StoredFileDeletionService;
use App\Support\BusinessClock;
use App\Support\RoknAppLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminNotificationsController extends Controller
{
    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index()
    {
        $admin_notifications = AdminNotification::query()
            ->with('photo')
            ->orderBy('priority')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(30);
        $editorVersions = $admin_notifications->getCollection()->mapWithKeys(
            fn (AdminNotification $notification): array => [
                $notification->id => $this->editorVersion($notification),
            ]
        );

        return view('admin.admin_notifications.index', compact('admin_notifications', 'editorVersions'));
    }


    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function create()
    {
        return view('admin.admin_notifications.create');
    }


    /**
     * @param AdminNotificationRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(
        AdminNotificationRequest $request,
        AdminAuthoringCreateIntentService $createIntents
    )
    {
        $requestId = (string) $request->validated('authoring_request_id');
        $payload = $this->payload($request);
        $storedImagePath = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $storedImagePath = app(StoredFileDeletionService::class)->storeTrackedUpload(
                $file,
                'admin_notifications',
                'public',
                60,
                'admin-message-template|'.strtolower($requestId).'|'.hash_file('sha256', $file->getRealPath())
            );
        }

        $committed = false;
        try {
            DB::transaction(function () use (
                $request,
                $payload,
                $requestId,
                $createIntents,
                $storedImagePath
            ): void {
                $notification = AdminNotification::query()
                    ->where('authoring_request_id', $requestId)
                    ->lockForUpdate()
                    ->first();
                if ($notification) {
                    if (!$this->sameCreatePayload($notification, $payload, $request)) {
                        throw ValidationException::withMessages([
                            'authoring_request_id' => ['تغيّرت بيانات القالب\nأعد فتح النموذج ثم أرسل'],
                        ]);
                    }
                } else {
                    $notification = AdminNotification::create(
                        $payload + ['authoring_request_id' => $requestId]
                    );
                }

                if (is_string($storedImagePath) && $storedImagePath !== '') {
                    $notification->allPhotos()->firstOrCreate([
                        'path' => $storedImagePath,
                        'type' => 'featured',
                    ]);
                }

                // The template row, its optional image reference and the
                // replay receipt become visible together. A storage/DB crash
                // can no longer expose an active half-created template.
                $createIntents->completeRedirect(
                    $request,
                    route('admin.admin_notifications.index'),
                    302,
                    AdminNotification::class,
                    $notification->id
                );
            }, 3);
            $committed = true;
        } finally {
            if (!$committed && is_string($storedImagePath) && $storedImagePath !== '') {
                app(StoredFileDeletionService::class)->deleteOrQueue('public', $storedImagePath);
            }
        }

        return redirect()->route('admin.admin_notifications.index')->with('success', 'تمت الإضافة بنجاح ');
    }

    /**
     * @param AdminNotification $admin_notification
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function edit(AdminNotification $admin_notification)
    {
        $editorVersion = $this->editorVersion($admin_notification);
        return view('admin.admin_notifications.edit', compact('admin_notification', 'editorVersion'));
    }


    /**
     * @param AdminNotificationRequest $request
     * @param AdminNotification $admin_notification
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(AdminNotificationRequest $request, AdminNotification $admin_notification)
    {
        $payload = $this->payload($request);
        $storedImagePath = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $storedImagePath = app(StoredFileDeletionService::class)->storeTrackedUpload(
                $image,
                'admin_notifications',
                'public',
                60,
                implode('|', [
                    'admin-message-template-update',
                    $admin_notification->id,
                    (string) $request->input('editor_version'),
                    hash_file('sha256', $image->getRealPath()),
                ])
            );
        }
        $committed = false;
        try {
            DB::transaction(function () use (
                $request,
                $admin_notification,
                $payload,
                $storedImagePath
            ): void {
                $locked = AdminNotification::query()->whereKey($admin_notification->id)
                    ->lockForUpdate()->firstOrFail();
                if (!hash_equals($this->editorVersion($locked), (string) $request->input('editor_version'))) {
                    throw ValidationException::withMessages([
                        'editor_version' => 'تغيّر القالب منذ فتح الصفحة\nأعد تحميله قبل الحفظ',
                    ]);
                }
                if ($locked->isSystemTemplate()) {
                    $payload['system_key'] = $locked->system_key;
                }
                $locked->update($payload);

                if (is_string($storedImagePath) && $storedImagePath !== '') {
                    $oldPhotos = $locked->allPhotos()->where('type', 'featured')
                        ->lockForUpdate()->get();
                    $newPhoto = $locked->allPhotos()->firstOrCreate([
                        'path' => $storedImagePath,
                        'type' => 'featured',
                    ]);
                    $oldPhotos->where('id', '!=', $newPhoto->id)->each->delete();
                } elseif ($request->boolean('remove_image')) {
                    $locked->allPhotos()->where('type', 'featured')
                        ->lockForUpdate()->get()->each->delete();
                }
            }, 3);
            $committed = true;
        } finally {
            if (!$committed && is_string($storedImagePath) && $storedImagePath !== '') {
                app(StoredFileDeletionService::class)->deleteOrQueue('public', $storedImagePath);
            }
        }

        return redirect()->route('admin.admin_notifications.index')->with('success', 'تم التعديل بنجاح');
    }


    /**
     * @param AdminNotification $admin_notification
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function destroy(Request $request, AdminNotification $admin_notification)
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
        $disabled = DB::transaction(function () use ($admin_notification, $validated): bool {
            $locked = AdminNotification::query()->whereKey($admin_notification->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), (string) $validated['editor_version'])) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّر القالب منذ فتح الصفحة\nأعد تحميله قبل الإيقاف أو الحذف',
                ]);
            }
            if ($locked->isSystemTemplate()) {
                $locked->update(['is_active' => false]);
                return true;
            }
            $locked->delete();
            return false;
        }, 3);

        if ($disabled) {
            return redirect()->route('admin.admin_notifications.index')->with('success', 'تم إيقاف القالب');
        }

        return redirect()->route('admin.admin_notifications.index')->with('success', 'تم حذف القالب');
    }

    private function payload(AdminNotificationRequest $request): array
    {
        $payload = $request->safe()->except([
            'image', 'remove_image', 'authoring_request_id', 'editor_version',
        ]);
        $payload['title_en'] = trim((string) ($payload['title_en'] ?? '')) ?: $payload['title_ar'];
        $payload['description_en'] = trim((string) ($payload['description_en'] ?? '')) ?: $payload['description_ar'];
        foreach (['starts_at', 'ends_at'] as $field) {
            $payload[$field] = BusinessClock::localInputToUtc($payload[$field] ?? null);
        }
        $payload['link'] = RoknAppLink::normalize($payload['link'] ?? null);

        return $payload + [
            'is_active' => $request->boolean('is_active'),
            'is_dismissible' => $request->boolean('is_dismissible'),
        ];
    }

    private function sameCreatePayload(
        AdminNotification $notification,
        array $payload,
        AdminNotificationRequest $request
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
        if (!$request->hasFile('image')) return $photo === null;
        // A prior worker can die after the template row commits but before
        // its deterministic image is attached. Let the same intent finish it.
        if (!$photo) return true;

        return $this->trackedImageMatches(
            (string) $photo->path,
            $request->file('image'),
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

    private function editorVersion(AdminNotification $notification): string
    {
        $notification->loadMissing('photo');
        return hash('sha256', json_encode([
            (string) $notification->system_key,
            (string) $notification->surface,
            (string) $notification->title_ar,
            (string) $notification->title_en,
            (string) $notification->description_ar,
            (string) $notification->description_en,
            (string) $notification->action_label_ar,
            (string) $notification->action_label_en,
            (string) $notification->secondary_action_label_ar,
            (string) $notification->secondary_action_label_en,
            (string) $notification->link,
            (bool) $notification->is_active,
            (bool) $notification->is_dismissible,
            (int) $notification->priority,
            (int) $notification->cooldown_hours,
            $notification->starts_at?->toIso8601String(),
            $notification->ends_at?->toIso8601String(),
            (string) ($notification->photo?->path ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
