<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\OperatingCostPoolController;
use App\Http\Requests\Admin\OperatingCostProfileRequest;
use App\Models\OperatingCostPool;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminOperatingCostReadService;
use App\Support\AdminEditorVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ValidatesFormRequests;
use Tests\TestCase;

final class OperatingInvoiceEntryContractTest extends TestCase
{
    use ValidatesFormRequests;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('courses', function (Blueprint $t): void {
            $t->id();
            $t->string('name_ar');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('course_authoring_revisions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('canonical_course_id');
            $t->unsignedBigInteger('revision_course_id');
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('role');
            $t->softDeletes();
        });
        Schema::create('course_enrollments', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('course_id');
            $t->unsignedBigInteger('user_id');
            $t->boolean('is_active');
            $t->timestamp('expires_at')->nullable();
        });
        Schema::create('settings', function (Blueprint $t): void {
            $t->id();
            $t->decimal('openrouter_usd_to_egp_rate', 12, 4)->nullable();
            $t->timestamps();
        });
        Schema::create('admin_singleton_locks', function (Blueprint $t): void {
            $t->string('lock_key')->primary();
            $t->timestamps();
        });
        Schema::create('operating_cost_pools', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('service_key');
            $t->unsignedBigInteger('course_id')->nullable();
            $t->date('period_start');
            $t->date('period_end');
            $t->decimal('amount', 14, 4);
            $t->string('currency');
            $t->decimal('fx_rate_to_egp', 12, 4)->nullable();
            $t->string('allocation_driver');
            $t->boolean('is_final');
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        DB::table('courses')->insert([['id' => 3, 'name_ar' => 'Canonical'], ['id' => 4, 'name_ar' => 'Archived revision'], ['id' => 7, 'name_ar' => 'Working copy']]);
        DB::table('course_authoring_revisions')->insert([['canonical_course_id' => 3, 'revision_course_id' => 4], ['canonical_course_id' => 3, 'revision_course_id' => 7]]);
    }

    protected function tearDown(): void
    {
        foreach (['operating_cost_pools', 'admin_singleton_locks', 'settings', 'course_enrollments', 'users', 'course_authoring_revisions', 'courses'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_choices_and_report_links_exclude_revisions_but_keep_existing_invoice_history(): void
    {
        $invoice = OperatingCostPool::create($this->payload() + ['course_id' => 4]);
        $reader = app(AdminOperatingCostReadService::class);
        self::assertSame([3], $reader->courses()->pluck('id')->all());
        self::assertSame(4, (int) $reader->invoices()->first()->course_id);
        self::assertSame($invoice->id, $reader->invoices()->first()->id);
    }

    public function test_legacy_revision_invoice_can_be_edited_without_reassigning_or_changing_driver(): void
    {
        $invoice = OperatingCostPool::create($this->payload() + ['course_id' => 4]);
        $version = AdminEditorVersion::for($invoice, ['name', 'service_key', 'course_id', 'period_start', 'period_end', 'amount', 'currency', 'fx_rate_to_egp', 'allocation_driver', 'is_final', 'notes']);
        $request = Request::create('/invoices/'.$invoice->id, 'PUT', ['editor_version' => $version, 'name' => 'Updated', 'course_id' => 4] + $this->payload());
        app(OperatingCostPoolController::class)->update($this->validatedFormRequest(OperatingCostProfileRequest::class, $request), $invoice);
        self::assertSame(4, (int) $invoice->fresh()->course_id);
        self::assertSame('playback_gb', $invoice->fresh()->allocation_driver);
        self::assertSame('Updated', $invoice->fresh()->name);
    }

    public function test_new_invoice_accepts_canonical_scope_but_rejects_authoring_copy(): void
    {
        $controller = app(OperatingCostPoolController::class);
        $service = app(AdminAuthoringCreateIntentService::class);
        $request = Request::create('/invoices', 'POST', ['course_id' => 3,
            'authoring_request_id' => (string) Str::uuid()] + $this->payload());
        $request->setUserResolver(fn () => new User(['id' => 1]));
        $controller->store($this->validatedFormRequest(OperatingCostProfileRequest::class, $request), $service);
        self::assertSame(3, (int) OperatingCostPool::query()->first()->course_id);
        $request->merge(['course_id' => 7, 'authoring_request_id' => (string) Str::uuid()]);
        try {
            $controller->store($this->validatedFormRequest(OperatingCostProfileRequest::class, $request), $service);
            self::fail('An authoring copy is not a financial course identity.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('course_id', $error->errors());
        }
        self::assertSame(1, OperatingCostPool::query()->count());
    }

    public function test_update_cannot_move_invoice_to_a_different_authoring_copy(): void
    {
        $invoice = OperatingCostPool::create($this->payload() + ['course_id' => 3]);
        $version = AdminEditorVersion::for($invoice, ['name', 'service_key', 'course_id', 'period_start', 'period_end', 'amount', 'currency', 'fx_rate_to_egp', 'allocation_driver', 'is_final', 'notes']);
        $this->expectException(ValidationException::class);
        app(OperatingCostPoolController::class)->update($this->validatedFormRequest(OperatingCostProfileRequest::class, Request::create('/invoices/'.$invoice->id, 'PUT',
            ['editor_version' => $version, 'course_id' => 7] + $this->payload())), $invoice);
    }

    private function payload(): array
    {
        return ['name' => 'Invoice', 'service_key' => 'infrastructure', 'period_start' => '2026-09-01',
            'period_end' => '2026-09-08', 'amount' => 20, 'currency' => 'EGP', 'allocation_driver' => 'playback_gb', 'is_final' => true];
    }
}
