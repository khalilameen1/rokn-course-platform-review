<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\ContactResource;
use App\Filament\Resources\CouponResource;
use App\Filament\Resources\StudentResource;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Contact;
use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use App\Services\AdminStudentProgressReadService;
use App\Services\CourseStagedAuthoringService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminDashboardListsAndExportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_orders_csv_streams_the_filtered_list_with_formula_safe_cells(): void
    {
        $course = Course::factory()->create(['tenant_id' => 1, 'name_ar' => '=HYPERLINK("x")']);
        $buyer = $this->user('client', ['name' => 'مشتري أول', 'phone' => '201011112222']);
        $other = $this->user('client', ['name' => 'Other Buyer']);
        $kept = $this->order($buyer, $course, ['final_amount' => 120]);
        $this->order($other, $course, ['final_amount' => 999]);

        $response = $this->actingAs($this->user('admin'))
            ->get(route('admin.orders.export', ['amount_max' => 500]));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
        self::assertSame('order_id', $rows[0][0]);
        self::assertCount(2, $rows);
        self::assertSame((string) $kept->id, $rows[1][0]);
        self::assertSame('مشتري أول', $rows[1][3]);
        self::assertSame('201011112222', $rows[1][5]);
        self::assertStringStartsWith("'=", $rows[1][6]);
    }

    public function test_students_csv_uses_list_filters_and_only_students(): void
    {
        $this->user('client', ['name' => 'Active Learner', 'email' => 'active@rokn.test']);
        $this->user('client', ['name' => 'Paused Learner', 'email' => 'paused@rokn.test', 'active' => false]);
        $admin = $this->user('admin', ['email' => 'owner@rokn.test']);

        $csv = $this->actingAs($admin)->get(route('admin.users.export', ['active' => '1']))
            ->assertOk()->streamedContent();
        self::assertStringContainsString('active@rokn.test', $csv);
        self::assertStringNotContainsString('paused@rokn.test', $csv);
        self::assertStringNotContainsString('owner@rokn.test', $csv);

        $csv = $this->actingAs($admin)->get(route('admin.users.export', ['search' => 'paused@']))
            ->assertOk()->streamedContent();
        self::assertStringContainsString('paused@rokn.test', $csv);
        self::assertStringNotContainsString('active@rokn.test', $csv);
    }

    public function test_exports_are_administrator_only(): void
    {
        $moderator = $this->user('moderator');
        $this->actingAs($moderator)->get(route('admin.orders.export'))->assertForbidden();
        $this->actingAs($moderator)->get(route('admin.users.export'))->assertForbidden();
    }

    public function test_sidebar_links_live_pages_and_dead_pages_are_gone(): void
    {
        $this->actingAs($this->user('admin'))->get(route('admin.coupons.index'))
            ->assertRedirect(CouponResource::getUrl());
        $this->get(CouponResource::getUrl())->assertOk()
            ->assertSee(CouponResource::getUrl(), false)
            ->assertSee(AuditLogResource::getUrl(), false);

        foreach ([
            'admin.urgent-tasks.index', 'admin.urgent-tasks.approve-order', 'admin.urgent-tasks.reject-order',
            'admin.urgent-tasks.activate-student', 'admin.categories.index', 'admin.grades.index',
            'admin.grades.courses', 'admin.feedback.destroy', 'admin.student-progress.statistics',
            'admin.student-progress.compare',
        ] as $name) {
            self::assertNull(Route::getRoutes()->getByName($name), $name);
        }
        foreach (['urgent-tasks', 'grades', 'categories', 'pages/online.blade.php', 'includes/footer.blade.php'] as $view) {
            self::assertFileDoesNotExist(resource_path('views/admin/'.$view));
        }
    }

    public function test_progress_course_filter_lists_each_authored_course_once(): void
    {
        $course = Course::factory()->create(['tenant_id' => 1]);
        $draft = app(CourseStagedAuthoringService::class)->draftFor($course);
        self::assertNotSame($course->id, $draft->id);

        $courses = app(AdminStudentProgressReadService::class)->listing([], [])['courses'];

        self::assertContains($course->id, $courses->modelKeys());
        self::assertNotContains($draft->id, $courses->modelKeys());
    }

    public function test_progress_search_treats_wildcards_literally(): void
    {
        $this->user('client', ['name' => 'Learner 50% off']);
        $this->user('client', ['name' => 'Learner plain']);

        $listing = app(AdminStudentProgressReadService::class)->listing(['search' => '50%'], []);
        self::assertSame(['Learner 50% off'], $listing['users']->getCollection()->pluck('name')->all());
        $listing = app(AdminStudentProgressReadService::class)->listing(['search' => '%'], []);
        self::assertSame(['Learner 50% off'], $listing['users']->getCollection()->pluck('name')->all());
    }

    public function test_phone_numbers_are_isolated_left_to_right_in_rtl_screens(): void
    {
        $admin = $this->user('admin');
        $student = $this->user('client', ['name' => 'Phone Learner', 'phone' => '201055550001']);
        $teacher = $this->user('teacher', ['name' => 'Phone Teacher', 'phone' => '201055550002']);
        $course = Course::factory()->create(['tenant_id' => 1]);
        $order = $this->order($student, $course, ['final_amount' => 50]);
        $contact = Contact::query()->create([
            'name' => 'Phone Contact', 'email' => 'phone-contact@rokn.test',
            'phone' => '201055550003', 'message' => 'رسالة', 'read' => false,
        ]);
        $this->actingAs($admin);

        foreach ([
            'order' => [route('admin.orders.show', $order), '201055550001'],
            'progress profile' => [route('admin.student-progress.show', $student), '201055550001'],
            'progress list' => [route('admin.student-progress.index'), '201055550001'],
            'contacts' => [ContactResource::getUrl(), '201055550003'],
            'contact' => [ContactResource::getUrl('view', ['record' => $contact->id]), '201055550003'],
            'student profile' => [StudentResource::getUrl('view', ['record' => $student->id]), '201055550001'],
        ] as $page => [$url, $phone]) {
            $xpath = $this->page($url);
            self::assertGreaterThan(
                0,
                $xpath->query('//*[normalize-space()="'.$phone.'" and ancestor-or-self::*[@dir="ltr"]]')->length,
                $page
            );
        }

        foreach ([
            'moderator form' => route('admin.moderators.create'),
            'settings' => route('admin.settings'),
        ] as $page => $url) {
            self::assertSame(1, $this->page($url)->query('//input[@name="phone" and @dir="ltr"]')->length, $page);
        }
    }

    private function page(string $url): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$this->get($url)->assertOk()->getContent());

        return new DOMXPath($document);
    }

    /** @param array<string, mixed> $values */
    private function order(User $user, Course $course, array $values): Order
    {
        return Order::query()->create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => $values['final_amount'], 'total_coins' => 100,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED,
            'order_ref' => 'ORD-'.Str::upper(Str::random(8)),
            ...$values,
        ]);
    }

    /** @param array<string, mixed> $values */
    private function user(string $role, array $values = []): User
    {
        return User::query()->forceCreate([
            'name' => ucfirst($role), 'email' => Str::uuid().'@rokn.test', 'role' => $role, 'active' => true,
            ...$values,
        ]);
    }
}
