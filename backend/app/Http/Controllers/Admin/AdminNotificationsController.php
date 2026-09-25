<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminNotificationRequest;
use App\Models\AdminNotification;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminNotificationTemplateAuthoringService;
use App\Support\NotificationTemplateEditorVersion;
use Illuminate\Http\Request;

class AdminNotificationsController extends Controller
{
    public function __construct(private readonly AdminNotificationTemplateAuthoringService $authoring)
    {
    }

    public function index()
    {
        $admin_notifications = AdminNotification::query()
            ->with('photo')->orderBy('priority')->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(30);
        $editorVersions = $admin_notifications->getCollection()->mapWithKeys(
            fn (AdminNotification $notification): array => [
                $notification->id => NotificationTemplateEditorVersion::for($notification),
            ]
        );

        return view('admin.admin_notifications.index', compact('admin_notifications', 'editorVersions'));
    }

    public function create()
    {
        return view('admin.admin_notifications.create');
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

    public function edit(AdminNotification $admin_notification)
    {
        $editorVersion = NotificationTemplateEditorVersion::for($admin_notification);

        return view('admin.admin_notifications.edit', compact('admin_notification', 'editorVersion'));
    }

    public function update(AdminNotificationRequest $request, AdminNotification $admin_notification)
    {
        $this->authoring->update(
            (int) $admin_notification->id, $request->validated(), (string) $request->validated('editor_version'),
            $request->file('image'), $request->boolean('remove_image')
        );

        return redirect()->route('admin.admin_notifications.index')->with('success', 'تم التعديل بنجاح');
    }

    public function destroy(Request $request, AdminNotification $admin_notification)
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
        $disabled = $this->authoring->deleteOrDisable((int) $admin_notification->id, (string) $validated['editor_version']);

        return redirect()->route('admin.admin_notifications.index')
            ->with('success', $disabled ? 'تم إيقاف القالب' : 'تم حذف القالب');
    }
}
