<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use App\Services\StoredFileDeletionService;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public function __construct(
        private readonly AdminPermissionMatrix $permissions
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
            ->withCount('teachingCourses');

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
        $intentTeacherId = User::withTrashed()
            ->where('authoring_request_id', (string) $request->input('authoring_request_id'))
            ->value('id');
        $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'email' => $canManageCredentials
                ? ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($intentTeacherId)]
                : ['prohibited'],
            'phone' => $canManageCredentials
                ? ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($intentTeacherId)]
                : ['prohibited'],
            'password' => $canManageCredentials
                ? ['required', 'string', 'min:10', 'max:72', 'confirmed']
                : ['prohibited'],
            'password_confirmation' => $canManageCredentials ? ['required', 'same:password'] : ['prohibited'],
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'job_title' => 'nullable|string|max:255',
            'bio_ar' => 'nullable|string',
            'bio_en' => 'nullable|string',
            'authoring_request_id' => 'required|uuid',
        ]);

        $requestId = (string) $request->input('authoring_request_id');
        $imagePath = $request->hasFile('image')
            ? app(StoredFileDeletionService::class)
                ->storeTrackedUpload(
                    $request->file('image'),
                    'users',
                    'public',
                    60,
                    'admin-teacher|'.strtolower($requestId).'|'.hash_file('sha256', $request->file('image')->getRealPath())
                )
            : null;
        if ($request->hasFile('image') && (!is_string($imagePath) || $imagePath === '')) {
            throw new \RuntimeException('Teacher image storage failed');
        }
        try {
            DB::transaction(function () use ($request, $imagePath, $requestId, $createIntents, $canManageCredentials): void {
                $teacher = User::withTrashed()->where('authoring_request_id', $requestId)
                    ->lockForUpdate()->first();
                if (!$teacher) {
                    $teacher = new User();
                    $teacher->fill([
                        'name_ar' => $request->string('name_ar')->trim(),
                        'name_en' => $request->filled('name_en') ? $request->string('name_en')->trim() : null,
                        'email' => $canManageCredentials ? strtolower($request->string('email')->trim()) : null,
                        'phone' => $canManageCredentials ? (string) $request->string('phone')->trim() : null,
                        'password' => $canManageCredentials ? Hash::make((string) $request->input('password')) : null,
                        'job_title' => $request->input('job_title'),
                        'bio_ar' => $request->input('bio_ar'),
                        'bio_en' => $request->input('bio_en'),
                        'authoring_request_id' => $requestId,
                    ]);
                    // Role is intentionally guarded against request mass
                    // assignment. Persist it with the profile in one insert;
                    // saving first violates the users.role NOT NULL contract.
                    $teacher->forceFill([
                        'role' => 'teacher',
                        'active' => $request->boolean('active'),
                    ])->save();
                }
                if ($imagePath) {
                    $teacher->allPhotos()->firstOrCreate(['path' => $imagePath, 'type' => 'featured']);
                }
                $createIntents->completeRedirect(
                    $request,
                    route('admin.teachers.index'),
                    302,
                    User::class,
                    $teacher->id
                );
            }, 3);
        } catch (\Throwable $exception) {
            if ($imagePath) app(StoredFileDeletionService::class)->deleteOrQueue('public', $imagePath);
            throw $exception;
        }

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
            ->findOrFail($id);
        $canViewEnrollmentCounts = $this->permissions->isAdministrator($request->user()?->role);
        $coursesQuery = $teacher->teachingCourses()
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
        $teacher = User::where('role', 'teacher')->findOrFail($id);
        return view('admin.teachers.edit', [
            'teacher' => $teacher,
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

        $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            'email' => $canManageCredentials
                ? ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($teacher->id)]
                : ['prohibited'],
            'phone' => $canManageCredentials
                ? ['required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($teacher->id)]
                : ['prohibited'],
            'password' => $canManageCredentials
                ? ['nullable', 'string', 'min:10', 'max:72', 'confirmed']
                : ['prohibited'],
            'password_confirmation' => $canManageCredentials ? ['nullable', 'same:password'] : ['prohibited'],
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'job_title' => 'nullable|string|max:255',
            'bio_ar' => 'nullable|string',
            'bio_en' => 'nullable|string',
            'editor_version' => 'required|string|size:64',
        ]);

        $userData = $request->only(['name_ar', 'name_en', 'job_title', 'bio_ar', 'bio_en']);
        if ($canManageCredentials) {
            $userData['email'] = strtolower(trim((string) $request->input('email')));
            $userData['phone'] = trim((string) $request->input('phone'));
        }
        if ($canManageCredentials && $request->filled('password')) {
            $userData['password'] = Hash::make($request->password);
        }

        $newImagePath = $request->hasFile('image')
            ? app(StoredFileDeletionService::class)
                ->storeTrackedUpload($request->file('image'), 'users')
            : null;
        if ($request->hasFile('image') && (!is_string($newImagePath) || $newImagePath === '')) {
            throw new \RuntimeException('Teacher image storage failed');
        }
        try {
            DB::transaction(function () use ($request, $teacher, $userData, $newImagePath): void {
                $locked = User::query()->whereKey($teacher->id)->where('role', 'teacher')
                    ->lockForUpdate()->firstOrFail();
                if (!hash_equals($this->editorVersion($locked), (string) $request->input('editor_version'))) {
                    throw ValidationException::withMessages([
                        'editor_version' => 'عدّل شخص آخر بيانات المحاضر\nأعد تحميل الصفحة قبل الحفظ',
                    ]);
                }
                if ($locked->active && !$request->boolean('active')) {
                    $this->assertCanDeactivate($locked);
                }
                $locked->update($userData);
                $locked->forceFill(['active' => $request->boolean('active')])->save();
                if ($newImagePath) {
                    $oldPhotos = $locked->allPhotos()->where('type', 'featured')->lockForUpdate()->get();
                    $locked->allPhotos()->create(['path' => $newImagePath, 'type' => 'featured']);
                    $oldPhotos->each->delete();
                }
            }, 3);
        } catch (\Throwable $exception) {
            if ($newImagePath) app(StoredFileDeletionService::class)->deleteOrQueue('public', $newImagePath);
            throw $exception;
        }
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
        $blocked = DB::transaction(function () use ($id): bool {
            $teacher = User::query()->where('role', 'teacher')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($teacher->teachingCourses()->exists()) return true;
            $teacher->delete();
            return false;
        }, 3);
        if ($blocked) {
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
        $teacher = DB::transaction(function () use ($id, $validated): User {
            $teacher = User::query()->where('role', 'teacher')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ((bool) $teacher->active !== (bool) $validated['expected_active']) {
                throw ValidationException::withMessages([
                    'expected_active' => 'تغيّرت حالة المحاضر بالفعل\nأعد تحميل الصفحة',
                ]);
            }
            if ($teacher->active) $this->assertCanDeactivate($teacher);
            $teacher->forceFill(['active' => !$teacher->active])->save();
            return $teacher;
        }, 3);
        return redirect()->back()->with('success', $teacher->active ? 'تم التفعيل بنجاح' : 'تم التعطيل بنجاح');
    }

    private function editorVersion(User $teacher): string
    {
        return hash('sha256', json_encode([
            $teacher->name_ar, $teacher->name_en, $teacher->email, $teacher->phone,
            $teacher->job_title, $teacher->bio_ar, $teacher->bio_en, (bool) $teacher->active,
            $teacher->photo?->path,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function assertCanDeactivate(User $teacher): void
    {
        $publishedCourses = Course::query()
            ->where('is_coming_soon', false)
            ->where(function ($courses) use ($teacher): void {
                $courses->where('teacher_id', $teacher->id)
                    ->orWhereHas('teachers', fn ($teachers) => $teachers->whereKey($teacher->id));
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($publishedCourses as $course) {
            $hasOtherActiveTeacher = $course->teachers()
                ->where('users.id', '<>', $teacher->id)
                ->where('users.active', true)
                ->exists();
            if (!$hasOtherActiveTeacher && (int) $course->teacher_id !== (int) $teacher->id) {
                $hasOtherActiveTeacher = User::query()
                    ->whereKey($course->teacher_id)
                    ->whereIn('role', ['teacher', 'admin'])
                    ->where('active', true)
                    ->exists();
            }
            if (!$hasOtherActiveTeacher) {
                throw ValidationException::withMessages([
                    'active' => "اربط الكورس «{$course->name_ar}» بمحاضر نشط آخر قبل تعطيل هذا المحاضر",
                ]);
            }
        }
    }

    private function canManageCredentials(Request $request): bool
    {
        return $this->permissions->allowsCapability(
            $request->user()?->role,
            AdminPermissionMatrix::ACCOUNT_CREDENTIALS
        );
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
