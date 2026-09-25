<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AppVersion;
use App\Services\AppReleasePolicyService;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Validation\Rule;
use App\Support\AppVersionEditorVersion;
use App\Services\AppReleaseAuthoringService;

class AppVersionController extends Controller
{
    public function __construct(
        private readonly AppReleasePolicyService $releasePolicy,
        private readonly AppReleaseAuthoringService $authoring
    )
    {
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
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
     * @return \Illuminate\Http\Response
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
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $data = $this->validatedPayload($request);

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
     * @return \Illuminate\Http\Response
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
     * @return \Illuminate\Http\Response
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
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $version = AppVersion::findOrFail($id);
        $editorVersion = (string) $request->validate([
            'editor_version' => 'required|string|size:64',
        ])['editor_version'];
        $data = $this->validatedPayload($request, $version);

        $this->authoring->update((int) $version->id, $data, $editorVersion);

        return redirect()->route('admin.app-versions.index')->with('success', 'تم تحديث الإصدار بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id)
    {
        $version = AppVersion::findOrFail($id);
        $editorVersion = (string) $request->validate([
            'editor_version' => 'required|string|size:64',
        ])['editor_version'];
        $blocked = !$this->authoring->delete((int) $version->id, $editorVersion);
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
     * @return \Illuminate\Http\Response
     */
    public function toggleActive(Request $request, $id)
    {
        $version = AppVersion::findOrFail($id);
        $editorVersion = (string) $request->validate([
            'editor_version' => 'required|string|size:64',
        ])['editor_version'];

        $blocked = !$this->authoring->toggleActive((int) $version->id, $editorVersion);
        if ($blocked) {
            return redirect()->back()->with(
                'error',
                'أكمل قناة التوزيع والرقم الداخلي ورابط التحديث الرسمي قبل تفعيل الإصدار.',
            );
        }

        return redirect()->back()->with('success', 'تم تغيير الحالة بنجاح');
    }

    /**
     * Keep the dashboard contract aligned with the native stores: Android is
     * ordered by versionCode and iOS by CFBundleVersion (build number).
     */
    private function validatedPayload(Request $request, ?AppVersion $existing = null): array
    {
        $platform = (string) $request->input('platform');
        $channel = (string) $request->input('distribution_channel');
        $requiresUrl = $request->boolean('is_active') || $request->boolean('is_force_update');

        $data = $request->validate([
            'platform' => ['required', 'in:android,ios'],
            'distribution_channel' => $platform === 'ios'
                ? ['required', Rule::in(['appstore'])]
                : ['required', Rule::in(['play', 'direct'])],
            'version_name' => ['required', 'string', 'max:40', 'regex:/^\d+(?:\.\d+){1,3}$/'],
            'version_code' => [
                Rule::requiredIf($platform === 'android'),
                'nullable',
                'integer',
                'min:1',
                Rule::unique('app_versions', 'version_code')
                    ->where(fn ($query) => $query
                        ->where('platform', 'android')
                        ->where('distribution_channel', $channel))
                    ->ignore($existing?->id),
            ],
            'build_number' => [
                Rule::requiredIf($platform === 'ios'),
                'nullable',
                'integer',
                'min:1',
                Rule::unique('app_versions', 'build_number')
                    ->where(fn ($query) => $query
                        ->where('platform', 'ios')
                        ->where('distribution_channel', $channel))
                    ->ignore($existing?->id),
            ],
            'is_force_update' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'update_message_ar' => ['nullable', 'string', 'max:500'],
            'update_message_en' => ['nullable', 'string', 'max:500'],
            'download_url' => [
                Rule::requiredIf($requiresUrl),
                'nullable',
                'string',
                'max:2048',
                'url',
                function (string $attribute, $value, $fail) use ($channel): void {
                    if (!$this->releasePolicy->isAllowedDownloadUrl($channel, $value)) {
                        $fail(match ($channel) {
                            'play' => 'استخدم صفحة تطبيق ركن الصحيحة على Google Play',
                            'appstore' => 'استخدم صفحة تطبيق ركن على App Store',
                            'direct' => 'استخدم رابط APK مباشرًا على rokn.app',
                            default => 'رابط التحديث لا يطابق قناة التوزيع',
                        });
                    }
                },
            ],
            'release_notes_ar' => ['nullable', 'string', 'max:5000'],
            'release_notes_en' => ['nullable', 'string', 'max:5000'],
            'authoring_request_id' => [$existing ? 'nullable' : 'required', 'uuid'],
        ]);

        unset($data['authoring_request_id']);

        $data['is_force_update'] = $request->boolean('is_force_update');
        $data['is_active'] = $request->boolean('is_active');
        $data['version_code'] = $platform === 'android' ? (int) $data['version_code'] : null;
        $data['build_number'] = $platform === 'ios' ? (int) $data['build_number'] : null;

        return $data;
    }
}
