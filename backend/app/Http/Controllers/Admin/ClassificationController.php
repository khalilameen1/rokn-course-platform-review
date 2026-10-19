<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\ClassificationResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClassificationProfileRequest;
use App\Models\Classification;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminClassificationAuthoringService;

class ClassificationController extends Controller
{
    public function __construct(
        private readonly AdminClassificationAuthoringService $authoring
    ) {}

    public function index()
    {
        return redirect(ClassificationResource::getUrl());
    }

    public function create()
    {
        return redirect(ClassificationResource::getUrl('create'));
    }

    public function store(ClassificationProfileRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->validated();
        $courseIds = $validated['course_ids'] ?? [];
        unset($validated['authoring_request_id'], $validated['course_ids']);
        $validated['show_on_home'] = $request->boolean('show_on_home');
        $this->authoring->create($validated, $courseIds, function (Classification $classification) use ($request, $createIntents): void {
            $createIntents->completeRedirect(
                $request, route('admin.classifications.index'), 302, Classification::class, $classification->id
            );
        });

        return redirect()->route('admin.classifications.index')->with('success', 'تم إضافة التصنيف بنجاح');
    }

    public function edit(Classification $classification)
    {
        return redirect(ClassificationResource::getUrl('edit', ['record' => $classification->id]));
    }

    public function update(ClassificationProfileRequest $request, Classification $classification)
    {
        $validated = $request->validated();
        $validated['show_on_home'] = $request->boolean('show_on_home');
        $courseIds = $validated['course_ids'] ?? [];
        $editorVersion = (string) $validated['editor_version'];
        unset($validated['course_ids'], $validated['editor_version']);
        $this->authoring->update($classification, $validated, $courseIds, $editorVersion);

        return redirect()->route('admin.classifications.index')->with('success', 'تم تحديث التصنيف بنجاح');
    }

    public function destroy(Classification $classification)
    {
        if (! $this->authoring->delete($classification)) {
            return redirect()->route('admin.classifications.index')
                ->with('error', 'انقل الكورسات إلى تصنيف آخر قبل حذف هذا التصنيف');
        }

        return redirect()->route('admin.classifications.index')->with('success', 'تم حذف التصنيف بنجاح');
    }
}
