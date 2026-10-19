<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContactAccountDeletionRequest;
use App\Http\Requests\Admin\ContactCloseRequest;
use App\Http\Requests\Admin\EditorVersionRequest;
use App\Models\Contact;
use App\Services\AdminContactWorkflowService;
use App\Services\ContactAccountLookupService;
use App\Support\ContactEditorVersion;
use Illuminate\Http\RedirectResponse;

class ContactsController extends Controller
{
    public function __construct(
        private readonly AdminContactWorkflowService $workflow,
        private readonly ContactAccountLookupService $accountsByEmail
    ) {}

    public function index()
    {
        $contacts = Contact::query()
            ->orderBy('read')
            ->latest()
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
        $editorVersions = $contacts->getCollection()->mapWithKeys(
            fn (Contact $contact): array => [$contact->id => ContactEditorVersion::for($contact)]
        );

        return view('admin.contacts.index', compact('contacts', 'editorVersions'));
    }

    public function show(Contact $contact)
    {
        $contact->load(['resolver', 'resolvedUser']);
        $deletionUser = $contact->isAccountDeletionRequest() && ! $contact->isResolved()
            ? $this->accountsByEmail->forEmail($contact->email)
            : null;
        $editorVersion = ContactEditorVersion::for($contact);

        return view('admin.contacts.show', compact('contact', 'deletionUser', 'editorVersion'));
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
