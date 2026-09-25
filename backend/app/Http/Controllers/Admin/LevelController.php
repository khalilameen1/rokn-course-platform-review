<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Level;
use App\Models\DesignSetting;
use App\Services\AdminLevelAuthoringService;
use App\Support\LevelEditorVersion;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;

class LevelController extends Controller
{
    public function __construct(private readonly AdminLevelAuthoringService $authoring)
    {
    }

    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $levels = Level::ordered()->get();
        $designSettings = $this->getDesignSettings();
        return view('admin.levels.index', compact('levels', 'designSettings'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $designSettings = $this->getDesignSettings();
        return view('admin.levels.create', compact('designSettings'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'description_ar' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'badge_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'order' => 'nullable|integer|min:1|max:1000',
            'authoring_request_id' => 'required|uuid',
        ]);

        $this->authoring->create(
            $validated,
            (string) $validated['authoring_request_id'],
            $request->file('badge_image'),
            function (Level $level) use ($request, $createIntents): void {
                $createIntents->completeRedirect(
                    $request, route('admin.levels.index'), 302, Level::class, $level->id
                );
            }
        );

        return redirect()->route('admin.levels.index')
            ->with('success', 'تم إضافة المستوى بنجاح');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Level  $level
     * @return \Illuminate\Http\Response
     */
    public function edit(Level $level)
    {
        $designSettings = $this->getDesignSettings();
        $editorVersion = LevelEditorVersion::for($level);
        return view('admin.levels.edit', compact('level', 'designSettings', 'editorVersion'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Level  $level
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Level $level)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'description_ar' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'badge_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'order' => 'nullable|integer|min:1|max:1000',
            'editor_version' => 'required|string|size:64',
        ]);

        $this->authoring->update(
            (int) $level->id, $validated, (string) $validated['editor_version'], $request->file('badge_image')
        );

        return redirect()->route('admin.levels.index')
            ->with('success', 'تم تحديث المستوى بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Level  $level
     * @return \Illuminate\Http\Response
     */
    public function destroy(Level $level)
    {
        if (!$this->authoring->deleteIfUnused((int) $level->id)) {
            return redirect()->route('admin.levels.index')
                ->with('error', 'لا يمكن حذف مستوى مرتبط بكورسات أو طلاب');
        }

        return redirect()->route('admin.levels.index')
            ->with('success', 'تم حذف المستوى بنجاح');
    }

}
