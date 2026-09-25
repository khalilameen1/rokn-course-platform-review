<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\AdminContactWorkflowService;
use App\Services\ContactAccountLookupService;
use App\Support\ContactEditorVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContactsController extends Controller
{
    public function __construct(
        private readonly AdminContactWorkflowService $workflow,
        private readonly ContactAccountLookupService $accountsByEmail
    ) {
    }

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
        $deletionUser = $contact->isAccountDeletionRequest() && !$contact->isResolved()
            ? $this->accountsByEmail->forEmail($contact->email)
            : null;
        $editorVersion = ContactEditorVersion::for($contact);

        return view('admin.contacts.show', compact('contact', 'deletionUser', 'editorVersion'));
    }

    public function markRead(Request $request, Contact $contact): RedirectResponse
    {
        $expected = $this->validatedEditorVersion($request);
        $this->workflow->markRead((int) $contact->id, $expected);

        return redirect()->route('admin.contacts.show', $contact);
    }

    public function destroy(Request $request, Contact $contact)
    {
        $expected = $this->validatedEditorVersion($request);
        $this->workflow->deleteMessage((int) $contact->id, $expected);

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', 'تم الحذف بنجاح');
    }

    public function markProcessing(Request $request, Contact $contact): RedirectResponse
    {
        $expected = $this->validatedEditorVersion($request);
        $this->workflow->markProcessing((int) $contact->id, $expected, (int) $request->user()->id);

        return redirect()->route('admin.contacts.show', $contact)
            ->with('success', 'تم نقل الطلب إلى المعالجة. تحقق من ملكية الحساب قبل أي إجراء على بياناته.');
    }

    public function closeDeletionRequest(Request $request, Contact $contact): RedirectResponse
    {
        $validated = $request->validate([
            'editor_version' => ['required', 'string', 'size:64'],
            'outcome' => ['required', 'in:self_service_completed,no_account_found,duplicate,withdrawn'],
            'resolution_note' => ['nullable', 'string', 'max:500'],
            'confirm_close' => ['accepted'],
        ], [
            'outcome.required' => 'اختر نتيجة المعالجة.',
            'outcome.in' => 'نتيجة المعالجة غير صالحة.',
            'confirm_close.accepted' => 'أكد أنك راجعت الطلب قبل إغلاقه.',
        ]);

        $this->workflow->closeDeletionRequest((int) $contact->id, $validated, (int) $request->user()->id);

        return redirect()->route('admin.contacts.show', $contact)
            ->with('success', 'تم إغلاق الطلب مع حفظ النتيجة وسجل المعالجة.');
    }

    public function executeAccountDeletion(
        Request $request,
        Contact $contact
    ): RedirectResponse {
        $validated = $request->validate([
            'editor_version' => ['required', 'string', 'size:64'],
            'account_email' => ['required', 'string', 'max:255'],
            'verification_note' => ['required', 'string', 'min:8', 'max:500'],
            'confirm_identity' => ['accepted'],
            'confirm_delete' => ['accepted'],
        ], [
            'account_email.required' => 'اكتب بريد الحساب للتأكيد.',
            'verification_note.required' => 'سجّل طريقة التحقق من صاحب الحساب.',
            'verification_note.min' => 'اكتب ملاحظة تحقق أوضح.',
            'confirm_identity.accepted' => 'أكد أنك تحققت من صاحب الحساب.',
            'confirm_delete.accepted' => 'أكد تنفيذ الحذف النهائي.',
        ]);
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

    private function validatedEditorVersion(Request $request): string
    {
        return (string) $request->validate([
            'editor_version' => ['required', 'string', 'size:64'],
        ])['editor_version'];
    }
}
