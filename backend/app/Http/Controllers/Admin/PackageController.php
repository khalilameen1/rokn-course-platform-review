<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\PackageResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EditorVersionRequest;
use App\Http\Requests\Admin\PackageProfileRequest;
use App\Models\Package;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminPackageAuthoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class PackageController extends Controller
{
    public function __construct(private readonly AdminPackageAuthoringService $authoring) {}

    /**
     * Display a listing of the resource.
     *
     * @return RedirectResponse
     */
    public function index()
    {
        return redirect(PackageResource::getUrl());
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return RedirectResponse
     */
    public function create()
    {
        return redirect(PackageResource::getUrl('create'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return RedirectResponse
     */
    public function store(PackageProfileRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->authoringData();

        $this->authoring->create($validated, function (Package $package) use ($request, $createIntents): void {
            $createIntents->completeRedirect(
                $request,
                route('admin.packages.index'),
                302,
                Package::class,
                $package->id
            );
        });

        return redirect()->route('admin.packages.index')->with('success', 'تم إضافة الباقة بنجاح');
    }

    /**
     * Display the specified resource.
     *
     * @return RedirectResponse
     */
    public function show(Package $package)
    {
        return redirect(PackageResource::getUrl('view', ['record' => $package->id]));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return RedirectResponse
     */
    public function edit(Package $package)
    {
        return redirect(PackageResource::getUrl('edit', ['record' => $package->id]));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return RedirectResponse
     */
    public function update(PackageProfileRequest $request, Package $package)
    {
        $editorVersion = (string) $request->validated('editor_version');
        $validated = $request->authoringData();

        try {
            $this->authoring->update((int) $package->id, $validated, $editorVersion);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages([
                'package' => [$exception->getMessage()],
            ]);
        }

        return redirect()->route('admin.packages.index')->with('success', 'تم تحديث الباقة بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return RedirectResponse
     */
    public function destroy(EditorVersionRequest $request, Package $package)
    {
        $validated = $request->validated();
        if (! $this->authoring->deleteIfUnused((int) $package->id, (string) $validated['editor_version'])) {
            return redirect()->back()->with(
                'error',
                'لا يمكن حذف باقة دخلت دورة بيع. عطّل قنواتها مع الاحتفاظ بالسجل المالي.'
            );
        }

        return redirect()->route('admin.packages.index')->with('success', 'تم حذف الباقة بنجاح');
    }
}
