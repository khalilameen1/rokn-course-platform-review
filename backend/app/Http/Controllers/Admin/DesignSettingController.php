<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DesignSetting;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminDesignSettingsAuthoringService;
use App\Services\AppArtworkService;
use App\Support\DesignSettingsEditorVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class DesignSettingController extends Controller
{
    public function __construct(private readonly AdminDesignSettingsAuthoringService $authoring)
    {
    }

    public function index(): View
    {
        $settings = DesignSetting::getDefaultSettings();

        return view('admin.design-settings.index', [
            'settings' => $settings,
            'editorVersion' => DesignSettingsEditorVersion::for($settings),
            'artwork' => app(AppArtworkService::class)->urls($settings),
        ]);
    }

    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents): RedirectResponse
    {
        $validated = $request->validate($this->rules());
        try {
            $this->authoring->save(
                $validated, $request->allFiles(), (string) $validated['editor_version'],
                function (DesignSetting $settings) use ($request, $createIntents): void {
                    $createIntents->completeRedirect(
                        $request, route('admin.design-settings.index'), 302, DesignSetting::class, $settings->id
                    );
                }
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'تعذر حفظ الإعدادات الآن');
        }

        return redirect()->route('admin.design-settings.index')->with('success', 'تم حفظ إعدادات التصميم');
    }

    /** @return array<string, string|array<int, string>> */
    private function rules(): array
    {
        $rules = [
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
        foreach (array_keys(AppArtworkService::ASSETS) as $key) {
            $rules[$key.'_image_file'] = ['nullable', 'image', 'mimes:png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096'];
        }
        return $rules;
    }
}
