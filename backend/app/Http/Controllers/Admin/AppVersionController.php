<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppVersionProfileRequest;
use App\Http\Requests\Admin\EditorVersionRequest;
use App\Models\AppVersion;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AppReleaseAuthoringService;
use App\Services\AppReleasePolicyService;
use App\Support\AppVersionEditorVersion;
use Illuminate\Http\Response;

class AppVersionController extends Controller
{
    public function __construct(
        private readonly AppReleasePolicyService $releasePolicy,
        private readonly AppReleaseAuthoringService $authoring
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $versions = AppVersion::orderBy('id', 'desc')->paginate(10);
        $releaseReadiness = $this->releasePolicy->launchReadiness();
        $editorVersions = $versions->getCollection()->mapWithKeys(
            fn (AppVersion $version): array => [$version->id => AppVersionEditorVersion::for($version)]
        );

        return view('admin.app-versions.index', compact('versions', 'releaseReadiness', 'editorVersions'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        $latestIdentifiers = collect($this->releasePolicy->channels())
            ->mapWithKeys(function (string $channel): array {
                $platform = $this->releasePolicy->platformForChannel($channel);
                $identifier = $channel === AppReleasePolicyService::CHANNEL_APP_STORE
                    ? 'build_number'
                    : 'version_code';

                return [$channel => [
                    'channel' => (int) AppVersion::query()
                        ->where('platform', $platform)
                        ->where('distribution_channel', $channel)
                        ->max($identifier),
                    'platform' => (int) AppVersion::query()
                        ->where('platform', $platform)
                        ->max($identifier),
                ]];
            })->all();

        return view('admin.app-versions.create', compact('latestIdentifiers'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
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
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show($id)
    {
        AppVersion::findOrFail($id);

        return redirect()->route('admin.app-versions.edit', $id);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
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
        $editorVersion = AppVersionEditorVersion::for($version);

        return view('admin.app-versions.edit', compact('version', 'editorVersion'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
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
     * @return Response
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
     * @return Response
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
