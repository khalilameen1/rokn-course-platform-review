<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Order;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Models\UserNote;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminStudentWorkspaceContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
        foreach ([$student, $neighbour] as $owner) {
            $this->order($owner);
            $this->note($owner, 'ملاحظة '.$owner->name);
            $this->submission($owner);
        }

        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.users.show', $student))
            ->assertOk();

        foreach (['orders' => 'orders_page', 'notes' => 'notes_page', 'projectSubmissions' => 'projects_page'] as $key => $pageName) {
            $page = $response->viewData($key);
            self::assertSame($pageName, $page->getPageName(), $key);
            self::assertSame(1, $page->total(), $key);
            self::assertSame([(int) $student->id], $page->getCollection()->pluck('user_id')->map('intval')->unique()->values()->all(), $key);
        }
        self::assertSame(['approved' => 0, 'pending' => 1], $response->viewData('orderStats'));
    }

    public function test_student_workspace_does_not_build_platform_wide_payment_reports(): void
    {
        $student = $this->student('Workspace Student');
        $this->order($student);
        $this->order($this->student('Other Buyer'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin, 'web')->get(route('admin.users.show', $student))->assertOk();
        $orderQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => (bool) preg_match('/\bfrom\s+"orders"/i', $sql))
            ->values();
        DB::disableQueryLog();

        // Listing page, its total and the per-status counts.
        self::assertCount(3, $orderQueries);
        foreach ($orderQueries as $sql) {
            // A platform summary (pending checkouts, channel totals) would
            // read orders without the opened student's id.
            self::assertMatchesRegularExpression('/"user_id"\s*=\s*\?/', $sql, $sql);
        }
    }

    public function test_student_view_has_one_workspace_linking_to_existing_detail_pages(): void
    {
        $student = $this->student('Workspace Student');
        $order = $this->order($student);
        $submission = $this->submission($student);

        $response = $this->actingAs($this->admin, 'web')
            ->get(route('admin.users.show', $student))
            ->assertOk();
        $xpath = $this->xpath($response->getContent());

        foreach ([
            route('admin.student-progress.show', $student->id),
            route('admin.project-submissions.show', $submission),
            route('admin.orders.show', $order->id),
        ] as $href) {
            self::assertGreaterThan(0, $xpath->query('//a[@href="'.$href.'"]')->length, $href);
        }
        self::assertSame(0, $xpath->query('//a[contains(@href, "/dashboard/bills")]')->length);
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
        self::assertSame($small, $this->listingQueryCount(), 'Student list queries must not grow per row.');

        $response = $this->actingAs($this->admin, 'web')->get(route('admin.users.index'))->assertOk();
        foreach ($response->viewData('users') as $user) {
            self::assertTrue($user->relationLoaded('photo'));
            self::assertTrue($user->relationLoaded('latestNote'));
        }
        $listed = $response->viewData('users')->getCollection()->keyBy('id');
        foreach ($students as $i => $student) {
            self::assertSame('أحدث ملاحظة '.$i, $listed[$student->id]->latestNote?->note);
        }

        // users-index.js retargets the single shared modal from each row's
        // data-note-action; the server must render that contract.
        $xpath = $this->xpath($response->getContent());
        self::assertSame(1, $xpath->query('//*[@id="addNoteModal"]')->length);
        self::assertSame(1, $xpath->query('//*[@id="addNoteModal"]//form[@data-student-note-form]//textarea[@name="note"]')->length);
        self::assertSame(1, $xpath->query('//script[contains(@src, "admin/assets/js/users-index.js")]')->length);
        foreach ($students as $student) {
            self::assertSame(1, $xpath->query('//*[@data-note-action="'.route('admin.users.notes.store', $student->id).'"]')->length);
        }

        $target = $students[2];
        $this->actingAs($this->admin, 'web')
            ->post(route('admin.users.notes.store', $target->id), [
                'authoring_request_id' => (string) Str::uuid(),
                'note' => 'ملاحظة من النافذة المشتركة',
            ])
            ->assertRedirect();
        self::assertSame('ملاحظة من النافذة المشتركة', $target->fresh()->latestNote?->note);
    }

    private function listingQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin, 'web')->get(route('admin.users.index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($document);
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
