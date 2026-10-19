<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\LevelResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LevelProfileRequest;
use App\Models\Level;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminLevelAuthoringService;
use Illuminate\Http\RedirectResponse;

class LevelController extends Controller
{
    public function __construct(private readonly AdminLevelAuthoringService $authoring) {}

    /**
     * Display a listing of the resource.
     *
     * @return RedirectResponse
     */
    public function index()
    {
        return redirect(LevelResource::getUrl());
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return RedirectResponse
     */
    public function create()
    {
        return redirect(LevelResource::getUrl('create'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return RedirectResponse
     */
    public function store(LevelProfileRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->validated();

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
     * @return RedirectResponse
     */
    public function edit(Level $level)
    {
        return redirect(LevelResource::getUrl('edit', ['record' => $level->id]));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return RedirectResponse
     */
    public function update(LevelProfileRequest $request, Level $level)
    {
        $validated = $request->validated();

        $this->authoring->update(
            (int) $level->id, $validated, (string) $validated['editor_version'], $request->file('badge_image')
        );

        return redirect()->route('admin.levels.index')
            ->with('success', 'تم تحديث المستوى بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return RedirectResponse
     */
    public function destroy(Level $level)
    {
        if (! $this->authoring->deleteIfUnused((int) $level->id)) {
            return redirect()->route('admin.levels.index')
                ->with('error', 'لا يمكن حذف مستوى مرتبط بكورسات أو طلاب');
        }

        return redirect()->route('admin.levels.index')
            ->with('success', 'تم حذف المستوى بنجاح');
    }
}
