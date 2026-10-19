<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\ArtworkSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DesignSettingsRequest;
use App\Models\DesignSetting;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminDesignSettingsAuthoringService;
use Illuminate\Http\RedirectResponse;

final class DesignSettingController extends Controller
{
    public function __construct(private readonly AdminDesignSettingsAuthoringService $authoring) {}

    public function index(): RedirectResponse
    {
        return redirect()->to(ArtworkSettings::getUrl());
    }

    public function store(DesignSettingsRequest $request, AdminAuthoringCreateIntentService $createIntents): RedirectResponse
    {
        $validated = $request->validated();
        $this->authoring->save(
            $validated, $request->allFiles(), (string) $validated['editor_version'],
            function (DesignSetting $settings) use ($request, $createIntents): void {
                $createIntents->completeRedirect(
                    $request, route('admin.design-settings.index'), 302, DesignSetting::class, $settings->id
                );
            }
        );

        return redirect()->route('admin.design-settings.index')->with('success', 'تم حفظ إعدادات التصميم');
    }
}
