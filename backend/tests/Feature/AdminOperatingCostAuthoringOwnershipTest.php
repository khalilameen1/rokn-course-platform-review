<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\OperatingCostPoolController;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\OperatingCostPool;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminOperatingCostAuthoringService;
use App\Services\PlatformCommercialReportService;
use App\Support\OperatingCostEditorVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminOperatingCostAuthoringOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        foreach ([OperatingCostPoolController::class, PlatformCommercialReportService::class] as $unrelated) {
            $this->app->bind($unrelated, static function (): never {
                throw new \LogicException('Invoice authoring must not resolve HTTP or report owners.');
            });
        }
    }

    public function test_creation_records_actor_and_finality_with_receipt_in_the_same_transaction(): void
    {
        $actor = $this->actor();
        $before = DB::transactionLevel();
        $completed = [];
        $pool = app(AdminOperatingCostAuthoringService::class)->create(
            [...$this->payload(), 'is_final' => false],
            (int) $actor->id,
            static function (OperatingCostPool $pool) use (&$completed, $before): void {
                self::assertSame($before + 1, DB::transactionLevel());
                self::assertFalse($pool->fresh()->is_final);
                $completed[] = $pool->id;
            }
        );
        self::assertSame([$pool->id], $completed);
        self::assertSame((int) $actor->id, (int) $pool->fresh()->created_by);
        self::assertFalse($pool->fresh()->is_final);
        Http::assertNothingSent();
    }

    public function test_receipt_failure_rolls_back_invoice_and_receipt_together(): void
    {
        try {
            app(AdminOperatingCostAuthoringService::class)->create(
                $this->payload(), (int) $this->actor()->id, static function (): never {
                    DB::table('admin_singleton_locks')->insert(['lock_key' => 'invoice-receipt']);
                    throw new \RuntimeException('Receipt failed');
                }
            );
            self::fail('Receipt failure must roll back its invoice.');
        } catch (\RuntimeException $error) {
            self::assertSame('Receipt failed', $error->getMessage());
        }
        self::assertSame(0, OperatingCostPool::withTrashed()->count());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'invoice-receipt')->exists());
    }

    public function test_update_and_delete_both_reject_stale_editor_without_losing_new_values(): void
    {
        $pool = OperatingCostPool::query()->create($this->payload());
        $stale = OperatingCostEditorVersion::for($pool);
        $writer = app(AdminOperatingCostAuthoringService::class);
        $writer->update((int) $pool->id, [...$this->payload(), 'amount' => 30, 'is_final' => false], $stale);
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $writer->update((int) $pool->id, $this->payload(), $stale);
                } else {
                    $writer->delete((int) $pool->id, $stale);
                }
                self::fail('An outdated editor cannot '.$operation.' the invoice.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('editor_version', $error->errors());
            }
            self::assertSame(30.0, (float) $pool->fresh()->amount);
            self::assertFalse($pool->fresh()->is_final);
        }
        $writer->delete((int) $pool->id, OperatingCostEditorVersion::for($pool->fresh()));
        self::assertNull(OperatingCostPool::find($pool->id));
        self::assertTrue(OperatingCostPool::withTrashed()->findOrFail($pool->id)->trashed());
    }

    public function test_legacy_revision_attribution_is_preserved_but_cannot_be_newly_assigned(): void
    {
        $canonical = $this->course();
        $revision = $this->course();
        CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $canonical->id, 'revision_course_id' => $revision->id,
            'base_authoring_version' => 1, 'status' => CourseAuthoringRevision::ARCHIVED,
            'clone_key' => (string) Str::uuid(),
        ]);
        $pool = OperatingCostPool::query()->create([...$this->payload(), 'course_id' => $revision->id]);
        $writer = app(AdminOperatingCostAuthoringService::class);
        $writer->update((int) $pool->id, [...$this->payload(), 'course_id' => $revision->id, 'name' => 'Historical invoice'], OperatingCostEditorVersion::for($pool));
        self::assertSame((int) $revision->id, (int) $pool->fresh()->course_id);
        self::assertSame('Historical invoice', $pool->fresh()->name);

        $canonicalPool = OperatingCostPool::query()->create([...$this->payload(), 'course_id' => $canonical->id]);
        foreach (['create', 'update'] as $operation) {
            try {
                $data = [...$this->payload(), 'course_id' => $revision->id];
                if ($operation === 'create') {
                    $writer->create($data, (int) $this->actor()->id, static function (): never {
                        self::fail('Rejected attribution must not complete a receipt.');
                    });
                } else {
                    $writer->update((int) $canonicalPool->id, $data, OperatingCostEditorVersion::for($canonicalPool));
                }
                self::fail('New revision attribution must be rejected.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('course_id', $error->errors());
            }
        }
        self::assertSame(2, OperatingCostPool::query()->count());
        self::assertSame((int) $canonical->id, (int) $canonicalPool->fresh()->course_id);
    }

    public function test_exchange_rate_changes_only_its_setting_not_historical_invoices(): void
    {
        $settings = Setting::query()->firstOrCreate([]);
        $settings->fill(['openrouter_usd_to_egp_rate' => 50, 'site_name_ar' => 'رُكن'])->save();
        $version = OperatingCostEditorVersion::exchangeRate($settings);
        // Unrelated settings changes neither invalidate this editor nor get overwritten.
        $settings->fill(['site_name_ar' => 'رُكن للتعلم'])->save();
        $invoice = OperatingCostPool::query()->create([...$this->payload(), 'currency' => 'USD', 'fx_rate_to_egp' => 40]);
        $writer = app(AdminOperatingCostAuthoringService::class);
        $writer->updateExchangeRate(52, $version);
        self::assertSame(52.0, (float) $settings->fresh()->openrouter_usd_to_egp_rate);
        self::assertSame('رُكن للتعلم', $settings->fresh()->site_name_ar);
        self::assertSame(40.0, (float) $invoice->fresh()->fx_rate_to_egp);
        self::assertSame(800.0, $invoice->fresh()->amountEgp());
        try {
            $writer->updateExchangeRate(45, $version);
            self::fail('A stale rate editor must not overwrite the newer rate.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        self::assertSame(52.0, (float) $settings->fresh()->openrouter_usd_to_egp_rate);
        Http::assertNothingSent();
    }

    public function test_outer_rollback_restores_invoice_and_exchange_rate_together(): void
    {
        $pool = OperatingCostPool::query()->create($this->payload());
        $settings = Setting::query()->firstOrCreate([]);
        $settings->fill(['openrouter_usd_to_egp_rate' => 50])->save();
        try {
            DB::transaction(function () use ($pool, $settings): never {
                $writer = app(AdminOperatingCostAuthoringService::class);
                $writer->update((int) $pool->id, [...$this->payload(), 'amount' => 99], OperatingCostEditorVersion::for($pool));
                $writer->updateExchangeRate(60, OperatingCostEditorVersion::exchangeRate($settings));
                $writer->delete((int) $pool->id, OperatingCostEditorVersion::for($pool->fresh()));
                self::assertNull(OperatingCostPool::find($pool->id));
                throw new \RuntimeException('Outer operation failed');
            });
            self::fail('All invoice operations must join the enclosing transaction.');
        } catch (\RuntimeException $error) {
            self::assertSame('Outer operation failed', $error->getMessage());
        }
        self::assertSame(20.0, (float) $pool->fresh()->amount);
        self::assertSame(50.0, (float) $settings->fresh()->openrouter_usd_to_egp_rate);
    }

    private function payload(): array
    {
        return ['name' => 'Invoice', 'service_key' => 'infrastructure', 'period_start' => '2026-09-01',
            'period_end' => '2026-09-08', 'amount' => 20, 'currency' => 'EGP',
            'allocation_driver' => 'playback_gb', 'is_final' => true];
    }

    private function actor(): User
    {
        return User::query()->forceCreate([
            'name_ar' => 'Admin', 'email' => Str::uuid().'@rokn.test',
            'role' => 'admin', 'active' => true, 'password' => 'test-only',
        ]);
    }

    private function course(): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس', 'price' => 400, 'authoring_version' => 1,
        ]);
    }
}
