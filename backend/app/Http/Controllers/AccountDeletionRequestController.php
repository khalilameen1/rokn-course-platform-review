<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\DesignSetting;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AccountDeletionRequestController extends Controller
{
    public function show(): View
    {
        app()->setLocale('ar');

        return view('static.account-deletion', [
            'setting' => Setting::first(),
            'designSetting' => DesignSetting::getDefaultSettings(),
            'locale' => 'ar',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'confirm' => ['accepted'],
            // Intentionally invisible to humans. A value marks automated spam.
            'website' => ['nullable', 'string', 'max:0'],
        ], [
            'name.required' => 'اكتب الاسم الموجود على الحساب',
            'email.required' => 'اكتب البريد المرتبط بالحساب',
            'email.email' => 'اكتب بريدًا إلكترونيًا صحيحًا',
            'confirm.accepted' => 'أكد أنك تريد بدء طلب حذف الحساب',
            'website.max' => 'تعذر إرسال الطلب',
        ]);

        $reference = 'DEL-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        $email = Str::lower(trim($validated['email']));
        $reason = trim((string) ($validated['reason'] ?? ''));

        $contact = new Contact;
        $contact->forceFill([
            'name' => trim($validated['name']),
            'email' => $email,
            'phone' => trim((string) ($validated['phone'] ?? '')) ?: '-',
            'message' => implode("\n", array_filter([
                '[ACCOUNT_DELETION_REQUEST]',
                'Reference: '.$reference,
                'Account email: '.$email,
                $reason !== '' ? 'User note: '.$reason : null,
            ])),
            'read' => false,
            'request_type' => Contact::TYPE_ACCOUNT_DELETION,
            'resolution_status' => Contact::RESOLUTION_PENDING,
        ])->save();

        // The same response is used whether an account exists or not so this
        // public endpoint cannot be used to enumerate registered addresses.
        return redirect()
            ->route('account-deletion.show')
            ->with('deletion_request_submitted', true)
            ->with('deletion_reference', $reference);
    }
}
