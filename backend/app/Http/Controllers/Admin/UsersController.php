<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\StudentResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StudentDeviceResetRequest;
use App\Http\Requests\Admin\StudentIndexRequest;
use App\Http\Requests\Admin\StudentNoteRequest;
use App\Http\Requests\Admin\StudentStateRequest;
use App\Http\Requests\Admin\UserRequest;
use App\Models\User;
use App\Models\UserNote;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminStudentAuthoringService;
use App\Services\AdminStudentNoteService;
use App\Services\AdminStudentReadService;
use App\Services\StudentAccountStateService;
use App\Support\CsvCell;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UsersController extends Controller
{
    public function __construct(
        private readonly AdminStudentAuthoringService $authoring,
        private readonly AdminStudentNoteService $notes
    ) {}

    public function index(StudentIndexRequest $request): RedirectResponse
    {
        return redirect()->to(StudentResource::listUrl($request->validated(), max(1, $request->integer('page', 1))));
    }

    /** Streamed CSV of the current list filters (UTF-8 BOM so Excel reads Arabic). */
    public function export(StudentIndexRequest $request, AdminStudentReadService $students): StreamedResponse
    {
        $filters = $request->validated();

        return response()->streamDownload(function () use ($students, $filters): void {
            $file = fopen('php://output', 'wb');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['student_id', 'name', 'email', 'phone', 'active', 'marketing_opt_in', 'created_at']);
            $students->eachForExport($filters, function (User $user) use ($file): void {
                fputcsv($file, CsvCell::row([
                    $user->id,
                    $user->name ?: ($user->name_ar ?: $user->name_en),
                    $user->email,
                    $user->phone,
                    $user->active ? 1 : 0,
                    $user->marketing_notifications_enabled ? 1 : 0,
                    $user->created_at?->toIso8601String(),
                ]));
            });
            fclose($file);
        }, 'students-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(): RedirectResponse
    {
        return redirect()->to(StudentResource::getUrl('create'));
    }

    /**
     * @return RedirectResponse
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

    public function show(User $user): RedirectResponse
    {
        $this->assertStudent($user);

        return redirect()->to(StudentResource::getUrl('view', ['record' => $user->id]));
    }

    public function edit(User $user): RedirectResponse
    {
        $this->assertStudent($user);

        return redirect()->to(StudentResource::getUrl('edit', ['record' => $user->id]));
    }

    /**
     * @return RedirectResponse
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
     * @return RedirectResponse
     */
    public function deactive(
        StudentStateRequest $request,
        User $user,
        StudentAccountStateService $accounts
    ) {
        abort_if(in_array(strtolower((string) $user->role), ['admin', 'moderator', 'teacher'], true), 403);
        $validated = $request->validated();
        $user = $accounts->setActive(
            $user,
            (bool) $validated['expected_active'],
            (string) $validated['state_version'],
            ! (bool) $validated['expected_active']
        );

        return redirect()->back()->with('success', $user->active ? 'تم التفعيل بنجاح' : 'تم التعطيل بنجاح');
    }

    /**
     * Store a new note for the user.
     */
    public function storeNote(
        StudentNoteRequest $request,
        User $user,
        AdminAuthoringCreateIntentService $createIntents
    ) {
        $validated = $request->validated();

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
        if (! $this->notes->delete((int) $note->id, (int) $request->user()->id, app(AdminPermissionMatrix::class)->allows($request->user(), 'admin.users.notes.delete', 'DELETE'))) {
            return redirect()->back()->with('error', 'غير مصرح لك بحذف هذه الملاحظة');
        }

        return redirect()->back()->with('success', 'تم حذف الملاحظة بنجاح');
    }

    /**
     * Reset the locked device for a user (single_device_permanent policy).
     */
    public function resetDevice(
        StudentDeviceResetRequest $request,
        User $user,
        StudentAccountStateService $accounts
    ) {
        $validated = $request->validated();
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
