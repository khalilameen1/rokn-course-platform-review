<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ContactResource;
use App\Filament\Resources\ContactResource\Pages\ListContacts;
use App\Filament\Resources\ContactResource\Pages\ViewContact;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Contact;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentContactWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
        $this->actingAs($this->user('admin'), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_reading_is_explicit_and_normal_message_deletion_uses_the_owner(): void
    {
        $message = $this->message();
        Livewire::test(ViewContact::class, ['record' => $message->id])->assertSee('رسالة عادية');
        self::assertFalse($message->fresh()->read);
        Livewire::test(ViewContact::class, ['record' => $message->id])->callAction('markRead');
        self::assertTrue($message->fresh()->read);
        Livewire::test(ViewContact::class, ['record' => $message->id])->callAction('deleteMessage')->assertRedirect(ContactResource::getUrl());
        self::assertNull($message->fresh());
    }

    public function test_deletion_request_requires_processing_and_valid_outcome_with_explicit_confirmation(): void
    {
        $student = $this->user();
        $request = $this->message($student, true);
        $page = Livewire::test(ViewContact::class, ['record' => $request->id])->assertSee('رقم '.$student->id)->assertActionHidden('closeRequest');
        $page->callAction('startProcessing');
        self::assertTrue($request->fresh()->isProcessing());
        self::assertSame((int) auth('web')->id(), $request->fresh()->getAttribute('resolution_metadata')['processing_started_by']);
        $page->callAction('closeRequest', ['outcome' => 'no_account_found', 'confirm_close' => true])->assertHasActionErrors(['outcome']);
        self::assertFalse($request->fresh()->isResolved());
        $page->unmountAction()->callAction('closeRequest', ['outcome' => 'duplicate', 'confirm_close' => false])->assertHasActionErrors(['confirm_close']);
        $page->unmountAction()->callAction('closeRequest', ['outcome' => 'duplicate', 'resolution_note' => 'طلب مكرر', 'confirm_close' => true])->assertHasNoActionErrors();
        self::assertTrue($request->fresh()->isResolved());
        self::assertSame('duplicate', $request->fresh()->getAttribute('resolution_metadata')['outcome']);
        self::assertNotNull($student->fresh());
    }

    public function test_verified_account_deletion_validates_identity_confirmations_then_retains_the_audit_request(): void
    {
        $student = $this->user();
        $request = $this->message($student, true);
        $page = Livewire::test(ViewContact::class, ['record' => $request->id])->callAction('startProcessing');
        $fields = ['account_email' => $student->email, 'verification_note' => 'تحققت من ملكية الحساب', 'confirm_identity' => true, 'confirm_delete' => true];
        $page->callAction('deleteAccount', [...$fields, 'confirm_identity' => false])->assertHasActionErrors(['confirm_identity']);
        self::assertFalse($student->fresh()->trashed());
        $page->unmountAction()->callAction('deleteAccount', [...$fields, 'account_email' => 'wrong@rokn.test'])->assertHasActionErrors(['account_email']);
        $page->unmountAction()->callAction('deleteAccount', $fields)->assertHasNoActionErrors();
        self::assertTrue(User::withTrashed()->findOrFail($student->id)->trashed());
        self::assertTrue($request->fresh()->isResolved());
        self::assertSame('manual_verified_deletion', $request->fresh()->getAttribute('resolution_metadata')['outcome']);
        Livewire::test(ViewContact::class, ['record' => $request->id])->assertActionHidden('deleteMessage')->assertActionHidden('deleteAccount');
    }

    public function test_stale_view_cannot_change_read_state_or_delete_a_message(): void
    {
        $message = $this->message();
        $page = Livewire::test(ViewContact::class, ['record' => $message->id]);
        $message->update(['message' => 'أحدث رسالة']);
        $page->callAction('markRead');
        self::assertFalse($message->fresh()->read);
        $page->callAction('deleteMessage');
        self::assertNotNull($message->fresh());
    }

    public function test_search_and_read_filter_do_not_mark_messages_read_and_bookmarks_are_preserved(): void
    {
        $message = $this->message();
        $read = $this->message();
        $read->update(['read' => true]);
        Livewire::test(ListContacts::class)->searchTable($message->email)->assertCanSeeTableRecords([$message]);
        Livewire::test(ListContacts::class)->filterTable('read', true)->assertCanSeeTableRecords([$read])->assertCanNotSeeTableRecords([$message]);
        self::assertFalse($message->fresh()->read);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->get(route('admin.contacts.index'))->assertRedirect(ContactResource::getUrl());
        $this->get(route('admin.contacts.show', $message))->assertRedirect(ContactResource::getUrl('view', ['record' => $message->id]));
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(ListContacts::class)->assertForbidden();
        Livewire::test(ViewContact::class, ['record' => $message->id])->assertForbidden();
    }

    private function message(?User $user = null, bool $deletion = false): Contact
    {
        $user ??= $this->user();
        $contact = Contact::query()->create(['name' => 'طالب للتجربة', 'email' => $user->email, 'phone' => '201055550003',
            'message' => $deletion ? '[ACCOUNT_DELETION_REQUEST] أريد حذف الحساب' : 'رسالة عادية', 'read' => false]);
        if ($deletion) {
            $contact->forceFill(['request_type' => Contact::TYPE_ACCOUNT_DELETION, 'resolution_status' => Contact::RESOLUTION_PENDING])->save();
        }

        return $contact;
    }

    private function user(string $role = 'client'): User
    {
        return User::query()->forceCreate(['name_ar' => 'حساب تجربة', 'email' => Str::uuid().'@contact.test', 'role' => $role, 'active' => true]);
    }
}
