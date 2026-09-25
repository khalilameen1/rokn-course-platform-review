<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Classification;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminClassificationAuthoringService;
use App\Services\AdminClassificationReadService;
use Illuminate\Http\Request;

class ClassificationController extends Controller
{
    public function __construct(
        private readonly AdminClassificationReadService $read,
        private readonly AdminClassificationAuthoringService $authoring
    ) {
    }

    public function index()
    {
        return view('admin.classifications.index', ['classifications' => $this->read->rows()]);
    }

    public function create()
    {
        return view('admin.classifications.create', ['courses' => $this->read->homeCourseOptions()]);
    }

    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'show_on_home' => 'nullable|boolean',
            'home_order' => 'required|integer|min:0|max:10000',
            'course_ids' => 'nullable|array|max:500',
            'course_ids.*' => 'integer|distinct|exists:courses,id',
            'authoring_request_id' => 'required|uuid',
        ]);
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
        return view('admin.classifications.edit', [
            'classification' => $classification,
            'courses' => $this->read->homeCourseOptions(),
            'selectedCourseIds' => $this->read->visibleCanonicalCourseIds($classification),
            'editorVersion' => $this->read->editorVersion($classification),
        ]);
    }

    public function update(Request $request, Classification $classification)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'show_on_home' => 'nullable|boolean',
            'home_order' => 'required|integer|min:0|max:10000',
            'course_ids' => 'nullable|array|max:500',
            'course_ids.*' => 'integer|distinct|exists:courses,id',
            'editor_version' => 'required|string|size:64',
        ]);
        $validated['show_on_home'] = $request->boolean('show_on_home');
        $courseIds = $validated['course_ids'] ?? [];
        $editorVersion = (string) $validated['editor_version'];
        unset($validated['course_ids'], $validated['editor_version']);
        $this->authoring->update($classification, $validated, $courseIds, $editorVersion);

        return redirect()->route('admin.classifications.index')->with('success', 'تم تحديث التصنيف بنجاح');
    }

    public function destroy(Classification $classification)
    {
        if (!$this->authoring->delete($classification)) {
            return redirect()->route('admin.classifications.index')
                ->with('error', 'انقل الكورسات إلى تصنيف آخر قبل حذف هذا التصنيف');
        }

        return redirect()->route('admin.classifications.index')->with('success', 'تم حذف التصنيف بنجاح');
    }
}
