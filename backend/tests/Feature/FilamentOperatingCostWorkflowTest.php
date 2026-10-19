<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\OperatingCostResource;
use App\Filament\Resources\OperatingCostResource\Pages\CreateOperatingCost;
use App\Filament\Resources\OperatingCostResource\Pages\EditOperatingCost;
use App\Filament\Resources\OperatingCostResource\Pages\ListOperatingCosts;
use App\Filament\Widgets\OperatingCostSummary;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\OperatingCostPool;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminOperatingCostReadService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentOperatingCostWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المالية', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_invoice_create_and_edit_preserve_exact_currency_and_dates(): void
    {
        Livewire::test(CreateOperatingCost::class)->fillForm($this->fields())->call('create')->assertHasNoFormErrors();
        $invoice = OperatingCostPool::query()->sole();
        self::assertSame('12.3456', $invoice->amount);
        self::assertSame('50.0000', $invoice->fx_rate_to_egp);
        self::assertSame('2026-10-01', $invoice->period_start->format('Y-m-d'));
        self::assertSame((int) auth('web')->id(), (int) $invoice->getAttribute('created_by'));
        Livewire::test(EditOperatingCost::class, ['record' => $invoice->id])->fillForm(['amount' => '15.4321', 'is_final' => false])
            ->call('save')->assertHasNoFormErrors();
        self::assertSame('15.4321', $invoice->fresh()->amount);
        self::assertFalse($invoice->fresh()->is_final);
        self::assertSame('active_students', $invoice->fresh()->getAttribute('allocation_driver'));
    }

    public function test_invoice_bounds_date_order_and_required_usd_rate_have_visible_errors(): void
    {
        Livewire::test(CreateOperatingCost::class)->fillForm([...$this->fields(), 'fx_rate_to_egp' => null])->call('create')->assertHasFormErrors(['fx_rate_to_egp' => 'required']);
        Livewire::test(CreateOperatingCost::class)->fillForm([...$this->fields(), 'period_end' => '2026-09-30'])->call('create')->assertHasFormErrors(['period_end' => 'after_or_equal']);
        Livewire::test(CreateOperatingCost::class)->fillForm([...$this->fields(), 'amount' => -1])->call('create')->assertHasFormErrors(['amount' => 'min']);
        self::assertSame(0, OperatingCostPool::query()->count());
    }

    public function test_summary_follows_filters_and_excludes_estimates_and_missing_rates(): void
    {
        $final = OperatingCostPool::query()->create($this->fields());
        $estimate = OperatingCostPool::query()->create([...$this->fields(), 'name' => 'تقدير', 'amount' => 20, 'currency' => 'EGP', 'is_final' => false]);
        OperatingCostPool::query()->create([...$this->fields(), 'name' => 'بلا تحويل', 'fx_rate_to_egp' => null]);
        $reader = app(AdminOperatingCostReadService::class);
        self::assertSame(['actual_egp' => 617.28, 'estimated_egp' => 20.0, 'missing_fx' => 1], $reader->summary($reader->invoices()));
        Livewire::test(ListOperatingCosts::class)->assertCanSeeTableRecords([$final, $estimate])->filterTable('is_final', true)->assertCanNotSeeTableRecords([$estimate]);
        Livewire::test(OperatingCostSummary::class, ['tableSearch' => 'تقدير'])->assertSee('20.00')->assertDontSee('617.28');
        Livewire::test(ListOperatingCosts::class)->filterTable('criteria', ['to' => '2026-09-30'])->assertCanNotSeeTableRecords([$final, $estimate]);
        Livewire::test(ListOperatingCosts::class)->set('tableDeferredFilters.criteria', ['from' => '2026-10-01', 'to' => '2026-10-19'])
            ->call('applyTableFilters')->assertHasNoErrors()->assertCanSeeTableRecords([$final]);
        Livewire::test(ListOperatingCosts::class)->set('tableDeferredFilters.criteria', ['from' => '2026-10-20', 'to' => '2026-10-19'])
            ->call('applyTableFilters')->assertHasErrors(['tableDeferredFilters.criteria.to' => 'after_or_equal']);
    }

    public function test_exchange_rate_action_uses_a_separate_locked_version_without_repricing_invoices(): void
    {
        $setting = Setting::query()->firstOrCreate([]);
        $setting->fill(['openrouter_usd_to_egp_rate' => 40])->save();
        $invoice = OperatingCostPool::query()->create($this->fields());
        $stale = Livewire::test(ListOperatingCosts::class)->mountAction('exchangeRate');
        Livewire::test(ListOperatingCosts::class)->callAction('exchangeRate', ['rate' => 52])->assertHasNoActionErrors();
        self::assertSame(52.0, (float) $setting->fresh()->openrouter_usd_to_egp_rate);
        self::assertSame('50.0000', $invoice->fresh()->fx_rate_to_egp);
        $stale->setActionData(['rate' => 45])->callMountedAction();
        self::assertSame(52.0, (float) $setting->fresh()->openrouter_usd_to_egp_rate);
    }

    public function test_stale_invoice_editor_and_delete_confirmation_cannot_overwrite_newer_values(): void
    {
        $invoice = OperatingCostPool::query()->create($this->fields());
        $editor = Livewire::test(EditOperatingCost::class, ['record' => $invoice->id]);
        $list = Livewire::test(ListOperatingCosts::class)->mountTableAction('remove', $invoice);
        $invoice->update(['name' => 'الأحدث']);
        $editor->fillForm(['name' => 'قديم'])->call('save');
        $list->callMountedTableAction();
        self::assertSame('الأحدث', $invoice->fresh()->name);
        self::assertFalse($invoice->fresh()->trashed());
        Livewire::test(ListOperatingCosts::class)->callTableAction('remove', $invoice->fresh());
        self::assertTrue($invoice->fresh()->trashed());
    }

    public function test_archived_originals_remain_selectable_but_new_revision_attribution_is_rejected(): void
    {
        $canonical = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'الكورس الأصلي', 'price' => 100]);
        $revision = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'نسخة تأليف', 'price' => 100]);
        CourseAuthoringRevision::query()->create(['canonical_course_id' => $canonical->id, 'revision_course_id' => $revision->id,
            'base_authoring_version' => 1, 'status' => CourseAuthoringRevision::ARCHIVED, 'clone_key' => (string) Str::uuid()]);
        $canonical->delete();
        self::assertSame([$canonical->id], array_keys(app(AdminOperatingCostReadService::class)->courseOptions('')));
        Livewire::test(CreateOperatingCost::class)->fillForm([...$this->fields(), 'course_id' => $revision->id])->call('create')->assertHasFormErrors(['course_id']);
        $invoice = OperatingCostPool::query()->create([...$this->fields(), 'course_id' => $revision->id, 'allocation_driver' => 'playback_gb']);
        Livewire::test(EditOperatingCost::class, ['record' => $invoice->id])->fillForm(['name' => 'محفوظ'])->call('save')->assertHasNoFormErrors();
        self::assertSame((int) $revision->id, (int) $invoice->fresh()->getAttribute('course_id'));
        self::assertSame('playback_gb', $invoice->fresh()->getAttribute('allocation_driver'));
    }

    public function test_old_bookmarks_and_financial_permissions(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $invoice = OperatingCostPool::query()->create($this->fields());
        $this->get(route('admin.operating-costs.index', ['edit_cost' => $invoice->id]))->assertRedirect(OperatingCostResource::getUrl('edit', ['record' => $invoice->id]));
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(ListOperatingCosts::class)->assertForbidden();
        Livewire::test(CreateOperatingCost::class)->assertForbidden();
        Livewire::test(EditOperatingCost::class, ['record' => $invoice->id])->assertForbidden();
    }

    private function fields(): array
    {
        return ['name' => 'فاتورة استضافة', 'service_key' => 'infrastructure', 'course_id' => null, 'period_start' => '2026-10-01',
            'period_end' => '2026-10-19', 'amount' => '12.3456', 'currency' => 'USD', 'fx_rate_to_egp' => '50.0000', 'allocation_driver' => 'active_students', 'is_final' => true];
    }
}
