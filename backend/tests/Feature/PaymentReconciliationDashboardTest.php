<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\AdminAuditLog;
use App\Models\PaymentReconciliationFinding;
use App\Models\User;
use App\Services\PaymentReconciliationReviewService;
use App\Support\AdminEditorVersion;
use App\Support\PaymentFindingEditorVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PaymentReconciliationDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'admin_audit_logs',
            'payment_reconciliation_findings',
            'orders',
            'notifications',
            'contacts',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 30);
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('notifications', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->unsignedBigInteger('notifiable_id');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->boolean('read')->default(false);
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('order_ref')->unique();
            $table->string('status', 32)->default('pending');
            $table->string('financial_status', 32)->default('pending');
            $table->unsignedInteger('total_coins')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('payment_reconciliation_findings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_ref', 191);
            $table->char('fingerprint', 64)->unique();
            $table->string('kind', 64);
            $table->string('local_status', 32)->nullable();
            $table->string('local_financial_status', 32)->nullable();
            $table->string('provider_status', 32)->nullable();
            $table->string('provider_transaction_id', 191)->nullable();
            $table->string('state', 16)->default('open');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->text('resolution_note')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('request_id', 100);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_role', 30);
            $table->string('route_name')->nullable();
            $table->string('http_method', 10);
            $table->string('path', 500);
            $table->json('route_parameters')->nullable();
            $table->json('request_fields')->nullable();
            $table->unsignedSmallInteger('response_status');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('occurred_at');
        });

        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    protected function tearDown(): void
    {
        foreach ([
            'admin_audit_logs',
            'payment_reconciliation_findings',
            'orders',
            'notifications',
            'contacts',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_administrator_can_filter_the_review_queue(): void
    {
        $admin = $this->user('admin');
        $expected = $this->finding('ORDER-MATCH-100', 'provider_unavailable');
        $this->finding('ORDER-OTHER-200', 'provider_status_missing');
        $this->finding(
            'ORDER-CLOSED-300',
            'provider_unavailable',
            PaymentReconciliationFinding::STATE_IGNORED
        );

        $response = $this->actingAs($admin)->get(route(
            'admin.payment-reconciliation-findings.index',
            [
                'state' => PaymentReconciliationFinding::STATE_OPEN,
                'kind' => 'provider_unavailable',
                'order_ref' => 'MATCH',
            ]
        ));

        $response->assertOk()
            ->assertSee($expected->order_ref)
            ->assertDontSee('ORDER-OTHER-200')
            ->assertDontSee('ORDER-CLOSED-300')
            ->assertSee('لا تغيّر الطلب أو الرصيد');
    }

    public function test_review_actions_only_change_the_finding_and_are_audited(): void
    {
        $admin = $this->user('admin');
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $admin->id,
            'order_ref' => 'ORDER-SAFE-400',
            'status' => 'approved',
            'financial_status' => 'review_required',
            'total_coins' => 750,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $finding = $this->finding('ORDER-SAFE-400', 'provider_reversal_requires_review');
        $finding->update(['order_id' => $orderId]);
        $orderBefore = DB::table('orders')->where('id', $orderId)->first();

        $this->actingAs($admin)->patch(
            route('admin.payment-reconciliation-findings.resolve', $finding),
            [
                'note' => 'راجعت إثبات البوابة وربط المعاملة يدويًا.',
                'editor_version' => $this->findingEditorVersion($finding),
            ]
        )->assertRedirect();
        $finding->refresh();
        self::assertSame(PaymentReconciliationFinding::STATE_RESOLVED, $finding->state);
        self::assertSame($admin->id, $finding->resolved_by);
        self::assertNotNull($finding->resolved_at);

        $this->patch(
            route('admin.payment-reconciliation-findings.reopen', $finding),
            [
                'note' => 'ظهر دليل جديد ويجب إعادة الفحص.',
                'editor_version' => $this->findingEditorVersion($finding),
            ]
        )->assertRedirect();
        $finding->refresh();
        self::assertSame(PaymentReconciliationFinding::STATE_OPEN, $finding->state);
        self::assertNull($finding->resolved_by);
        self::assertNull($finding->resolved_at);

        $this->patch(
            route('admin.payment-reconciliation-findings.ignore', $finding),
            [
                'note' => 'اختلاف معروف لا يحتاج تعديلًا ماليًا.',
                'editor_version' => $this->findingEditorVersion($finding),
            ]
        )->assertRedirect();
        $finding->refresh();
        self::assertSame(PaymentReconciliationFinding::STATE_IGNORED, $finding->state);
        self::assertSame('اختلاف معروف لا يحتاج تعديلًا ماليًا.', $finding->resolution_note);

        self::assertEquals($orderBefore, DB::table('orders')->where('id', $orderId)->first());
        foreach ([
            'admin.payment-reconciliation-findings.resolve',
            'admin.payment-reconciliation-findings.reopen',
            'admin.payment-reconciliation-findings.ignore',
        ] as $routeName) {
            $audit = AdminAuditLog::query()->where('route_name', $routeName)->first();
            self::assertNotNull($audit, $routeName);
            self::assertSame($admin->id, $audit->actor_id);
            self::assertSame('PATCH', $audit->http_method);
            self::assertContains('note', $audit->request_fields);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $audit->ip_address);
            if ($audit->user_agent !== null) {
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $audit->user_agent);
            }
            self::assertNotSame('127.0.0.1', $audit->ip_address);
        }
    }

    public function test_actions_require_a_note_and_reject_stale_transitions(): void
    {
        $admin = $this->user('admin');
        $finding = $this->finding('ORDER-VALIDATE-500', 'provider_unavailable');

        $this->actingAs($admin)
            ->patch(route('admin.payment-reconciliation-findings.resolve', $finding), [
                'editor_version' => $this->findingEditorVersion($finding),
            ])
            ->assertSessionHasErrors('note');
        self::assertSame(PaymentReconciliationFinding::STATE_OPEN, $finding->fresh()->state);

        $this->patch(
            route('admin.payment-reconciliation-findings.reopen', $finding),
            [
                'note' => 'محاولة انتقال قديم',
                'editor_version' => $this->findingEditorVersion($finding),
            ]
        )->assertSessionHasErrors('finding');
        self::assertSame(PaymentReconciliationFinding::STATE_OPEN, $finding->fresh()->state);
    }

    public function test_moderator_cannot_open_the_financial_review_queue(): void
    {
        $moderator = $this->user('moderator');

        $this->actingAs($moderator)
            ->get(route('admin.payment-reconciliation-findings.index'))
            ->assertForbidden();
    }

    public function test_shared_editor_version_preserves_the_existing_page_contract(): void
    {
        $finding = $this->finding('ORDER-VERSION', 'provider_unavailable')->fresh();
        self::assertSame($this->findingEditorVersion($finding), PaymentFindingEditorVersion::for($finding));
    }

    public function test_review_service_owns_transitions_and_participates_in_the_callers_transaction(): void
    {
        $admin = $this->user('admin');
        $finding = $this->finding('ORDER-OWNER', 'provider_unavailable')->fresh();
        $version = PaymentFindingEditorVersion::for($finding);
        $service = app(PaymentReconciliationReviewService::class);
        DB::beginTransaction();
        try {
            $service->transition($finding->id, PaymentReconciliationFinding::STATE_RESOLVED,
                '  راجعت دليل الدفع  ', $version, $admin->id);
            self::assertSame('راجعت دليل الدفع', $finding->fresh()->resolution_note);
            self::assertSame($admin->id, $finding->fresh()->resolved_by);
            self::assertNotNull($finding->fresh()->resolved_at);
        } finally {
            DB::rollBack();
        }
        self::assertSame(PaymentReconciliationFinding::STATE_OPEN, $finding->fresh()->state);
        self::assertNull($finding->fresh()->resolution_note);

        $service->transition($finding->id, PaymentReconciliationFinding::STATE_IGNORED,
            'اختلاف معروف', $version, $admin->id);
        $service->transition($finding->id, PaymentReconciliationFinding::STATE_OPEN,
            'ظهر دليل جديد', PaymentFindingEditorVersion::for($finding->fresh()), $admin->id);
        $finding->refresh();
        self::assertSame(PaymentReconciliationFinding::STATE_OPEN, $finding->state);
        self::assertNull($finding->resolved_at);
        self::assertNull($finding->resolved_by);
        self::assertSame('ظهر دليل جديد', $finding->resolution_note);
    }

    public function test_new_evidence_invalidates_a_review_even_when_the_state_has_not_changed(): void
    {
        $admin = $this->user('admin');
        $finding = $this->finding('ORDER-NEW-EVIDENCE', 'provider_unavailable')->fresh();
        $version = PaymentFindingEditorVersion::for($finding);
        $finding->update(['evidence' => ['provider_status' => 'paid'], 'attempts' => 3]);
        $before = $finding->fresh()->getRawOriginal();

        $this->actingAs($admin)->patch(route('admin.payment-reconciliation-findings.resolve', $finding), [
            'note' => 'قرار على دليل قديم', 'editor_version' => $version,
        ])->assertSessionHasErrors('finding');
        self::assertSame($before, $finding->fresh()->getRawOriginal());
    }

    public function test_direct_review_rejects_blank_notes_and_closed_to_closed_transitions(): void
    {
        $admin = $this->user('admin');
        $finding = $this->finding('ORDER-DIRECT-GUARDS', 'provider_unavailable')->fresh();
        $service = app(PaymentReconciliationReviewService::class);
        try {
            $service->transition($finding->id, PaymentReconciliationFinding::STATE_RESOLVED,
                '   ', PaymentFindingEditorVersion::for($finding), $admin->id);
            self::fail('A review needs a meaningful note.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('note', $e->errors());
        }
        self::assertSame(PaymentReconciliationFinding::STATE_OPEN, $finding->fresh()->state);
        $service->transition($finding->id, PaymentReconciliationFinding::STATE_RESOLVED,
            'دليل كاف', PaymentFindingEditorVersion::for($finding), $admin->id);
        $before = $finding->fresh()->getRawOriginal();
        try {
            $service->transition($finding->id, PaymentReconciliationFinding::STATE_IGNORED,
                'تغيير القرار', PaymentFindingEditorVersion::for($finding->fresh()), $admin->id);
            self::fail('Closed findings must first be reopened.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('finding', $e->errors());
        }
        self::assertSame($before, $finding->fresh()->getRawOriginal());
    }

    private function user(string $role): User
    {
        return User::query()->forceCreate([
            'name' => ucfirst($role),
            'email' => "{$role}@rokn.test",
            'password' => Hash::make('password'),
            'role' => $role,
            'active' => true,
        ]);
    }

    private function findingEditorVersion(PaymentReconciliationFinding $finding): string
    {
        return AdminEditorVersion::for($finding, [
            'state',
            'attempts',
            'last_seen_at',
            'local_status',
            'local_financial_status',
            'provider_status',
            'provider_transaction_id',
            'evidence',
        ]);
    }

    private function finding(
        string $orderRef,
        string $kind,
        string $state = PaymentReconciliationFinding::STATE_OPEN
    ): PaymentReconciliationFinding {
        return PaymentReconciliationFinding::query()->create([
            'provider' => 'kashier',
            'order_ref' => $orderRef,
            'fingerprint' => hash('sha256', $orderRef.'|'.$kind.'|'.$state),
            'kind' => $kind,
            'local_status' => 'approved',
            'local_financial_status' => 'review_required',
            'provider_status' => 'reversed',
            'provider_transaction_id' => 'TXN-'.$orderRef,
            'state' => $state,
            'attempts' => 2,
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
            'evidence' => ['provider_status' => 'reversed'],
        ]);
    }
}
