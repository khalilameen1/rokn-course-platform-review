<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\PublicDiskUrl;
use App\Http\Controllers\Controller;
use App\Models\DesignSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Services\PublicAppSettingsService;
use App\Services\StoredFileDeletionService;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Validation\ValidationException;
use App\Support\AdminSingletonLock;

final class DesignSettingController extends Controller
{
    public function index(): View
    {
        $settings = DesignSetting::getDefaultSettings();
        return view('admin.design-settings.index', [
            'settings' => $settings,
            'editorVersion' => $this->editorVersion($settings),
        ]);
    }

    /**
     * The dashboard owns one design-settings record. The same endpoint creates
     * it on first use and updates it afterwards, so there is no second editor
     * with a competing contract.
     */
    public function store(
        Request $request,
        PublicAppSettingsService $publicSettings,
        StoredFileDeletionService $storedFiles,
        AdminAuthoringCreateIntentService $createIntents
    ): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        $submittedEditorVersion = (string) $validated['editor_version'];
        if (!empty($validated['how_platform_works_video_link'])) {
            $validated['how_platform_works_video_link'] = $publicSettings->embedVideoUrl(
                $validated['how_platform_works_video_link']
            );
            if ($validated['how_platform_works_video_link'] === null) {
                throw ValidationException::withMessages([
                    'how_platform_works_video_link' => ['استخدم رابط فيديو من YouTube أو Vimeo'],
                ]);
            }
        }
        if ($request->boolean('show_how_platform_works') && empty($validated['how_platform_works_video_link'])) {
            throw ValidationException::withMessages([
                'how_platform_works_video_link' => ['أضف رابط الفيديو قبل إظهار هذا القسم'],
            ]);
        }
        $data = collect($validated)->except([
            'logo_file',
            'icon_file',
            'home_background_file',
            'editor_version',
            'authoring_request_id',
        ])->all();
        $data['show_how_platform_works'] = $request->boolean('show_how_platform_works');

        $settings = DesignSetting::query()->first();
        $newFiles = [];
        $oldFiles = [];

        try {
            foreach ([
                'logo_file' => ['logo_url', 'design-settings/logos'],
                'icon_file' => ['icon_url', 'design-settings/icons'],
                'home_background_file' => ['home_background_url', 'design-settings/home-backgrounds'],
            ] as $input => [$attribute, $directory]) {
                if (!$request->hasFile($input)) {
                    continue;
                }

                $path = $storedFiles->storeTrackedUpload($request->file($input), $directory);

                $newFiles[] = $path;
                $data[$attribute] = PublicDiskUrl::from($path);
                if ($settings?->{$attribute}) {
                    $oldFiles[] = $this->publicPathFromUrl((string) $settings->{$attribute});
                }
            }

            DB::transaction(function () use (
                &$settings,
                $data,
                $submittedEditorVersion,
                $request,
                $createIntents
            ): void {
                AdminSingletonLock::acquire('design_settings');
                $locked = DesignSetting::query()->lockForUpdate()->first();
                $current = $locked ?: DesignSetting::getDefaultSettings();
                if (!hash_equals($this->editorVersion($current), $submittedEditorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => ["عدّل شخص آخر إعدادات التصميم\nأعد تحميل الصفحة قبل الحفظ"],
                    ]);
                }
                if ($locked) {
                    $locked->update($data);
                    $settings = $locked;
                } else {
                    $settings = DesignSetting::create($data);
                }
                $createIntents->completeRedirect(
                    $request,
                    route('admin.design-settings.index'),
                    302,
                    DesignSetting::class,
                    $settings->id
                );
            });
        } catch (\Throwable $exception) {
            foreach ($newFiles as $path) {
                $storedFiles->deleteOrQueue('public', $path);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            report($exception);

            return back()->withInput()->with('error', 'تعذر حفظ الإعدادات الآن');
        }

        foreach (array_filter($oldFiles) as $path) {
            $storedFiles->deleteOrQueue('public', $path);
        }

        return redirect()->route('admin.design-settings.index')
            ->with('success', 'تم حفظ إعدادات التصميم');
    }

    /** @return array<string, string|array<int, string>> */
    private function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'slogan_1_ar' => ['nullable', 'string', 'max:255'],
            'slogan_1_en' => ['nullable', 'string', 'max:255'],
            'slogan_2_ar' => ['nullable', 'string', 'max:255'],
            'slogan_2_en' => ['nullable', 'string', 'max:255'],
            'slogan_3_ar' => ['nullable', 'string', 'max:255'],
            'slogan_3_en' => ['nullable', 'string', 'max:255'],
            'color_1' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_2' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_3' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_4' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_file' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:1524'],
            'icon_file' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:1524'],
            'home_background_file' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
            'show_how_platform_works' => ['nullable', 'boolean'],
            'how_platform_works_title_ar' => ['nullable', 'string', 'max:255'],
            'how_platform_works_title_en' => ['nullable', 'string', 'max:255'],
            'how_platform_works_video_link' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'editor_version' => ['required', 'string', 'size:64'],
            'authoring_request_id' => ['required', 'uuid'],
        ];
    }

    private function publicPathFromUrl(string $url): ?string
    {
        return PublicDiskUrl::pathFrom($url);
    }

    private function editorVersion(DesignSetting $settings): string
    {
        return hash('sha256', implode('|', [
            (string) ($settings->id ?? 'new'),
            (string) optional($settings->updated_at)->format('Y-m-d H:i:s.u'),
        ]));
    }
}
