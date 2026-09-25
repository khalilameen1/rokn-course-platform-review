<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Path;
use App\Services\AdminPathReadService;
use App\Services\AdminPathAuthoringService;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PathController extends Controller
{
    public function __construct(
        private readonly AdminPathReadService $read,
        private readonly AdminPathAuthoringService $authoring
    ) {
    }

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
        return view('admin.paths.create', $this->read->form());
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

        $this->authoring->create($validated, function (Path $path) use ($request, $createIntents): void {
            $createIntents->completeRedirect($request, route('admin.paths.index'), 302, Path::class, $path->id);
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
        return view('admin.paths.edit', $this->read->form((int) $id));
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

        $this->authoring->update((int) $id, $validated, (string) $validated['editor_version']);

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
        if (!$this->authoring->deleteIfUnused((int) $id)) {
            return redirect()->route('admin.paths.index')
                ->with('error', 'انقل الكورسات إلى مسار آخر قبل حذف هذا المسار');
        }

        return redirect()->route('admin.paths.index')->with('success', 'تم حذف المسار بنجاح');
    }

}
