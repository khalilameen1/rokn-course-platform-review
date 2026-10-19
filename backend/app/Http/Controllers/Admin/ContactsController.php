<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\ContactResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContactAccountDeletionRequest;
use App\Http\Requests\Admin\ContactCloseRequest;
use App\Http\Requests\Admin\EditorVersionRequest;
use App\Models\Contact;
use App\Services\AdminContactWorkflowService;
use Illuminate\Http\RedirectResponse;

class ContactsController extends Controller
{
    public function __construct(
        private readonly AdminContactWorkflowService $workflow
    ) {}

    public function index()
    {
        return redirect(ContactResource::getUrl());
    }

    public function show(Contact $contact)
    {
        return redirect(ContactResource::getUrl('view', ['record' => $contact->id]));
    }

    public function markRead(EditorVersionRequest $request, Contact $contact): RedirectResponse
    {
        $expected = $request->validated('editor_version');
        $this->workflow->markRead((int) $contact->id, $expected);

        return redirect()->route('admin.contacts.show', $contact);
    }

    public function destroy(EditorVersionRequest $request, Contact $contact)
    {
        $expected = $request->validated('editor_version');
        $this->workflow->deleteMessage((int) $contact->id, $expected);

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', 'تم الحذف بنجاح');
    }

    public function markProcessing(EditorVersionRequest $request, Contact $contact): RedirectResponse
    {
        $expected = $request->validated('editor_version');
        $this->workflow->markProcessing((int) $contact->id, $expected, (int) $request->user()->id);

        return redirect()->route('admin.contacts.show', $contact)
            ->with('success', 'تم نقل الطلب إلى المعالجة. تحقق من ملكية الحساب قبل أي إجراء على بياناته.');
    }

    public function closeDeletionRequest(ContactCloseRequest $request, Contact $contact): RedirectResponse
    {
        $validated = $request->validated();

        $this->workflow->closeDeletionRequest((int) $contact->id, $validated, (int) $request->user()->id);

        return redirect()->route('admin.contacts.show', $contact)
            ->with('success', 'تم إغلاق الطلب مع حفظ النتيجة وسجل المعالجة.');
    }

    public function executeAccountDeletion(
        ContactAccountDeletionRequest $request,
        Contact $contact
    ): RedirectResponse {
        $validated = $request->validated();
        $cleanupPending = $this->workflow->executeVerifiedDeletion(
            (int) $contact->id, $validated, (int) $request->user()->id
        );

        return redirect()->route('admin.contacts.show', $contact)
            ->with(
                'success',
                $cleanupPending
                    ? 'تم إغلاق الحساب وبدأ حذف ملفاته من التخزين.'
                    : 'تم حذف الحساب وبياناته الشخصية.'
            );
    }
}
