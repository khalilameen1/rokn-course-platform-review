<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\DesignSetting;
use App\Models\User;
use App\Models\UserNote;
use App\Services\DeviceLoginService;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminStudentReadService;
use App\Services\StudentAccountStateService;
use App\Support\AdminEditorVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UsersController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }
    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request, AdminStudentReadService $students)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', 'in:0,1'],
        ]);

        return view('admin.users.index', array_merge(
            $students->listing($filters, $request->query()),
            ['designSettings' => $this->getDesignSettings()]
        ));
    }


    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function create()
    {
        $designSettings = $this->getDesignSettings();
        return view('admin.users.create', compact('designSettings'));
    }


    /**
     * @param UserRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(UserRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->validated();
        $requestId = (string) $validated['authoring_request_id'];
        $user = User::withTrashed()->where('authoring_request_id', $requestId)->first();
        if (!$user) {
            $user = DB::transaction(function () use (
                $request,
                $validated,
                $requestId,
                $createIntents
            ): User {
                $user = new User();
                $user->name = $validated['name'];
                $user->email = strtolower(trim($validated['email']));
                $user->phone = trim($validated['phone']);
                // Learner authentication is social-only. Keep a non-usable
                // database value for the legacy non-null column without
                // presenting or accepting a password credential in the admin.
                $user->password = bcrypt(\Illuminate\Support\Str::random(64));
                $user->authoring_request_id = $requestId;
                $user->forceFill([
                    'role' => 'client',
                    'active' => true,
                    'is_online' => false,
                    // Creating a learner in the dashboard reserves the row;
                    // it does not prove ownership of the email address. The
                    // linked social provider remains the only identity proof.
                    'email_verified_at' => null,
                ])->save();
                $createIntents->checkpointResource($request, User::class, $user->id);
                return $user;
            }, 3);
        } else {
            DB::transaction(function () use ($request, $user, $createIntents): void {
                User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $createIntents->checkpointResource($request, User::class, $user->id);
            }, 3);
        }

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $user->storeImage(
                $file,
                'users',
                'featured',
                'admin-student|'.strtolower($requestId).'|'.hash_file('sha256', $file->getRealPath())
            );
        }

        DB::transaction(function () use ($request, $user, $createIntents): void {
            $locked = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $createIntents->completeRedirect(
                $request,
                route('admin.users.index'),
                302,
                User::class,
                $locked->id
            );
        }, 3);

        return redirect()->route('admin.users.index')->with('success', 'تمت الإضافة بنجاح ');
    }


    /**
     * @param User $user
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function show(
        User $user,
        Request $request,
        AdminStudentReadService $students
    )
    {
        $this->assertStudent($user);

        return view('admin.users.show', array_merge(
            $students->workspace($user, $request->query()),
            ['designSettings' => $this->getDesignSettings()]
        ));
    }

    /**
     * @param User $user
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function edit(User $user)
    {
        $this->assertStudent($user);
        $designSettings = $this->getDesignSettings();
        $editorVersion = $this->editorVersion($user);
        return view('admin.users.edit', compact('user', 'designSettings', 'editorVersion'));
    }


    /**
     * @param UserRequest $request
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UserRequest $request, User $user)
    {

        abort_if(in_array(strtolower((string) $user->role), ['admin', 'moderator', 'teacher'], true), 403);

        $validated = $request->validated();
        $editorVersion = (string) $validated['editor_version'];
        DB::transaction(function () use ($user, $validated, $editorVersion): void {
            $locked = User::query()->students()->whereKey($user->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => ["تغيّرت بيانات الطالب منذ فتح الصفحة\nأعد تحميلها قبل الحفظ"],
                ]);
            }

            $email = strtolower(trim((string) $validated['email']));
            $updates = [
                'name' => $validated['name'],
                'email' => $email,
                'phone' => trim((string) $validated['phone']),
                'profile_revision' => (int) $locked->profile_revision + 1,
            ];
            if (!hash_equals(strtolower(trim((string) $locked->email)), $email)) {
                $updates['email_verified_at'] = null;
            }
            $locked->forceFill($updates)->save();
        }, 3);

        return redirect()->route('admin.users.show', $user->id)->with('success', 'تم التعديل بنجاح');
    }


    /**
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deactive(
        Request $request,
        User $user,
        StudentAccountStateService $accounts
    )
    {
        abort_if(in_array(strtolower((string) $user->role), ['admin', 'moderator', 'teacher'], true), 403);
        $validated = $request->validate([
            'expected_active' => ['required', 'boolean'],
            'state_version' => ['required', 'string', 'size:64'],
        ]);
        $user = $accounts->setActive(
            $user,
            (bool) $validated['expected_active'],
            (string) $validated['state_version'],
            !(bool) $validated['expected_active']
        );
        return redirect()->back()->with('success', $user->active ? 'تم التفعيل بنجاح' : 'تم التعطيل بنجاح');
    }

    private function editorVersion(User $user): string
    {
        return AdminEditorVersion::for($user, [
            'name', 'email', 'phone', 'profile_revision', 'email_verified_at',
        ]);
    }

    private function deviceEditorVersion(User $user): string
    {
        return AdminEditorVersion::for($user, [
            'locked_device_id', 'profile_revision', 'deleted_at',
        ]);
    }

    /**
     * Store a new note for the user.
     */
    public function storeNote(
        Request $request,
        User $user,
        AdminAuthoringCreateIntentService $createIntents
    )
    {
        $validated = $request->validate([
            'note' => 'required|string|max:1000',
            'authoring_request_id' => 'required|uuid',
        ]);

        DB::transaction(function () use ($request, $user, $validated, $createIntents): void {
            $locked = User::query()->students()->whereKey($user->id)
                ->lockForUpdate()->firstOrFail();
            $note = $locked->notes()->create([
                'note' => $validated['note'],
                'created_by' => auth()->id(),
            ]);
            $createIntents->checkpointResource($request, UserNote::class, $note->id);
            $createIntents->completeRedirect(
                $request,
                route('admin.users.show', $locked->id),
                302,
                UserNote::class,
                $note->id
            );
        }, 3);

        return redirect()->route('admin.users.show', $user->id)
            ->with('success', 'تم إضافة الملاحظة بنجاح');
    }

    /**
     * Delete a note.
     */
    public function deleteNote(UserNote $note)
    {
        // Check if the current user can delete this note
        if ($note->created_by !== auth()->id() && auth()->user()->role !== 'admin') {
            return redirect()->back()->with('error', 'غير مصرح لك بحذف هذه الملاحظة');
        }

        $note->delete();

        return redirect()->back()->with('success', 'تم حذف الملاحظة بنجاح');
    }

    /**
     * Reset the locked device for a user (single_device_permanent policy).
     */
    public function resetDevice(
        Request $request,
        User $user,
        DeviceLoginService $deviceLogin
    )
    {
        $validated = $request->validate([
            'state_version' => ['required', 'string', 'size:64'],
            'expected_policy' => ['required', 'string'],
        ]);
        $policy = $deviceLogin->configuredPolicy();
        if (
            $validated['expected_policy'] !== DeviceLoginService::POLICY_SINGLE_PERMANENT
            || $policy !== DeviceLoginService::POLICY_SINGLE_PERMANENT
        ) {
            throw ValidationException::withMessages([
                'expected_policy' => ["تغيّرت سياسة الأجهزة\nأعد تحميل الصفحة"],
            ]);
        }

        DB::transaction(function () use ($user, $validated): void {
            $locked = User::query()->students()->whereKey($user->id)
                ->lockForUpdate()->firstOrFail();
            if (
                trim((string) $locked->locked_device_id) === ''
                || !hash_equals($this->deviceEditorVersion($locked), (string) $validated['state_version'])
            ) {
                throw ValidationException::withMessages([
                    'state_version' => ["تغيّرت جلسات الطالب بالفعل\nأعد تحميل الصفحة"],
                ]);
            }

            $locked->purgeApiTokens();
            $locked->deviceTokens()->delete();
            $locked->forceFill([
                'locked_device_id' => null,
                'profile_revision' => (int) $locked->profile_revision + 1,
            ])->save();
        }, 3);

        return redirect()->back()->with(
            'success',
            'تم إعادة تعيين الجهاز بنجاح. يمكن للطالب الآن تسجيل الدخول من جهاز جديد.'
        );
    }

    private function assertStudent(User $user): void
    {
        abort_unless(strtolower(trim((string) $user->role)) === 'client', 404);
    }
}
