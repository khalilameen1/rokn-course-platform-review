<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\DesignSetting;
use App\Models\User;
use App\Models\UserNote;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminStudentReadService;
use App\Services\StudentAccountStateService;
use App\Support\StudentEditorVersion;
use App\Services\AdminStudentAuthoringService;
use App\Services\AdminStudentNoteService;
use Illuminate\Http\Request;

class UsersController extends Controller
{
    public function __construct(
        private readonly AdminStudentAuthoringService $authoring,
        private readonly AdminStudentNoteService $notes
    ) {
    }

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
        $this->authoring->create(
            $validated,
            (string) $validated['authoring_request_id'],
            $request->file('image'),
            function (User $user) use ($request, $createIntents): void {
                $createIntents->checkpointResource($request, User::class, $user->id);
                $createIntents->completeRedirect(
                    $request, route('admin.users.index'), 302, User::class, $user->id
                );
            }
        );

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
        $editorVersion = StudentEditorVersion::for($user);
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
        $this->authoring->update((int) $user->id, $validated, $editorVersion);

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

        $this->notes->create(
            (int) $user->id, $validated['note'], (int) $request->user()->id,
            function (UserNote $note) use ($request, $user, $createIntents): void {
                $createIntents->checkpointResource($request, UserNote::class, $note->id);
                $createIntents->completeRedirect(
                    $request, route('admin.users.show', $user->id), 302, UserNote::class, $note->id
                );
            }
        );

        return redirect()->route('admin.users.show', $user->id)
            ->with('success', 'تم إضافة الملاحظة بنجاح');
    }

    /**
     * Delete a note.
     */
    public function deleteNote(UserNote $note, Request $request)
    {
        if (!$this->notes->delete((int) $note->id, (int) $request->user()->id, $request->user()->role === 'admin')) {
            return redirect()->back()->with('error', 'غير مصرح لك بحذف هذه الملاحظة');
        }

        return redirect()->back()->with('success', 'تم حذف الملاحظة بنجاح');
    }

    /**
     * Reset the locked device for a user (single_device_permanent policy).
     */
    public function resetDevice(
        Request $request,
        User $user,
        StudentAccountStateService $accounts
    )
    {
        $validated = $request->validate([
            'state_version' => ['required', 'string', 'size:64'],
            'expected_policy' => ['required', 'string'],
        ]);
        $accounts->resetDevice(
            $user, (string) $validated['expected_policy'], (string) $validated['state_version']
        );

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
