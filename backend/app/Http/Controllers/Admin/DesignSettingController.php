<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DesignSettingsRequest;
use App\Models\DesignSetting;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminDesignSettingsAuthoringService;
use App\Services\AppArtworkService;
use App\Support\DesignSettingsEditorVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class DesignSettingController extends Controller
{
    public function __construct(private readonly AdminDesignSettingsAuthoringService $authoring) {}

    public function index(): View
    {
        $settings = DesignSetting::getDefaultSettings();

        return view('admin.design-settings.index', [
            'settings' => $settings,
            'editorVersion' => DesignSettingsEditorVersion::for($settings),
            'artwork' => app(AppArtworkService::class)->urls($settings),
        ]);
    }

    public function store(DesignSettingsRequest $request, AdminAuthoringCreateIntentService $createIntents): RedirectResponse
    {
        $validated = $request->validated();
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
}
