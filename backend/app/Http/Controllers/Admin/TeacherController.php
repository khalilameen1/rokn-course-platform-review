<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TeacherIndexRequest;
use App\Http\Requests\Admin\TeacherProfileRequest;
use App\Http\Requests\Admin\TeacherStatusRequest;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminTeacherAuthoringService;
use App\Services\AdminTeacherReadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TeacherController extends Controller
{
    public function __construct(
        private readonly AdminPermissionMatrix $permissions,
        private readonly AdminTeacherAuthoringService $authoring,
        private readonly AdminTeacherReadService $teachers
    ) {}

    public function index(TeacherIndexRequest $request): View
    {
        $canManageCredentials = $this->canManageCredentials($request);
        $teachers = $this->teachers->listing(
            (string) $request->validated('search', ''), $canManageCredentials, $request->query()
        );
        $canDeleteTeacher = $this->permissions->allows(
            $request->user()?->role, 'admin.teachers.destroy', 'DELETE'
        );

        return view('admin.teachers.index', compact('teachers', 'canManageCredentials', 'canDeleteTeacher'));
    }

    public function create(Request $request): View
    {
        return view('admin.teachers.create', [
            'canManageCredentials' => $this->canManageCredentials($request),
        ]);
    }

    public function store(TeacherProfileRequest $request, AdminAuthoringCreateIntentService $createIntents): RedirectResponse
    {
        $this->authoring->create(
            $request->profilePayload(),
            $request->boolean('active'),
            (string) $request->validated('authoring_request_id'),
            $request->file('image'),
            function (User $teacher) use ($request, $createIntents): void {
                $createIntents->completeRedirect(
                    $request, route('admin.teachers.index'), 302, User::class, $teacher->id
                );
            }
        );

        return redirect()->route('admin.teachers.index')->with('success', 'تم إضافة المعلم بنجاح');
    }

    public function show(Request $request, $id): View
    {
        $canManageCredentials = $this->canManageCredentials($request);
        $canViewEnrollmentCounts = $this->permissions->isAdministrator($request->user()?->role);

        return view('admin.teachers.show', [
            ...$this->teachers->detail((int) $id, $canManageCredentials, $canViewEnrollmentCounts),
            'canManageCredentials' => $canManageCredentials,
            'canViewEnrollmentCounts' => $canViewEnrollmentCounts,
        ]);
    }

    public function edit(Request $request, $id): View
    {
        return view('admin.teachers.edit', [
            ...$this->teachers->editor((int) $id),
            'canManageCredentials' => $this->canManageCredentials($request),
        ]);
    }

    public function update(TeacherProfileRequest $request, $id): RedirectResponse
    {
        $this->authoring->update(
            (int) $id,
            $request->profilePayload(),
            $request->boolean('active'),
            (string) $request->validated('editor_version'),
            $request->file('image')
        );

        return redirect()->route('admin.teachers.index')->with('success', 'تم تعديل بيانات المعلم بنجاح');
    }

    public function destroy($id): RedirectResponse
    {
        if (! $this->authoring->delete((int) $id)) {
            return redirect()->route('admin.teachers.index')
                ->with('error', 'انقل الكورسات إلى مدرب آخر قبل حذف هذا المدرب');
        }

        return redirect()->route('admin.teachers.index')->with('success', 'تم حذف المعلم بنجاح');
    }

    public function deactive(TeacherStatusRequest $request, $id): RedirectResponse
    {
        $teacher = $this->authoring->toggleActive((int) $id, (bool) $request->validated('expected_active'));

        return redirect()->back()->with('success', $teacher->active ? 'تم التفعيل بنجاح' : 'تم التعطيل بنجاح');
    }

    private function canManageCredentials(Request $request): bool
    {
        return $this->permissions->allowsCapability(
            $request->user()?->role, AdminPermissionMatrix::ACCOUNT_CREDENTIALS
        );
    }
}
