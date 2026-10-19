<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\NotificationTemplateResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminNotificationRequest;
use App\Http\Requests\Admin\EditorVersionRequest;
use App\Models\AdminNotification;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminNotificationTemplateAuthoringService;
use Illuminate\Http\RedirectResponse;

class AdminNotificationsController extends Controller
{
    public function __construct(private readonly AdminNotificationTemplateAuthoringService $authoring) {}

    public function index(): RedirectResponse
    {
        return redirect()->to(NotificationTemplateResource::getUrl());
    }

    public function create(): RedirectResponse
    {
        return redirect()->to(NotificationTemplateResource::getUrl('create'));
    }

    public function store(AdminNotificationRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $this->authoring->create(
            $request->validated(), (string) $request->validated('authoring_request_id'), $request->file('image'),
            function (AdminNotification $notification) use ($request, $createIntents): void {
                $createIntents->completeRedirect(
                    $request, route('admin.admin_notifications.index'), 302, AdminNotification::class, $notification->id
                );
            }
        );

        return redirect()->route('admin.admin_notifications.index')->with('success', 'تمت الإضافة بنجاح ');
    }

    public function edit(AdminNotification $admin_notification): RedirectResponse
    {
        return redirect()->to(NotificationTemplateResource::getUrl('edit', ['record' => $admin_notification->id]));
    }

    public function update(AdminNotificationRequest $request, AdminNotification $admin_notification)
    {
        $this->authoring->update(
            (int) $admin_notification->id, $request->validated(), (string) $request->validated('editor_version'),
            $request->file('image'), $request->boolean('remove_image')
        );

        return redirect()->route('admin.admin_notifications.index')->with('success', 'تم التعديل بنجاح');
    }

    public function destroy(EditorVersionRequest $request, AdminNotification $admin_notification)
    {
        $validated = $request->validated();
        $disabled = $this->authoring->deleteOrDisable((int) $admin_notification->id, (string) $validated['editor_version']);

        return redirect()->route('admin.admin_notifications.index')
            ->with('success', $disabled ? 'تم إيقاف القالب' : 'تم حذف القالب');
    }
}
