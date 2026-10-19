<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\CoinEarningMethod;
use App\Models\Package;
use App\Models\User;
use App\Support\PackageEditorVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminEconomyHttpInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المالية', 'email' => 'economy-input@example.test', 'role' => 'admin', 'active' => true]));
    }

    public function test_package_form_creates_and_updates_through_the_existing_writer(): void
    {
        $payload = ['name_ar' => 'حزمة محلية', 'name_en' => 'Local draft', 'price' => 100, 'coins' => 1000, 'authoring_request_id' => (string) Str::uuid()];
        $this->post(route('admin.packages.store'), $payload)->assertRedirect(route('admin.packages.index'))->assertSessionHasNoErrors();
        $package = Package::query()->sole();
        self::assertFalse($package->is_active);
        self::assertFalse($package->google_enabled);
        $this->put(route('admin.packages.update', $package), [
            'name_ar' => 'حزمة محدثة', 'name_en' => 'Updated draft', 'price' => 150, 'coins' => 1000,
            'editor_version' => PackageEditorVersion::for($package),
        ])->assertRedirect(route('admin.packages.index'))->assertSessionHasNoErrors();
        self::assertSame('حزمة محدثة', $package->fresh()->name_ar);
        self::assertSame('150.00', $package->fresh()->price);
    }

    public function test_task_with_an_expiry_date_does_not_require_a_separate_start_date(): void
    {
        $this->post(route('admin.coin-earning-methods.store'), [
            'title_ar' => 'مهمة مؤقتة', 'title_en' => 'Timed task', 'coins_amount' => 5,
            'action_key' => 'local_task_contract', 'ends_at' => now()->addDay()->format('Y-m-d\\TH:i'),
            'authoring_request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('admin.coin-earning-methods.index'))->assertSessionHasNoErrors();
        $method = CoinEarningMethod::query()->where('action_key', 'local_task_contract')->sole();
        self::assertNull($method->starts_at);
        self::assertNotNull($method->ends_at);
    }

    public function test_operating_cost_index_accepts_end_date_only_and_rejects_an_inverted_range(): void
    {
        $this->get(route('admin.operating-costs.index', ['to' => '2026-10-19']))->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('admin.operating-costs.index', ['from' => '2026-10-20', 'to' => '2026-10-19']))->assertSessionHasErrors('to');
    }
}
