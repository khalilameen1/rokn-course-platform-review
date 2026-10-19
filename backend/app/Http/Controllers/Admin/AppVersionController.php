<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\AppVersionResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppVersionProfileRequest;
use App\Http\Requests\Admin\EditorVersionRequest;
use App\Models\AppVersion;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AppReleaseAuthoringService;
use Illuminate\Http\RedirectResponse;

class AppVersionController extends Controller
{
    public function __construct(
        private readonly AppReleaseAuthoringService $authoring
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return RedirectResponse
     */
    public function index()
    {
        return redirect(AppVersionResource::getUrl());
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return RedirectResponse
     */
    public function create()
    {
        return redirect(AppVersionResource::getUrl('create'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return RedirectResponse
     */
    public function store(AppVersionProfileRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $data = $request->authoringData();

        $this->authoring->create($data, function (AppVersion $version) use ($request, $createIntents): void {
            $createIntents->completeRedirect(
                $request,
                route('admin.app-versions.index'),
                302,
                AppVersion::class,
                $version->id
            );
        });

        return redirect()->route('admin.app-versions.index')->with('success', 'تم إضافة الإصدار بنجاح');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return RedirectResponse
     */
    public function edit($id)
    {
        $version = AppVersion::findOrFail($id);
        if ($version->distribution_channel === null) {
            return redirect()->route('admin.app-versions.index')->with(
                'error',
                'هذا سجل قديم بلا قناة محددة ويمكن إيقافه فقط. أنشئ إصدارًا جديدًا للقناة الصحيحة.'
            );
        }

        return redirect(AppVersionResource::getUrl('edit', ['record' => $version->id]));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return RedirectResponse
     */
    public function update(AppVersionProfileRequest $request, $id)
    {
        $version = AppVersion::findOrFail($id);
        $editorVersion = (string) $request->validated('editor_version');
        $data = $request->authoringData();

        $this->authoring->update((int) $version->id, $data, $editorVersion);

        return redirect()->route('admin.app-versions.index')->with('success', 'تم تحديث الإصدار بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return RedirectResponse
     */
    public function destroy(EditorVersionRequest $request, $id)
    {
        $version = AppVersion::findOrFail($id);
        $editorVersion = (string) $request->validated('editor_version');
        $blocked = ! $this->authoring->delete((int) $version->id, $editorVersion);
        if ($blocked) {
            return redirect()->back()->with(
                'error',
                'أوقف الإصدار أولًا حتى لا يختفي رابط التحميل أو التحديث أثناء الحذف'
            );
        }

        return redirect()->route('admin.app-versions.index')->with('success', 'تم حذف الإصدار بنجاح');
    }

    /**
     * Toggle the active status of the specified resource.
     *
     * @param  int  $id
     * @return RedirectResponse
     */
    public function toggleActive(EditorVersionRequest $request, $id)
    {
        $version = AppVersion::findOrFail($id);
        $editorVersion = (string) $request->validated('editor_version');

        $blocked = ! $this->authoring->toggleActive((int) $version->id, $editorVersion);
        if ($blocked) {
            return redirect()->back()->with(
                'error',
                'أكمل قناة التوزيع والرقم الداخلي ورابط التحديث الرسمي قبل تفعيل الإصدار.',
            );
        }

        return redirect()->back()->with('success', 'تم تغيير الحالة بنجاح');
    }
}
