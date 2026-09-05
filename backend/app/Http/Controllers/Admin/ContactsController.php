<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Support\AdminEditorVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactsController extends Controller
{
    public function index()
    {
        $contacts = Contact::query()
            ->orderBy('read')
            ->latest()
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
        $editorVersions = $contacts->getCollection()->mapWithKeys(
            fn (Contact $contact): array => [$contact->id => $this->editorVersion($contact)]
        );

        return view('admin.contacts.index', compact('contacts', 'editorVersions'));
    }

    public function show(Contact $contact)
    {
        $contact->load(['resolver', 'resolvedUser']);
        $deletionUser = $contact->isAccountDeletionRequest() && !$contact->isResolved()
            ? $this->existingUserForEmail($contact->email)
            : null;
        $editorVersion = $this->editorVersion($contact);

        return view('admin.contacts.show', compact('contact', 'deletionUser', 'editorVersion'));
    }

    public function markRead(Request $request, Contact $contact): RedirectResponse
    {
        $expected = $this->validatedEditorVersion($request);
        DB::transaction(function () use ($contact, $expected): void {
            $locked = $this->lockedCurrent($contact, $expected);
            if (!$locked->read) {
                $locked->forceFill(['read' => true])->save();
            }
        }, 3);

        return redirect()->route('admin.contacts.show', $contact);
    }

    public function destroy(Request $request, Contact $contact)
    {
        $expected = $this->validatedEditorVersion($request);
        DB::transaction(function () use ($contact, $expected): void {
            $locked = $this->lockedCurrent($contact, $expected);
            if ($locked->isAccountDeletionRequest()) {
                throw ValidationException::withMessages([
                    'editor_version' => ['لا يمكن حذف سجل طلب حذف حساب'],
                ]);
            }
            $locked->delete();
        }, 3);

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', 'تم الحذف بنجاح');
    }

    public function markProcessing(Request $request, Contact $contact): RedirectResponse
    {
        $expected = $this->validatedEditorVersion($request);
        DB::transaction(function () use ($contact, $expected): void {
            $locked = $this->lockedCurrent($contact, $expected);
            if (!$locked->isAccountDeletionRequest() || $locked->isResolved()) {
                throw ValidationException::withMessages([
                    'editor_version' => ['لا يمكن بدء معالجة هذا الطلب في حالته الحالية'],
                ]);
            }

            $metadata = (array) ($locked->resolution_metadata ?? []);
            $metadata['processing_started_at'] = now()->toIso8601String();
            $metadata['processing_started_by'] = (int) auth()->id();
            $locked->forceFill([
                'read' => true,
                'resolution_status' => Contact::RESOLUTION_PROCESSING,
                'resolution_metadata' => $metadata,
            ])->save();
        }, 3);

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

        DB::transaction(function () use ($contact, $validated): void {
            $locked = $this->lockedCurrent($contact, (string) $validated['editor_version']);
            if (!$locked->isAccountDeletionRequest() || $locked->isResolved() || !$locked->isProcessing()) {
                throw ValidationException::withMessages([
                    'editor_version' => ["تغيّرت حالة الطلب\nحدّث الصفحة قبل الإغلاق"],
                ]);
            }
            $matchedUser = $this->existingUserForEmail($locked->email);
            if (in_array($validated['outcome'], ['self_service_completed', 'no_account_found'], true) && $matchedUser) {
                throw ValidationException::withMessages([
                    'outcome' => ['الحساب المطابق ما زال موجودًا'],
                ]);
            }

            $metadata = (array) ($locked->resolution_metadata ?? []);
            $metadata['outcome'] = $validated['outcome'];
            $metadata['note'] = trim((string) ($validated['resolution_note'] ?? '')) ?: null;
            $locked->forceFill([
                'read' => true,
                'resolution_status' => Contact::RESOLUTION_CLOSED,
                'resolved_at' => now(),
                'resolved_by' => (int) auth()->id(),
                'resolved_user_id' => $matchedUser?->id,
                'resolution_metadata' => $metadata,
            ])->save();
        }, 3);

        return redirect()->route('admin.contacts.show', $contact)
            ->with('success', 'تم إغلاق الطلب مع حفظ النتيجة وسجل المعالجة.');
    }

    public function executeAccountDeletion(
        Request $request,
        Contact $contact,
        AccountDeletionService $accounts
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
        $cleanupPending = DB::transaction(function () use ($contact, $validated, $accounts): bool {
            $locked = $this->lockedCurrent($contact, (string) $validated['editor_version']);
            if (!$locked->isAccountDeletionRequest() || $locked->isResolved() || !$locked->isProcessing()) {
                throw ValidationException::withMessages([
                    'editor_version' => ["تغيّرت حالة الطلب\nحدّث الصفحة قبل تنفيذ الحذف"],
                ]);
            }
            $matchedUser = $this->existingUserForEmail($locked->email);
            if (!$matchedUser || strtolower((string) $matchedUser->role) !== 'client') {
                throw ValidationException::withMessages([
                    'account_email' => ['لا يوجد حساب طالب نشط مطابق لهذا الطلب'],
                ]);
            }
            $confirmedEmail = Str::lower(trim((string) $validated['account_email']));
            if (!hash_equals(Str::lower(trim((string) $matchedUser->email)), $confirmedEmail)) {
                throw ValidationException::withMessages([
                    'account_email' => ['بريد التأكيد لا يطابق الحساب المطلوب حذفه'],
                ]);
            }

            $cleanup = $accounts->delete($matchedUser);
            $pending = (bool) (
                $cleanup['local_cleanup_pending']
                || $cleanup['remote_portfolio_cleanup_pending']
            );
            $metadata = (array) ($locked->resolution_metadata ?? []);
            $metadata['outcome'] = 'manual_verified_deletion';
            $metadata['note'] = trim((string) $validated['verification_note']);
            $metadata['cleanup_pending'] = $pending;
            $locked->forceFill([
                'read' => true,
                'resolution_status' => Contact::RESOLUTION_CLOSED,
                'resolved_at' => now(),
                'resolved_by' => (int) auth()->id(),
                'resolved_user_id' => $matchedUser->id,
                'resolution_metadata' => $metadata,
            ])->save();
            return $pending;
        }, 3);

        return redirect()->route('admin.contacts.show', $contact)
            ->with(
                'success',
                $cleanupPending
                    ? 'تم إغلاق الحساب وبدأ حذف ملفاته من التخزين.'
                    : 'تم حذف الحساب وبياناته الشخصية.'
            );
    }

    private function existingUserForEmail(?string $email): ?User
    {
        $normalizedEmail = Str::lower(trim((string) $email));
        if ($normalizedEmail === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();
    }

    private function validatedEditorVersion(Request $request): string
    {
        return (string) $request->validate([
            'editor_version' => ['required', 'string', 'size:64'],
        ])['editor_version'];
    }

    private function lockedCurrent(Contact $contact, string $expected): Contact
    {
        $locked = Contact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();
        if (!hash_equals($this->editorVersion($locked), $expected)) {
            throw ValidationException::withMessages([
                'editor_version' => ["تغيّرت الرسالة منذ فتح الصفحة\nحدّثها قبل تنفيذ الإجراء"],
            ]);
        }
        return $locked;
    }

    private function editorVersion(Contact $contact): string
    {
        return AdminEditorVersion::for($contact, [
            'request_type', 'email', 'read', 'resolution_status', 'resolved_at',
            'resolved_by', 'resolved_user_id', 'resolution_metadata', 'updated_at',
        ]);
    }
}
