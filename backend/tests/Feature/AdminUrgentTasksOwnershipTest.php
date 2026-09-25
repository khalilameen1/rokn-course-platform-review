<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Order;
use App\Models\User;
use App\Services\AdminUrgentTasksReadService;
use App\Services\StudentAccountStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminUrgentTasksOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_overview_keeps_full_counts_but_loads_only_five_rows_per_queue(): void
    {
        $studentIds = $orderIds = [];
        $this->freezeTime();
        for ($i = 0; $i < 7; $i++) {
            $student = $this->user('client', false);
            $studentIds[] = $student->id;
            $orderIds[] = $this->order($student)->id;
        }
        $this->user('admin', false);
        $this->user('moderator', false);
        $active = $this->user('client', true);
        $this->user('client', false)->delete();
        $this->order($active, Order::STATUS_APPROVED);
        $this->order($active)->delete();

        $overview = app(AdminUrgentTasksReadService::class)->overview();
        self::assertSame([
            'pending_orders_count' => 7, 'inactive_students_count' => 7, 'total_urgent_tasks' => 14,
        ], $overview['stats']);
        self::assertSame(array_slice(array_reverse($orderIds), 0, 5), $overview['pendingOrders']->modelKeys());
        self::assertSame(array_slice(array_reverse($studentIds), 0, 5), $overview['inactiveStudents']->modelKeys());
        self::assertCount(5, $overview['accountStateVersions']);
        foreach ($overview['inactiveStudents'] as $student) {
            self::assertSame(app(StudentAccountStateService::class)->editorVersion($student),
                $overview['accountStateVersions'][$student->id]);
        }
        foreach ($overview['pendingOrders'] as $order) {
            self::assertTrue($order->relationLoaded('user'));
            self::assertTrue($order->relationLoaded('course'));
            self::assertTrue($order->relationLoaded('courseCode'));
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_full_lists_have_explicit_stable_pagination_and_versions_only_for_the_page(): void
    {
        $this->freezeTime();
        $studentIds = $orderIds = [];
        for ($i = 0; $i < 22; $i++) {
            $student = $this->user('client', false);
            $studentIds[] = $student->id;
            $orderIds[] = $this->order($student)->id;
        }
        $reader = app(AdminUrgentTasksReadService::class);
        $first = $reader->pendingOrders(1);
        $second = $reader->pendingOrders(2);
        self::assertSame(22, $second->total());
        self::assertSame(20, $second->perPage());
        self::assertSame(2, $second->currentPage());
        self::assertCount(20, $first->items());
        self::assertSame(array_reverse(array_slice($orderIds, 0, 2)), $second->getCollection()->modelKeys());
        self::assertEmpty(array_intersect($first->getCollection()->modelKeys(), $second->getCollection()->modelKeys()));

        $students = $reader->inactiveStudents(2);
        self::assertSame(22, $students['inactiveStudents']->total());
        self::assertSame(array_reverse(array_slice($studentIds, 0, 2)), $students['inactiveStudents']->getCollection()->modelKeys());
        self::assertSame($students['inactiveStudents']->getCollection()->modelKeys(), $students['accountStateVersions']->keys()->all());
    }

    public function test_course_setup_warning_ignores_working_copies_and_retired_originals(): void
    {
        $canonical = $this->course();
        foreach ([CourseAuthoringRevision::DRAFT, CourseAuthoringRevision::ARCHIVED] as $status) {
            $revision = $this->course();
            CourseAuthoringRevision::query()->create([
                'canonical_course_id' => $canonical->id, 'revision_course_id' => $revision->id,
                'status' => $status, 'base_authoring_version' => 1,
                'active_slot' => $status === CourseAuthoringRevision::DRAFT
                    ? CourseAuthoringRevision::draftSlot((int) $canonical->id) : null,
                'clone_key' => (string) Str::uuid(),
            ]);
        }
        $canonical->delete();
        self::assertFalse(app(AdminUrgentTasksReadService::class)->overview()['hasCourses']);
        $this->course(); // An unpublished original is valid setup data.
        self::assertTrue(app(AdminUrgentTasksReadService::class)->overview()['hasCourses']);
    }

    public function test_http_pages_offer_a_review_link_and_keep_old_forms_non_mutating(): void
    {
        $admin = $this->user('admin', true);
        $order = $this->order($this->user('client', false));
        $before = $order->fresh()->getRawOriginal();
        $this->actingAs($admin, 'web');
        foreach (['admin.urgent-tasks.index', 'admin.urgent-tasks.pending-orders'] as $routeName) {
            $this->get(route($routeName))->assertOk()->assertSee('مراجعة الطلب')
                ->assertSee(route('admin.orders.show', $order), false)
                ->assertDontSee(route('admin.urgent-tasks.approve-order', $order), false)
                ->assertDontSee(route('admin.urgent-tasks.reject-order', $order), false);
        }
        foreach (['admin.urgent-tasks.approve-order', 'admin.urgent-tasks.reject-order'] as $routeName) {
            $this->post(route($routeName, $order))->assertRedirect(route('admin.orders.show', $order));
            self::assertSame($before, $order->fresh()->getRawOriginal());
        }
        $this->get(route('admin.urgent-tasks.inactive-students'))->assertOk();
    }

    public function test_pagination_rejects_invalid_input_and_preserves_other_query_parameters(): void
    {
        $this->actingAs($this->user('admin', true), 'web');
        foreach (['admin.urgent-tasks.pending-orders', 'admin.urgent-tasks.inactive-students'] as $routeName) {
            $this->get(route($routeName, ['page' => -1]))->assertSessionHasErrors('page');
        }
        $this->get(route('admin.urgent-tasks.pending-orders', ['page' => 2, 'context' => 'queue']))
            ->assertOk()->assertViewHas('pendingOrders', fn ($page): bool =>
                $page->currentPage() === 2 && str_contains($page->url(1), 'context=queue'));
    }

    private function user(string $role, bool $active): User
    {
        return User::query()->forceCreate([
            'name_ar' => 'مستخدم الاختبار', 'email' => Str::uuid().'@rokn.test',
            'role' => $role, 'active' => $active,
        ]);
    }

    private function order(User $student, string $status = Order::STATUS_PENDING): Order
    {
        return Order::query()->create([
            'user_id' => $student->id, 'order_ref' => (string) Str::uuid(),
            'status' => $status, 'amount' => 100, 'final_amount' => 100,
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
        ]);
    }

    private function course(): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس الاختبار', 'price' => 100,
            'is_coming_soon' => true, 'is_catalog_visible' => true,
        ]);
    }
}
