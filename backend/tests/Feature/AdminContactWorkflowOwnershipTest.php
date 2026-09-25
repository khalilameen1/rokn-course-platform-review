<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\ContactsController;
use App\Models\AccountFileDeletion;
use App\Models\Contact;
use App\Models\Course;
use App\Models\User;
use App\Services\AdminContactWorkflowService;
use App\Services\ContactAccountLookupService;
use App\Support\ContactEditorVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminContactWorkflowOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
    }

    public function test_read_and_message_deletion_use_current_versions_without_http(): void
    {
        $this->forbidController();
        $message = Contact::query()->create(['name' => 'Learner', 'email' => 'message@rokn.test', 'phone' => '-', 'message' => 'رسالة عادية', 'read' => false]);
        $version = ContactEditorVersion::for($message);
        $workflow = app(AdminContactWorkflowService::class);
        $workflow->markRead((int) $message->id, $version);
        self::assertTrue($message->fresh()->read);
        try {
            $workflow->deleteMessage((int) $message->id, $version);
            self::fail('A stale message version cannot authorize deletion.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        $workflow->deleteMessage((int) $message->id, ContactEditorVersion::for($message->fresh()));
        self::assertNull(Contact::find($message->id));
    }

    public function test_processing_and_non_destructive_closure_record_explicit_actor_and_keep_account(): void
    {
        $this->forbidController();
        $actor = $this->user('admin');
        $student = $this->user();
        $contact = $this->requestFor(' '.strtoupper($student->email).' ');
        $workflow = app(AdminContactWorkflowService::class);
        self::assertSame($student->id, app(ContactAccountLookupService::class)->forEmail($contact->email)->id);
        $workflow->markProcessing((int) $contact->id, ContactEditorVersion::for($contact), (int) $actor->id);
        self::assertSame((int) $actor->id, $contact->fresh()->resolution_metadata['processing_started_by']);
        self::assertNotEmpty($contact->fresh()->resolution_metadata['processing_started_at']);
        $workflow->closeDeletionRequest((int) $contact->id, [
            'editor_version' => ContactEditorVersion::for($contact->fresh()),
            'outcome' => 'duplicate', 'resolution_note' => '  مكرر لطلب آخر  ',
        ], (int) $actor->id);
        $contact->refresh();
        self::assertTrue($contact->isResolved());
        self::assertSame((int) $actor->id, (int) $contact->resolved_by);
        self::assertSame((int) $student->id, (int) $contact->resolved_user_id);
        self::assertSame('مكرر لطلب آخر', $contact->resolution_metadata['note']);
        self::assertSame('duplicate', $contact->resolution_metadata['outcome']);
        self::assertNotNull($student->fresh());
        Http::assertNothingSent();
    }

    public function test_audit_records_cannot_be_deleted_and_live_accounts_cannot_be_reported_as_absent(): void
    {
        $this->forbidController();
        $student = $this->user();
        $actor = $this->user('admin');
        $workflow = app(AdminContactWorkflowService::class);
        foreach ([true, false] as $typedRequest) {
            $contact = $this->requestFor($student->email);
            if (!$typedRequest) $contact->forceFill(['request_type' => null])->save();
            try {
                $workflow->deleteMessage((int) $contact->id, ContactEditorVersion::for($contact));
                self::fail('Both typed and legacy deletion requests must keep their audit record.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('editor_version', $error->errors());
            }
            $workflow->markProcessing((int) $contact->id, ContactEditorVersion::for($contact), (int) $actor->id);
            foreach (['self_service_completed', 'no_account_found'] as $outcome) {
                try {
                    $workflow->closeDeletionRequest((int) $contact->id, [
                        'editor_version' => ContactEditorVersion::for($contact->fresh()), 'outcome' => $outcome,
                    ], (int) $actor->id);
                    self::fail('An existing account cannot be recorded as absent.');
                } catch (ValidationException $error) {
                    self::assertArrayHasKey('outcome', $error->errors());
                }
            }
            self::assertTrue($contact->fresh()->isProcessing());
        }
        self::assertNotNull($student->fresh());
    }

    public function test_verified_deletion_retains_audit_and_reports_durable_pending_file_cleanup(): void
    {
        $this->forbidController();
        $actor = $this->user('admin');
        $student = $this->user();
        Storage::disk('public')->put('profiles/contact-test.jpg', 'disposable private bytes');
        $student->forceFill(['profile_image' => 'profiles/contact-test.jpg'])->save();
        $contact = $this->requestFor($student->email);
        $workflow = app(AdminContactWorkflowService::class);
        $workflow->markProcessing((int) $contact->id, ContactEditorVersion::for($contact), (int) $actor->id);
        $input = $this->deletionInput($contact->fresh(), $student);
        try {
            $workflow->executeVerifiedDeletion((int) $contact->id, [...$input, 'account_email' => 'wrong@rokn.test'], (int) $actor->id);
            self::fail('A mismatched confirmation email must not delete a student.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('account_email', $error->errors());
        }
        self::assertNotNull($student->fresh());
        self::assertTrue($workflow->executeVerifiedDeletion((int) $contact->id, $input, (int) $actor->id));
        self::assertTrue(User::withTrashed()->findOrFail($student->id)->trashed());
        $contact->refresh();
        self::assertTrue($contact->isResolved());
        self::assertSame('manual_verified_deletion', $contact->resolution_metadata['outcome']);
        self::assertTrue($contact->resolution_metadata['cleanup_pending']);
        self::assertSame((int) $actor->id, (int) $contact->resolved_by);
        self::assertSame((int) $student->id, (int) $contact->resolved_user_id);
        self::assertSame('profiles/contact-test.jpg', AccountFileDeletion::query()->sole()->path);
        Storage::disk('public')->assertExists('profiles/contact-test.jpg');
        Http::assertNothingSent();
    }

    public function test_audit_write_failure_rolls_back_the_account_and_cleanup_outbox(): void
    {
        $this->forbidController();
        $actor = $this->user('admin');
        $student = $this->user();
        Storage::disk('public')->put('profiles/contact-rollback.jpg', 'disposable private bytes');
        $student->forceFill(['profile_image' => 'profiles/contact-rollback.jpg'])->save();
        $contact = $this->requestFor($student->email);
        $workflow = app(AdminContactWorkflowService::class);
        $workflow->markProcessing((int) $contact->id, ContactEditorVersion::for($contact), (int) $actor->id);
        $input = $this->deletionInput($contact->fresh(), $student);
        $this->addRating($student);
        Cache::forever('courses:catalog-revision', 50);
        DB::statement("CREATE TRIGGER reject_contact_resolution BEFORE UPDATE ON contacts
            WHEN NEW.resolution_status = 'closed' BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
        try {
            $workflow->executeVerifiedDeletion((int) $contact->id, $input, (int) $actor->id);
            self::fail('Audit failure must roll back the associated account erasure.');
        } catch (QueryException $error) {
            self::assertStringContainsString('audit unavailable', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER reject_contact_resolution');
        }
        self::assertSame($student->email, $student->fresh()->email);
        self::assertNull($student->fresh()->deleted_at);
        self::assertTrue($contact->fresh()->isProcessing());
        self::assertSame(0, AccountFileDeletion::query()->count());
        self::assertSame(1, DB::table('course_ratings')->where('user_id', $student->id)->count());
        self::assertSame(50, (int) Cache::get('courses:catalog-revision'));
        Queue::assertNothingPushed();
        Storage::disk('public')->assertExists('profiles/contact-rollback.jpg');
    }

    public function test_deletion_invalidates_catalogue_only_after_the_outer_workflow_commits(): void
    {
        $this->forbidController();
        $actor = $this->user('admin');
        $student = $this->user();
        $this->addRating($student);
        $contact = $this->requestFor($student->email);
        $workflow = app(AdminContactWorkflowService::class);
        $workflow->markProcessing((int) $contact->id, ContactEditorVersion::for($contact), (int) $actor->id);
        Cache::forever('courses:catalog-revision', 70);
        self::assertSame(0, DB::transactionLevel());

        DB::beginTransaction();
        try {
            $workflow->executeVerifiedDeletion(
                (int) $contact->id, $this->deletionInput($contact->fresh(), $student), (int) $actor->id
            );
            self::assertTrue($contact->fresh()->isResolved());
            self::assertTrue(User::withTrashed()->findOrFail($student->id)->trashed());
            self::assertSame(0, DB::table('course_ratings')->where('user_id', $student->id)->count());
            self::assertSame(70, (int) Cache::get('courses:catalog-revision'));
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack();
        }

        self::assertGreaterThan(70, (int) Cache::get('courses:catalog-revision'));
        self::assertTrue($contact->fresh()->isResolved());
    }

    public function test_http_adapter_requires_identity_and_delete_confirmations_before_workflow(): void
    {
        $actor = $this->user('admin');
        $student = $this->user();
        $contact = $this->requestFor($student->email);
        app(AdminContactWorkflowService::class)->markProcessing((int) $contact->id, ContactEditorVersion::for($contact), (int) $actor->id);
        $this->withoutMiddleware();
        $this->actingAs($actor)->post(route('admin.contacts.execute-account-deletion', $contact),
            $this->deletionInput($contact->fresh(), $student)
        )->assertSessionHasErrors(['confirm_identity', 'confirm_delete']);
        self::assertNotNull($student->fresh());
        self::assertTrue($contact->fresh()->isProcessing());
    }

    private function user(string $role = 'client'): User
    {
        return User::query()->forceCreate([
            'name_ar' => 'Test user', 'email' => Str::uuid().'@rokn.test',
            'phone' => (string) Str::uuid(), 'role' => $role, 'active' => true, 'password' => 'test-only',
        ]);
    }

    private function addRating(User $student): void
    {
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس التقييم', 'price' => 100, 'is_coming_soon' => false,
        ]);
        DB::table('course_ratings')->insert([
            'user_id' => $student->id, 'course_id' => $course->id,
            'rating' => 5, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function requestFor(string $email): Contact
    {
        return Contact::query()->forceCreate([
            'name' => 'Learner', 'email' => $email, 'phone' => '-',
            'message' => '[ACCOUNT_DELETION_REQUEST] Test request', 'read' => false,
            'request_type' => Contact::TYPE_ACCOUNT_DELETION, 'resolution_status' => Contact::RESOLUTION_PENDING,
        ]);
    }

    private function deletionInput(Contact $contact, User $student): array
    {
        return ['editor_version' => ContactEditorVersion::for($contact), 'account_email' => strtoupper($student->email),
            'verification_note' => 'تم التحقق من صاحب الحساب في الطلب'];
    }

    private function forbidController(): void
    {
        $this->app->bind(ContactsController::class, static function (): never {
            throw new \LogicException('Contact workflow must not resolve its HTTP adapter.');
        });
    }
}
