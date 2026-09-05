<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Path;
use App\Models\Classification;
use App\Models\Course;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PathController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $paths = Path::query();

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $paths->where(function($query) use ($search) {
                $query->where('title_ar', 'LIKE', "%{$search}%")
                      ->orWhere('title_en', 'LIKE', "%{$search}%");
            });
        }

        $paths = $paths->with('interests')->latest()->latest('id')->paginate(10)->withQueryString();

        return view('admin.paths.index', compact('paths'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $interests = Classification::all();
        $courses = Course::query()->orderBy('name_ar')->get();
        return view('admin.paths.create', compact('interests', 'courses'));
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
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'interest_ids' => 'nullable|array',
            'interest_ids.*' => 'integer|distinct|exists:classifications,id',
            'course_ids' => 'nullable|array|max:200',
            'course_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('courses', 'id'),
            ],
            'authoring_request_id' => 'required|uuid',
        ]);

        unset($validated['authoring_request_id']);
        DB::transaction(function () use ($request, $validated, $createIntents): void {
            $path = Path::create([
                'title_ar' => $validated['title_ar'],
                'title_en' => $validated['title_en'],
            ]);
            $path->interests()->sync($validated['interest_ids'] ?? []);
            Course::query()
                ->whereIn('id', $validated['course_ids'] ?? [])
                ->get()
                ->each(fn (Course $course) => $course->update(['path_id' => $path->id]));
            $createIntents->completeRedirect(
                $request,
                route('admin.paths.index'),
                302,
                Path::class,
                $path->id
            );
        });

        return redirect()->route('admin.paths.index')->with('success', 'تم إضافة المسار بنجاح');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $path = Path::with(['interests', 'courses'])->findOrFail($id);
        $interests = Classification::all();
        $courses = Course::query()->orderBy('name_ar')->get();
        $editorVersion = $this->editorVersion($path);
        return view('admin.paths.edit', compact('path', 'interests', 'courses', 'editorVersion'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $path = Path::findOrFail($id);

        $validated = $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'interest_ids' => 'nullable|array',
            'interest_ids.*' => 'integer|distinct|exists:classifications,id',
            'course_ids' => 'nullable|array|max:200',
            'course_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('courses', 'id'),
            ],
            'editor_version' => 'required|string|size:64',
        ]);

        $editorVersion = (string) $validated['editor_version'];
        unset($validated['editor_version']);
        $courseIds = collect($validated['course_ids'] ?? [])
            ->map(fn ($courseId): int => (int) $courseId)
            ->unique()
            ->sort()
            ->values();
        DB::transaction(function () use ($path, $validated, $editorVersion, $courseIds): void {
            $locked = Path::query()->whereKey($path->id)->lockForUpdate()->firstOrFail();
            $affectedCourseIds = Course::query()
                ->where('path_id', $locked->id)
                ->pluck('id')
                ->merge($courseIds)
                ->unique()
                ->sort()
                ->values();
            if ($affectedCourseIds->isNotEmpty()) {
                Course::query()
                    ->whereIn('id', $affectedCourseIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
            }
            if (!hash_equals($this->editorVersion($locked), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => "عدّل شخص آخر هذا المسار\nأعد تحميل الصفحة قبل الحفظ",
                ]);
            }
            $locked->update([
                'title_ar' => $validated['title_ar'],
                'title_en' => $validated['title_en'],
            ]);
            $locked->interests()->sync($validated['interest_ids'] ?? []);
            Course::query()
                ->where('path_id', $locked->id)
                ->whereNotIn('id', $courseIds)
                ->get()
                ->each(fn (Course $course) => $course->update(['path_id' => null]));
            Course::query()
                ->whereIn('id', $courseIds)
                ->get()
                ->each(fn (Course $course) => $course->update(['path_id' => $locked->id]));
        }, 3);

        return redirect()->route('admin.paths.index')->with('success', 'تم تعديل المسار بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $path = Path::findOrFail($id);
        $blocked = DB::transaction(function () use ($path): bool {
            $locked = Path::query()->whereKey($path->id)->lockForUpdate()->firstOrFail();
            if ($locked->courses()->exists()) return true;
            $locked->interests()->detach();
            $locked->delete();
            return false;
        }, 3);
        if ($blocked) {
            return redirect()->route('admin.paths.index')
                ->with('error', 'انقل الكورسات إلى مسار آخر قبل حذف هذا المسار');
        }

        return redirect()->route('admin.paths.index')->with('success', 'تم حذف المسار بنجاح');
    }

    private function editorVersion(Path $path): string
    {
        $path->loadMissing('interests:id');
        return hash('sha256', json_encode([
            $path->title_ar,
            $path->title_en,
            collect($path->interests->modelKeys())->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            Course::query()
                ->orderBy('id')
                ->get(['id', 'path_id'])
                ->map(fn (Course $course): array => [(int) $course->id, (int) ($course->path_id ?? 0)])
                ->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
