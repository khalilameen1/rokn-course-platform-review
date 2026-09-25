<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\CourseAuthoringRevision;
use Illuminate\Http\Request;
use App\Services\AdminTeacherAuthoringService;
use App\Support\TeacherEditorVersion;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public function __construct(
        private readonly AdminPermissionMatrix $permissions,
        private readonly AdminTeacherAuthoringService $authoring
    ) {
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $canManageCredentials = $this->canManageCredentials($request);
        $teachers = User::query()
            ->select($this->teacherColumns($canManageCredentials))
            ->where('role', 'teacher')
            ->with('photo')
            ->withCount([
                'teachingCourses' => fn ($courses) => $this->onlyCanonicalCourses($courses),
            ]);

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $teachers->where(function($query) use ($search, $canManageCredentials) {
                $query->where('name_ar', 'LIKE', "%{$search}%")
                      ->orWhere('name_en', 'LIKE', "%{$search}%");
                if ($canManageCredentials) {
                    $query->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                }
            });
        }

        $teachers = $teachers->latest()->latest('id')->paginate(10)->withQueryString();
        $canDeleteTeacher = $this->permissions->allows(
            $request->user()?->role,
            'admin.teachers.destroy',
            'DELETE'
        );

        return view('admin.teachers.index', compact(
            'teachers',
            'canManageCredentials',
            'canDeleteTeacher'
        ));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        return view('admin.teachers.create', [
            'canManageCredentials' => $this->canManageCredentials($request),
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
        $canManageCredentials = $this->canManageCredentials($request);
        $manageCredentials = $canManageCredentials && $request->boolean('manage_credentials');
        $intentTeacherId = User::withTrashed()
            ->where('authoring_request_id', (string) $request->input('authoring_request_id'))
            ->value('id');
        $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            ...$this->credentialRules($request, $intentTeacherId),
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'job_title' => 'nullable|string|max:255',
            'bio_ar' => 'nullable|string',
            'bio_en' => 'nullable|string',
            'authoring_request_id' => 'required|uuid',
        ]);

        $payload = [
            'name_ar' => (string) $request->string('name_ar')->trim(),
            'name_en' => $request->filled('name_en') ? (string) $request->string('name_en')->trim() : null,
            'email' => $manageCredentials && $request->filled('email')
                ? strtolower((string) $request->string('email')->trim()) : null,
            'phone' => $manageCredentials && $request->filled('phone')
                ? (string) $request->string('phone')->trim() : null,
            'password' => $manageCredentials && $request->filled('password')
                ? (string) $request->input('password') : null,
            'job_title' => $request->input('job_title'),
            'bio_ar' => $request->input('bio_ar'),
            'bio_en' => $request->input('bio_en'),
        ];
        $this->authoring->create(
            $payload,
            $request->boolean('active'),
            (string) $request->input('authoring_request_id'),
            $request->file('image'),
            function (User $teacher) use ($request, $createIntents): void {
                $createIntents->completeRedirect(
                    $request,
                    route('admin.teachers.index'),
                    302,
                    User::class,
                    $teacher->id
                );
            }
        );

        return redirect()->route('admin.teachers.index')->with('success', 'تم إضافة المعلم بنجاح');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        $canManageCredentials = $this->canManageCredentials($request);
        $teacher = User::query()
            ->select($this->teacherColumns($canManageCredentials))
            ->where('role', 'teacher')
            ->with('photo')
            ->findOrFail($id);
        $canViewEnrollmentCounts = $this->permissions->isAdministrator($request->user()?->role);
        $coursesQuery = $this->onlyCanonicalCourses($teacher->teachingCourses())
            ->orderByDesc('courses.id');
        if ($canViewEnrollmentCounts) {
            $coursesQuery->withCount('activeEnrollments');
        }
        $courses = $coursesQuery->paginate(10);

        return view('admin.teachers.show', compact(
            'teacher',
            'courses',
            'canViewEnrollmentCounts',
            'canManageCredentials'
        ));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $id)
    {
        $teacher = User::query()->where('role', 'teacher')->with('photo')->findOrFail($id);
        return view('admin.teachers.edit', [
            'teacher' => $teacher,
            'editorVersion' => TeacherEditorVersion::for($teacher),
            'canManageCredentials' => $this->canManageCredentials($request),
        ]);
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
        $teacher = User::where('role', 'teacher')->findOrFail($id);
        $canManageCredentials = $this->canManageCredentials($request);
        $manageCredentials = $canManageCredentials && $request->boolean('manage_credentials');

        $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            ...$this->credentialRules($request, $teacher->id),
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'job_title' => 'nullable|string|max:255',
            'bio_ar' => 'nullable|string',
            'bio_en' => 'nullable|string',
            'editor_version' => 'required|string|size:64',
        ]);

        $userData = $request->only(['name_ar', 'name_en', 'job_title', 'bio_ar', 'bio_en']);
        if ($manageCredentials && $request->filled('email')) {
            $userData['email'] = strtolower(trim((string) $request->input('email')));
        }
        if ($manageCredentials && $request->filled('phone')) {
            $userData['phone'] = trim((string) $request->input('phone'));
        }
        if ($manageCredentials && $request->filled('password')) {
            $userData['password'] = (string) $request->input('password');
        }

        $this->authoring->update(
            (int) $teacher->id,
            $userData,
            $request->boolean('active'),
            (string) $request->input('editor_version'),
            $request->file('image')
        );
        return redirect()->route('admin.teachers.index')->with('success', 'تم تعديل بيانات المعلم بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (!$this->authoring->delete((int) $id)) {
            return redirect()->route('admin.teachers.index')
                ->with('error', 'انقل الكورسات إلى مدرب آخر قبل حذف هذا المدرب');
        }

        return redirect()->route('admin.teachers.index')->with('success', 'تم حذف المعلم بنجاح');
    }

    /**
     * Toggle active status.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function deactive(Request $request, $id)
    {
        $validated = $request->validate(['expected_active' => 'required|boolean']);
        $teacher = $this->authoring->toggleActive((int) $id, (bool) $validated['expected_active']);
        return redirect()->back()->with('success', $teacher->active ? 'تم التفعيل بنجاح' : 'تم التعطيل بنجاح');
    }

    private function canManageCredentials(Request $request): bool
    {
        return $this->permissions->allowsCapability(
            $request->user()?->role,
            AdminPermissionMatrix::ACCOUNT_CREDENTIALS
        );
    }

    private function onlyCanonicalCourses($courses)
    {
        return $courses->whereNotIn(
            'courses.id',
            CourseAuthoringRevision::query()->select('revision_course_id')
        );
    }

    /** @return array<string, list<mixed>> */
    private function credentialRules(Request $request, ?int $teacherId = null): array
    {
        if (!$this->canManageCredentials($request)) {
            return [
                'manage_credentials' => ['prohibited'],
                'email' => ['prohibited'],
                'phone' => ['prohibited'],
                'password' => ['prohibited'],
                'password_confirmation' => ['prohibited'],
            ];
        }

        if (!$request->boolean('manage_credentials')) {
            return [
                'manage_credentials' => ['nullable', 'boolean'],
                'email' => ['exclude'],
                'phone' => ['exclude'],
                'password' => ['exclude'],
                'password_confirmation' => ['exclude'],
            ];
        }

        return [
            'manage_credentials' => ['nullable', 'boolean'],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($teacherId)],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($teacherId)],
            'password' => ['nullable', 'string', 'min:10', 'max:72', 'confirmed'],
            'password_confirmation' => ['nullable', 'same:password'],
        ];
    }

    /** @return list<string> */
    private function teacherColumns(bool $includeCredentials): array
    {
        $columns = [
            'id',
            'name_ar',
            'name_en',
            'job_title',
            'bio_ar',
            'bio_en',
            'profile_image',
            'active',
            'role',
            'created_at',
        ];

        if ($includeCredentials) {
            $columns[] = 'email';
            $columns[] = 'phone';
        }

        return $columns;
    }
}
