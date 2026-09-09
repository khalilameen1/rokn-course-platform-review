<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\OperatingCostPoolController;
use App\Models\OperatingCostPool;
use App\Support\AdminEditorVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class OperatingInvoiceEntryContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('courses', function (Blueprint $t): void {
            $t->id(); $t->string('name_ar'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('course_authoring_revisions', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('canonical_course_id'); $t->unsignedBigInteger('revision_course_id');
        });
        Schema::create('users', function (Blueprint $t): void {
            $t->id(); $t->string('role'); $t->softDeletes();
        });
        Schema::create('course_enrollments', function (Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('course_id'); $t->unsignedBigInteger('user_id');
            $t->boolean('is_active'); $t->timestamp('expires_at')->nullable();
        });
        Schema::create('settings', function (Blueprint $t): void {
            $t->id(); $t->decimal('openrouter_usd_to_egp_rate', 12, 4)->nullable(); $t->timestamps();
        });
        Schema::create('admin_singleton_locks', function (Blueprint $t): void {
            $t->string('lock_key')->primary(); $t->timestamps();
        });
        Schema::create('operating_cost_pools', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('service_key'); $t->unsignedBigInteger('course_id')->nullable();
            $t->date('period_start'); $t->date('period_end'); $t->decimal('amount', 14, 4); $t->string('currency');
            $t->decimal('fx_rate_to_egp', 12, 4)->nullable(); $t->string('allocation_driver');
            $t->boolean('is_final'); $t->text('notes')->nullable(); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        DB::table('courses')->insert([['id' => 3, 'name_ar' => 'Canonical'], ['id' => 4, 'name_ar' => 'Archived revision'], ['id' => 7, 'name_ar' => 'Working copy']]);
        DB::table('course_authoring_revisions')->insert([['canonical_course_id' => 3, 'revision_course_id' => 4], ['canonical_course_id' => 3, 'revision_course_id' => 7]]);
    }

    protected function tearDown(): void
    {
        foreach (['operating_cost_pools', 'admin_singleton_locks', 'settings', 'course_enrollments', 'users', 'course_authoring_revisions', 'courses'] as $table) Schema::dropIfExists($table);
        parent::tearDown();
    }

    public function test_choices_and_report_links_exclude_revisions_but_keep_existing_invoice_history(): void
    {
        $invoice = OperatingCostPool::create($this->payload() + ['course_id' => 4]);
        $data = app(OperatingCostPoolController::class)->index(Request::create('/invoices'))->getData();
        self::assertSame([3], $data['courses']->pluck('id')->all());
        self::assertSame(4, (int) $data['pools']->first()->course_id);
        self::assertSame($invoice->id, $data['pools']->first()->id);
    }

    public function test_actual_form_has_no_allocation_control_and_fx_version_belongs_to_fx_form(): void
    {
        $data = app(OperatingCostPoolController::class)->index(Request::create('/invoices'))->getData();
        $source = str_replace("@extends('admin.layouts.app')", '', file_get_contents(resource_path('views/admin/operating-costs/index.blade.php')));
        $html = Blade::render($source."\n@yield('content')", $data);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        self::assertSame(0, $xpath->query('//select[@name="allocation_driver"]')->length);
        self::assertSame(1, $xpath->query('//input[@name="allocation_driver" and @type="hidden"]')->length);
        $fx = $xpath->query('//form[contains(@action,"operating-costs-exchange-rate")]')->item(0);
        self::assertNotNull($fx);
        self::assertSame(1, $xpath->query('.//input[@name="editor_version"]', $fx)->length);
        $version = $xpath->query('.//input[@name="editor_version"]', $fx)->item(0)->getAttribute('value');
        app(OperatingCostPoolController::class)->updateExchangeRate(Request::create('/fx', 'POST', [
            'editor_version' => $version, 'openrouter_usd_to_egp_rate' => 52,
        ]));
        self::assertSame(52.0, (float) DB::table('settings')->value('openrouter_usd_to_egp_rate'));
        self::assertStringNotContainsString('النظام يوزعها', strip_tags($html));
        self::assertStringNotContainsString('طريقة التوزيع', strip_tags($html));
    }

    public function test_legacy_revision_invoice_can_be_edited_without_reassigning_or_changing_driver(): void
    {
        $invoice = OperatingCostPool::create($this->payload() + ['course_id' => 4]);
        $version = AdminEditorVersion::for($invoice, ['name', 'service_key', 'course_id', 'period_start', 'period_end', 'amount', 'currency', 'fx_rate_to_egp', 'allocation_driver', 'is_final', 'notes']);
        $request = Request::create('/invoices/'.$invoice->id, 'PUT', ['editor_version' => $version, 'name' => 'Updated', 'course_id' => 4] + $this->payload());
        app(OperatingCostPoolController::class)->update($request, $invoice);
        self::assertSame(4, (int) $invoice->fresh()->course_id);
        self::assertSame('playback_gb', $invoice->fresh()->allocation_driver);
        self::assertSame('Updated', $invoice->fresh()->name);
    }

    public function test_new_invoice_accepts_canonical_scope_but_rejects_authoring_copy(): void
    {
        $controller = app(OperatingCostPoolController::class);
        $service = app(\App\Services\AdminAuthoringCreateIntentService::class);
        $request = Request::create('/invoices', 'POST', ['course_id' => 3,
            'authoring_request_id' => (string) \Illuminate\Support\Str::uuid()] + $this->payload());
        $request->setUserResolver(fn () => new \App\Models\User(['id' => 1]));
        $controller->store($request, $service);
        self::assertSame(3, (int) OperatingCostPool::query()->first()->course_id);
        $request->merge(['course_id' => 7, 'authoring_request_id' => (string) \Illuminate\Support\Str::uuid()]);
        try {
            $controller->store($request, $service);
            self::fail('An authoring copy is not a financial course identity.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            self::assertArrayHasKey('course_id', $error->errors());
        }
        self::assertSame(1, OperatingCostPool::query()->count());
    }

    public function test_update_cannot_move_invoice_to_a_different_authoring_copy(): void
    {
        $invoice = OperatingCostPool::create($this->payload() + ['course_id' => 3]);
        $version = AdminEditorVersion::for($invoice, ['name', 'service_key', 'course_id', 'period_start', 'period_end', 'amount', 'currency', 'fx_rate_to_egp', 'allocation_driver', 'is_final', 'notes']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(OperatingCostPoolController::class)->update(Request::create('/invoices/'.$invoice->id, 'PUT',
            ['editor_version' => $version, 'course_id' => 7] + $this->payload()), $invoice);
    }

    private function payload(): array
    {
        return ['name' => 'Invoice', 'service_key' => 'infrastructure', 'period_start' => '2026-09-01',
            'period_end' => '2026-09-08', 'amount' => 20, 'currency' => 'EGP', 'allocation_driver' => 'playback_gb', 'is_final' => true];
    }
}
