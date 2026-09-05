<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AppVersion;
use App\Services\AppReleasePolicyService;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\AdminEditorVersion;

class AppVersionController extends Controller
{
    public function __construct(private readonly AppReleasePolicyService $releasePolicy)
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
            fn (AppVersion $version): array => [$version->id => $this->editorVersion($version)]
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

        DB::transaction(function () use ($request, $data, $createIntents): void {
            $version = AppVersion::create($data);
            $createIntents->completeRedirect(
                $request,
                route('admin.app-versions.index'),
                302,
                AppVersion::class,
                $version->id
            );
        }, 3);

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
        $editorVersion = $this->editorVersion($version);
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

        DB::transaction(function () use ($version, $data, $editorVersion): void {
            $locked = AppVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($locked, $editorVersion);
            $locked->update($data);
        }, 3);

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
        $blocked = DB::transaction(function () use ($version, $editorVersion): bool {
            $locked = AppVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($locked, $editorVersion);
            if ($locked->is_active) return true;
            $locked->delete();
            return false;
        }, 3);
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

        $blocked = DB::transaction(function () use ($version, $editorVersion): bool {
            $locked = AppVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($locked, $editorVersion);
            if (!$locked->is_active && !$this->isActivatable($locked)) return true;
            $locked->is_active = !$locked->is_active;
            $locked->save();
            return false;
        }, 3);
        if ($blocked) {
            return redirect()->back()->with(
                'error',
                'أكمل قناة التوزيع والرقم الداخلي ورابط التحديث الرسمي قبل تفعيل الإصدار.',
            );
        }

        return redirect()->back()->with('success', 'تم تغيير الحالة بنجاح');
    }

    private function assertCurrentVersion(AppVersion $version, string $editorVersion): void
    {
        if (!hash_equals($this->editorVersion($version), $editorVersion)) {
            throw ValidationException::withMessages([
                'editor_version' => "تغيّر إصدار التطبيق منذ فتح الصفحة\nأعد تحميلها قبل المتابعة",
            ]);
        }
    }

    private function editorVersion(AppVersion $version): string
    {
        return AdminEditorVersion::for($version, [
            'platform', 'distribution_channel', 'version_name', 'version_code',
            'build_number', 'is_force_update', 'is_active', 'update_message_ar',
            'update_message_en', 'download_url', 'release_notes_ar', 'release_notes_en',
        ]);
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

        if ($existing) {
            $immutableChanged = $existing->platform !== $data['platform']
                || $existing->distribution_channel !== $data['distribution_channel']
                || $existing->version_name !== $data['version_name']
                || (int) $existing->version_code !== (int) $data['version_code']
                || (int) $existing->build_number !== (int) $data['build_number'];
            if ($immutableChanged) {
                throw ValidationException::withMessages([
                    'version_name' => 'هوية الإصدار والقناة والرقم الداخلي لا تتغير بعد إنشائه. أنشئ إصدارًا جديدًا.',
                ]);
            }
        } else {
            $identifierColumn = $platform === 'android' ? 'version_code' : 'build_number';
            $candidate = (int) $data[$identifierColumn];
            $channelMaximum = (int) AppVersion::query()
                ->where('platform', $platform)
                ->where('distribution_channel', $channel)
                ->max($identifierColumn);
            $platformMaximum = (int) AppVersion::query()
                ->where('platform', $platform)
                ->max($identifierColumn);
            $sameBuildVersionNames = AppVersion::query()
                ->where('platform', $platform)
                ->where($identifierColumn, $candidate)
                ->pluck('version_name')
                ->map(fn ($name): string => (string) $name)
                ->unique();
            if (
                $sameBuildVersionNames->isNotEmpty()
                && !$sameBuildVersionNames->contains($data['version_name'])
            ) {
                throw ValidationException::withMessages([
                    'version_name' => 'نفس رقم البناء يجب أن يحمل نفس اسم الإصدار في كل قنوات التوزيع.',
                ]);
            }
            // Play and direct may publish the same Android build identity, but
            // neither channel may introduce a lower build than users could
            // already have installed from the other one.
            if (
                ($channelMaximum > 0 && $candidate <= $channelMaximum)
                || ($platformMaximum > 0 && $candidate < $platformMaximum)
            ) {
                $minimum = max($channelMaximum + 1, $platformMaximum);
                throw ValidationException::withMessages([
                    $identifierColumn => "استخدم رقمًا لا يقل عن {$minimum}. الرجوع إلى رقم أقدم يمنع التحديث فوق النسخة المثبتة.",
                ]);
            }
        }

        return $data;
    }

    private function isActivatable(AppVersion $version): bool
    {
        $channel = (string) $version->distribution_channel;
        $hasIdentifier = $version->platform === 'android'
            ? in_array($channel, ['play', 'direct'], true) && (int) $version->version_code > 0
            : $channel === 'appstore' && (int) $version->build_number > 0;

        return $hasIdentifier
            && $this->releasePolicy->isAllowedDownloadUrl($channel, $version->download_url);
    }
}
