<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\PathResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PathIndexRequest;
use App\Http\Requests\Admin\PathProfileRequest;
use App\Models\Path;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminPathAuthoringService;
use Illuminate\Http\RedirectResponse;

class PathController extends Controller
{
    public function __construct(
        private readonly AdminPathAuthoringService $authoring
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return RedirectResponse
     */
    public function index(PathIndexRequest $request)
    {
        return redirect(PathResource::getUrl('index', ['search' => $request->validated('search', '')]));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return RedirectResponse
     */
    public function create()
    {
        return redirect(PathResource::getUrl('create'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return RedirectResponse
     */
    public function store(PathProfileRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->validated();

        $this->authoring->create($validated, function (Path $path) use ($request, $createIntents): void {
            $createIntents->completeRedirect($request, route('admin.paths.index'), 302, Path::class, $path->id);
        });

        return redirect()->route('admin.paths.index')->with('success', 'تم إضافة المسار بنجاح');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return RedirectResponse
     */
    public function edit($id)
    {
        return redirect(PathResource::getUrl('edit', ['record' => $id]));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return RedirectResponse
     */
    public function update(PathProfileRequest $request, $id)
    {
        $validated = $request->validated();

        $this->authoring->update((int) $id, $validated, (string) $validated['editor_version']);

        return redirect()->route('admin.paths.index')->with('success', 'تم تعديل المسار بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return RedirectResponse
     */
    public function destroy($id)
    {
        if (! $this->authoring->deleteIfUnused((int) $id)) {
            return redirect()->route('admin.paths.index')
                ->with('error', 'انقل الكورسات إلى مسار آخر قبل حذف هذا المسار');
        }

        return redirect()->route('admin.paths.index')->with('success', 'تم حذف المسار بنجاح');
    }
}
