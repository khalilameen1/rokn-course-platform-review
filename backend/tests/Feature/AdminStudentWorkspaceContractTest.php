<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\StudentResource;
use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Filament\Resources\StudentResource\Pages\ViewStudent;
use App\Filament\Resources\StudentResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\StudentResource\RelationManagers\ProjectsRelationManager;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Order;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Models\UserNote;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class AdminStudentWorkspaceContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $listQueries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->admin = User::query()->forceCreate([
            'name' => 'Workspace Admin', 'email' => 'workspace-admin@rokn.test', 'role' => 'admin', 'active' => true,
        ]);
    }

    public function test_student_workspace_reads_are_scoped_to_the_opened_student(): void
    {
        $student = $this->student('Workspace Student');
        $neighbour = $this->student('Neighbour Student');
        $records = [];
        foreach ([$student, $neighbour] as $owner) {
            $records[] = [$this->order($owner), $this->note($owner, 'ملاحظة '.$owner->name), $this->submission($owner)];
        }
        $this->actingAs($this->admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        foreach ([
            OrdersRelationManager::class,
            NotesRelationManager::class,
            ProjectsRelationManager::class,
        ] as $index => $manager) {
            Livewire::test($manager, ['ownerRecord' => $student, 'pageClass' => ViewStudent::class])
                ->assertCanSeeTableRecords([$records[0][$index]])->assertCanNotSeeTableRecords([$records[1][$index]]);
        }
    }

    public function test_student_workspace_does_not_build_platform_wide_payment_reports(): void
    {
        $student = $this->student('Workspace Student');
        $this->order($student);
        $this->order($this->student('Other Buyer'));
        $this->actingAs($this->admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(OrdersRelationManager::class, [
            'ownerRecord' => $student, 'pageClass' => ViewStudent::class,
        ])->assertCountTableRecords(1);
        $orderQueries = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql): bool => (bool) preg_match('/\\bfrom\\s+"orders"/i', $sql))->values();
        DB::disableQueryLog();
        self::assertNotEmpty($orderQueries);
        foreach ($orderQueries as $sql) {
            self::assertMatchesRegularExpression('/"user_id"\\s*=\\s*\\?/', $sql, $sql);
        }
    }

    public function test_student_view_has_one_workspace_linking_to_existing_detail_pages(): void
    {
        $student = $this->student('Workspace Student');
        $order = $this->order($student);
        $submission = $this->submission($student);
        $this->actingAs($this->admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $this->get(route('admin.users.show', $student))->assertRedirect(StudentResource::getUrl('view', ['record' => $student->id]));
        Livewire::test(ViewStudent::class, ['record' => $student->id])
            ->assertSee(route('admin.student-progress.show', $student->id), false)->assertDontSee('/dashboard/bills', false);
        Livewire::test(ProjectsRelationManager::class, [
            'ownerRecord' => $student, 'pageClass' => ViewStudent::class,
        ])->assertSee(route('admin.project-submissions.show', $submission), false);
        Livewire::test(OrdersRelationManager::class, [
            'ownerRecord' => $student, 'pageClass' => ViewStudent::class,
        ])->assertSee(OrderResource::getUrl('view', ['record' => $order->id]), false);
        self::assertFalse(Route::has('admin.bills.show'));
    }

    public function test_student_list_reads_notes_and_photos_in_bounded_queries(): void
    {
        $this->student('First Student');
        $small = $this->listingQueryCount();
        $students = [];
        for ($i = 0; $i < 5; $i++) {
            $students[] = $student = $this->student('Listed Student '.$i);
            $this->note($student, 'أقدم ملاحظة');
            $this->travel(1)->seconds();
            $this->note($student, 'أحدث ملاحظة '.$i);
        }
        self::assertSame($small, $this->listingQueryCount(), 'Student list queries must not grow per row. '.json_encode($this->listQueries));

        $this->actingAs($this->admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $page = Livewire::test(ListStudents::class);
        $listed = collect($page->instance()->getTableRecords()->items())->keyBy('id');
        foreach ($students as $i => $student) {
            self::assertTrue($listed[$student->id]->relationLoaded('photo'));
            self::assertTrue($listed[$student->id]->relationLoaded('latestNote'));
            self::assertSame('أحدث ملاحظة '.$i, $listed[$student->id]->latestNote?->note);
        }
        $target = $students[2];
        $page->callAction(TestAction::make('addNote')->table($target), data: ['note' => 'ملاحظة من القائمة الأصلية'])
            ->assertHasNoActionErrors();
        self::assertSame('ملاحظة من القائمة الأصلية', $target->fresh()->latestNote?->note);
    }

    private function listingQueryCount(): int
    {
        $this->actingAs($this->admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        Livewire::test(ListStudents::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListStudents::class);
        $this->listQueries = collect(DB::getQueryLog())->pluck('query')->all();
        $count = count($this->listQueries);
        DB::disableQueryLog();

        return $count;
    }

    private function student(string $name): User
    {
        return User::query()->forceCreate([
            'name' => $name, 'email' => Str::slug($name).'-'.uniqid().'@rokn.test', 'role' => 'client', 'active' => true,
        ]);
    }

    private function note(User $user, string $text): UserNote
    {
        return UserNote::query()->create(['user_id' => $user->id, 'note' => $text, 'created_by' => $this->admin->id]);
    }

    private function order(User $user): Order
    {
        $package = Package::query()->create([
            'name_ar' => 'باقة الطالب', 'name_en' => 'Student package', 'price' => 100, 'coins' => 500,
        ]);

        return Order::query()->create([
            'user_id' => $user->id, 'package_id' => $package->id, 'package_coins' => 500,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER, 'order_ref' => 'WS-'.uniqid(),
            'amount' => 100, 'final_amount' => 100, 'gateway_currency' => 'EGP', 'total_coins' => 500,
            'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING,
        ]);
    }

    private function submission(User $user): ProjectSubmission
    {
        return ProjectSubmission::query()->create([
            'user_id' => $user->id, 'project_id' => Project::factory()->create()->id,
            'public_id' => (string) Str::uuid(), 'idempotency_key' => 'ws-'.Str::uuid(),
            'review_status' => 'approved', 'submission_text' => 'حل المشروع',
            'submitted_at' => now()->subMinute(), 'reviewed_at' => now(),
        ]);
    }
}
