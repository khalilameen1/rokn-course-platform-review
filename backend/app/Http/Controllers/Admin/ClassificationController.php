<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassificationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $classifications = Classification::query()
            ->withCount(['courses as home_courses_count' => fn ($courses) => $this
                ->onlyCanonicalCourses($courses)
                ->where('is_catalog_visible', true)])
            ->orderBy('home_order')
            ->orderBy('name_ar')
            ->get();
        return view('admin.classifications.index', compact('classifications'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.classifications.create', [
            'courses' => $this->homeCourseOptions(),
        ]);
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
            'show_on_home' => 'nullable|boolean',
            'home_order' => 'required|integer|min:0|max:10000',
            'course_ids' => 'nullable|array|max:500',
            'course_ids.*' => 'integer|distinct|exists:courses,id',
            'authoring_request_id' => 'required|uuid',
        ]);

        unset($validated['authoring_request_id']);
        $courseIds = $this->validatedHomeCourseIds($validated['course_ids'] ?? []);
        unset($validated['course_ids']);
        $validated['show_on_home'] = $request->boolean('show_on_home');
        $classification = DB::transaction(function () use ($request, $validated, $courseIds, $createIntents): Classification {
            $this->lockCoursesForHomeMembership($courseIds);
            $classification = Classification::create($validated);
            $this->syncCanonicalHomeMembership($classification, $courseIds);
            $createIntents->completeRedirect(
                $request,
                route('admin.classifications.index'),
                302,
                Classification::class,
                $classification->id
            );
            return $classification;
        }, 3);
        return redirect()->route('admin.classifications.index')
            ->with('success', 'تم إضافة التصنيف بنجاح');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Classification  $classification
     * @return \Illuminate\Http\Response
     */
    public function edit(Classification $classification)
    {
        $editorVersion = $this->editorVersion($classification);
        return view('admin.classifications.edit', [
            'classification' => $classification,
            'courses' => $this->homeCourseOptions(),
            'selectedCourseIds' => $this->visibleCanonicalCourseIds($classification),
            'editorVersion' => $editorVersion,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Classification  $classification
     * @return \Illuminate\Http\Response
     */
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
        $courseIds = $this->validatedHomeCourseIds($validated['course_ids'] ?? []);
        unset($validated['course_ids']);
        $editorVersion = (string) $validated['editor_version'];
        unset($validated['editor_version']);
        // Publishing owns the canonical Course row before it replaces this
        // pivot. Read the current membership first, then take every affected
        // Course lock before the Classification lock. The subsequent version
        // check rejects a membership change that landed between this snapshot
        // and the locks without ever reversing the publish lock order.
        $affectedCourseIds = collect($this->canonicalCourseIds($classification))
            ->merge($courseIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        DB::transaction(function () use (
            $classification,
            $validated,
            $courseIds,
            $affectedCourseIds,
            $editorVersion
        ): void {
            $this->lockCoursesForHomeMembership($affectedCourseIds);
            // Eligibility may change after request validation while this save
            // waits for a concurrent publish. Never attach a now-hidden course.
            $this->validatedHomeCourseIds($courseIds);
            $locked = Classification::query()->whereKey($classification->id)->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => "عدّل شخص آخر هذا التصنيف\nأعد تحميل الصفحة قبل الحفظ",
                ]);
            }
            $locked->update($validated);
            $this->syncCanonicalHomeMembership($locked, $courseIds);
        }, 3);
        return redirect()->route('admin.classifications.index')
            ->with('success', 'تم تحديث التصنيف بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Classification  $classification
     * @return \Illuminate\Http\Response
     */
    public function destroy(Classification $classification)
    {
        $blocked = DB::transaction(function () use ($classification): bool {
            $locked = Classification::query()->whereKey($classification->id)->lockForUpdate()->firstOrFail();
            if ($locked->courses()->exists()) return true;
            $locked->delete();
            return false;
        }, 3);
        if ($blocked) {
            return redirect()->route('admin.classifications.index')
                ->with('error', 'انقل الكورسات إلى تصنيف آخر قبل حذف هذا التصنيف');
        }
        return redirect()->route('admin.classifications.index')
            ->with('success', 'تم حذف التصنيف بنجاح');
    }

    private function editorVersion(Classification $classification): string
    {
        return hash('sha256', json_encode([
            $classification->name_ar,
            $classification->name_en,
            (bool) $classification->show_on_home,
            (int) $classification->home_order,
            $this->canonicalCourseIds($classification),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return Collection<int, Course> */
    private function homeCourseOptions(): Collection
    {
        return $this->onlyCanonicalCourses(Course::query())
            ->select(['id', 'name_ar', 'name_en', 'home_sort_order', 'is_coming_soon'])
            ->where('is_catalog_visible', true)
            ->orderBy('home_sort_order')
            ->orderBy('name_ar')
            ->orderBy('id')
            ->get();
    }

    /** @param array<int, mixed> $courseIds @return array<int, int> */
    private function validatedHomeCourseIds(array $courseIds): array
    {
        $ids = collect($courseIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $eligibleIds = $this->onlyCanonicalCourses(Course::query())
            ->whereIn('id', $ids)
            ->where('is_catalog_visible', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values();
        if ($eligibleIds->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'course_ids' => 'اختر كورسات ظاهرة في الكتالوج فقط',
            ]);
        }

        return $eligibleIds->all();
    }

    /** @return array<int, int> */
    private function canonicalCourseIds(Classification $classification): array
    {
        return $this->onlyCanonicalCourses($classification->courses())
            ->pluck('courses.id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    private function visibleCanonicalCourseIds(Classification $classification): array
    {
        return $this->onlyCanonicalCourses($classification->courses())
            ->where('is_catalog_visible', true)
            ->pluck('courses.id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Limit home curation to real course identities. Working drafts and retained
     * archives deliberately keep their own classification snapshots for the
     * staged three-way merge and must never appear as extra home-row courses.
     */
    private function onlyCanonicalCourses($courses)
    {
        return $courses->whereNotIn(
            'courses.id',
            CourseAuthoringRevision::query()->select('revision_course_id')
        );
    }

    /** @param array<int, int> $courseIds */
    private function syncCanonicalHomeMembership(
        Classification $classification,
        array $courseIds
    ): void {
        $requested = collect($courseIds)->map(fn ($id): int => (int) $id)
            ->unique()->sort()->values();
        // The editor deliberately lists visible catalogue courses only. Sync
        // that visible subset and retain hidden taxonomy membership verbatim.
        $current = collect($this->visibleCanonicalCourseIds($classification));
        $detach = $current->diff($requested)->values()->all();
        $attach = $requested->diff($current)->values()->all();

        if ($detach !== []) {
            $classification->courses()->detach($detach);
        }
        if ($attach !== []) {
            $classification->courses()->attach($attach);
        }
    }

    /**
     * Course publishing takes the canonical course lock before it reads and
     * replaces classification_course. Curation takes the same affected course
     * locks first, in a stable order, before it locks the Classification and
     * syncs. This also matches the parent-lock order imposed by pivot FKs, so
     * one operation completes before the other without a deadlock cycle.
     *
     * @param array<int, int> $courseIds
     */
    private function lockCoursesForHomeMembership(array $courseIds): void
    {
        $ids = collect($courseIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($ids === []) {
            return;
        }

        Course::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }
}
