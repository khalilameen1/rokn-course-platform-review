<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\ViewStudent;
use App\Filament\Resources\StudentResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\ProjectsRelationManager;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\ApiToken;
use App\Models\Order;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNote;
use App\Services\DeviceLoginService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentStudentWorkspaceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs($this->student(['role' => 'admin']), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_profile_read_shows_summary_without_writing_the_student(): void
    {
        $student = $this->student();
        $before = $student->refresh()->getAttributes();
        Livewire::test(ViewStudent::class, ['record' => $student->id])->assertSee($student->email)
            ->assertSee('لا يوجد كورس نشط')->assertSee('رصيد العملات')->assertActionVisible('setActive')
            ->assertActionHidden('resetDevice');
        self::assertSame($before, $student->fresh()->getAttributes());
    }

    public function test_native_notes_are_scoped_and_use_the_existing_receipt_owner(): void
    {
        $student = $this->student();
        $other = $this->student();
        $foreign = UserNote::query()->create(['user_id' => $other->id, 'created_by' => auth('web')->id(), 'note' => 'لا تظهر هنا']);
        $page = Livewire::test(NotesRelationManager::class, ['ownerRecord' => $student, 'pageClass' => ViewStudent::class]);
        $page->assertCanNotSeeTableRecords([$foreign])->callAction(TestAction::make('addNote')->table(), data: ['note' => 'متابعة الطالب بعد التعلم'])->assertHasNoActionErrors();
        $note = $student->notes()->sole();
        self::assertSame((int) auth('web')->id(), (int) $note->created_by);
        $page->assertCanSeeTableRecords([$note])->callAction(TestAction::make('removeNote')->table($note))->assertHasNoActionErrors();
        self::assertSame(0, $student->notes()->count());
        self::assertSame(1, $other->notes()->count());
    }

    public function test_order_and_project_tables_cannot_show_a_neighbours_records(): void
    {
        $student = $this->student();
        $other = $this->student();
        $package = Package::query()->create(['name_ar' => 'حزمة اختبار', 'name_en' => 'Fixture', 'price' => 100, 'coins' => 500]);
        $orders = collect([$student, $other])->map(fn ($owner) => Order::query()->create(['user_id' => $owner->id, 'package_id' => $package->id,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER, 'order_ref' => (string) Str::uuid(), 'amount' => 100, 'final_amount' => 100,
            'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING]));
        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $student, 'pageClass' => ViewStudent::class])
            ->assertCanSeeTableRecords([$orders[0]])->assertCanNotSeeTableRecords([$orders[1]]);
        $project = Project::factory()->create();
        $submissions = collect([$student, $other])->map(fn ($owner) => ProjectSubmission::query()->create([
            'user_id' => $owner->id, 'project_id' => $project->id, 'public_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(), 'review_status' => ProjectSubmission::STATUS_PENDING, 'submitted_at' => now(),
        ]));
        Livewire::test(ProjectsRelationManager::class, ['ownerRecord' => $student, 'pageClass' => ViewStudent::class])
            ->assertCanSeeTableRecords([$submissions[0]])->assertCanNotSeeTableRecords([$submissions[1]]);
    }

    public function test_native_account_toggle_invalidates_tokens_and_a_stale_page_cannot_reverse_it(): void
    {
        $student = $this->student();
        ApiToken::query()->create(['user_id' => $student->id, 'token' => hash('sha256', 'test-student-session'), 'session_id' => (string) Str::uuid(), 'issued_at' => now(), 'expired_at' => now()->addDay()]);
        $stale = Livewire::test(ViewStudent::class, ['record' => $student->id]);
        $page = Livewire::test(ViewStudent::class, ['record' => $student->id]);
        $page->callAction('setActive')->assertHasNoActionErrors();
        self::assertFalse((bool) $student->fresh()->active);
        self::assertSame(0, ApiToken::query()->where('user_id', $student->id)->whereNull('revoked_at')->count());
        $stale->callAction('setActive');
        self::assertFalse((bool) $student->fresh()->active);
        $page->callAction('setActive')->assertHasNoActionErrors();
        self::assertTrue((bool) $student->fresh()->active);
    }

    public function test_native_device_reset_uses_current_policy_and_revokes_sessions(): void
    {
        Setting::query()->firstOrCreate([])->forceFill(['device_login_policy' => DeviceLoginService::POLICY_SINGLE_PERMANENT])->save();
        $student = $this->student(['locked_device_id' => (string) Str::uuid()]);
        $student->generateApiToken();
        $student->deviceTokens()->create(['device_token' => 'native-student-push', 'device_id' => $student->locked_device_id, 'device_type' => 'android']);
        Livewire::test(ViewStudent::class, ['record' => $student->id])->assertActionVisible('resetDevice')->callAction('resetDevice')->assertHasNoActionErrors();
        self::assertNull($student->fresh()->locked_device_id);
        self::assertSame(0, $student->apiTokens()->count());
        self::assertSame(0, $student->deviceTokens()->count());
    }

    private function student(array $data = []): User
    {
        return User::query()->forceCreate([...$data, 'name' => 'طالب اختبار', 'email' => Str::uuid().'@workspace.test', 'active' => true, 'role' => $data['role'] ?? 'client']);
    }
}
