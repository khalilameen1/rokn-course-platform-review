<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\TeacherResource;
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

    public function index(TeacherIndexRequest $request): RedirectResponse
    {
        return redirect(TeacherResource::getUrl('index', ['search' => $request->validated('search', '')]));
    }

    public function create(Request $request): RedirectResponse
    {
        return redirect(TeacherResource::getUrl('create'));
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
        $canViewEnrollmentCounts = $this->permissions->isAdministrator($request->user());

        return view('admin.teachers.show', [
            ...$this->teachers->detail((int) $id, $canManageCredentials, $canViewEnrollmentCounts),
            'canManageCredentials' => $canManageCredentials,
            'canViewEnrollmentCounts' => $canViewEnrollmentCounts,
        ]);
    }

    public function edit(Request $request, $id): RedirectResponse
    {
        return redirect(TeacherResource::getUrl('edit', ['record' => $id]));
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
            $request->user(), AdminPermissionMatrix::ACCOUNT_CREDENTIALS
        );
    }
}
