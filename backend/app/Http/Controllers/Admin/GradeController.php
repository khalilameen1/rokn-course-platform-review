<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GradeRequest;
use App\Models\Grade;
use App\Models\Setting;
use App\Models\DesignSetting;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GradeController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * عرض قائمة المراحل الدراسية
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $grades = Grade::ordered()->get();
        $designSettings = $this->getDesignSettings();
        return view('admin.grades.index', compact('grades', 'designSettings'));
    }

    /**
     * عرض نموذج إنشاء مرحلة دراسية جديدة
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $settings = Setting::first();
        $enableEnglish = $settings ? $settings->english_translation : false;
        $designSettings = $this->getDesignSettings();
        return view('admin.grades.create', compact('enableEnglish', 'designSettings'));
    }

    /**
     * حفظ مرحلة دراسية جديدة
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(GradeRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $request->validate(['authoring_request_id' => 'required|uuid']);
        DB::transaction(function () use ($request, $createIntents): void {
            $grade = Grade::create($request->validated());
            $createIntents->completeRedirect(
                $request,
                route('admin.grades.index'),
                302,
                Grade::class,
                $grade->id
            );
        }, 3);

        return redirect()->route('admin.grades.index')
            ->with('success', 'تم إضافة المرحلة الدراسية بنجاح');
    }

    /**
     * عرض نموذج تعديل مرحلة دراسية
     *
     * @param  \App\Models\Grade  $grade
     * @return \Illuminate\Http\Response
     */
    public function edit(Grade $grade)
    {
        $settings = Setting::first();
        $enableEnglish = $settings ? $settings->english_translation : false;
        $designSettings = $this->getDesignSettings();
        $editorVersion = $this->editorVersion($grade);
        return view('admin.grades.edit', compact('grade', 'enableEnglish', 'designSettings', 'editorVersion'));
    }

    /**
     * تحديث مرحلة دراسية
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Grade  $grade
     * @return \Illuminate\Http\Response
     */
    public function update(GradeRequest $request, Grade $grade)
    {
        $request->validate(['editor_version' => 'required|string|size:64']);
        DB::transaction(function () use ($request, $grade): void {
            $locked = Grade::query()->whereKey($grade->id)->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), (string) $request->input('editor_version'))) {
                throw ValidationException::withMessages([
                    'editor_version' => "عدّل شخص آخر هذه المرحلة\nأعد تحميل الصفحة قبل الحفظ",
                ]);
            }
            $locked->update($request->validated());
        }, 3);

        return redirect()->route('admin.grades.index')
            ->with('success', 'تم تحديث المرحلة الدراسية بنجاح');
    }

    /**
     * حذف مرحلة دراسية
     *
     * @param  \App\Models\Grade  $grade
     * @return \Illuminate\Http\Response
     */
    public function destroy(Grade $grade)
    {
        $blocked = DB::transaction(function () use ($grade): bool {
            $locked = Grade::query()->whereKey($grade->id)->lockForUpdate()->firstOrFail();
            if ($locked->courses()->exists()) return true;
            $locked->delete();
            return false;
        }, 3);
        if ($blocked) {
            return redirect()->route('admin.grades.index')
                ->with('error', 'لا يمكن حذف المرحلة الدراسية لوجود كورسات مرتبطة بها');
        }

        return redirect()->route('admin.grades.index')
            ->with('success', 'تم حذف المرحلة الدراسية بنجاح');
    }

    /**
     * عرض الكورسات المرتبطة بمرحلة دراسية
     *
     * @param  \App\Models\Grade  $grade
     * @return \Illuminate\Http\Response
     */
    public function courses(Grade $grade)
    {
        $canViewEnrollmentCounts = strtolower(trim((string) auth()->user()?->role)) === 'admin';
        $coursesQuery = $grade->courses()
            ->with('classifications')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
        if ($canViewEnrollmentCounts) {
            $coursesQuery->withCount('activeEnrollments');
        }
        $courses = $coursesQuery->get();
        $classifications = $courses
            ->flatMap->classifications
            ->unique('id')
            ->sortBy([['home_order', 'asc'], ['id', 'asc']])
            ->values();
        $designSettings = $this->getDesignSettings();
        return view('admin.grades.courses', compact(
            'grade',
            'courses',
            'classifications',
            'designSettings',
            'canViewEnrollmentCounts'
        ));
    }

    private function editorVersion(Grade $grade): string
    {
        return hash('sha256', json_encode([
            $grade->name_ar,
            $grade->name_en,
            $grade->type,
            $grade->description_ar,
            $grade->description_en,
            $grade->country,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
