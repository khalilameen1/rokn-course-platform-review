<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ProductFeatureFlag;
use App\Models\User;
use App\Http\Middleware\RequireAdminMfa;
use App\Services\ProductFeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ProductFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_preserve_learning_without_exposing_control_plane_metadata(): void
    {
        $service = app(ProductFeatureFlagService::class);

        self::assertTrue($service->enabled('playback', 1));
        self::assertTrue($service->enabled('checkout', 1));
        $snapshot = $service->clientSnapshot(45);
        self::assertTrue($snapshot['flags']['playback']);
        self::assertArrayNotHasKey('safe_defaults', $snapshot);
        self::assertArrayNotHasKey('capabilities', $snapshot);
    }

    public function test_disabled_and_expired_flags_fail_closed(): void
    {
        ProductFeatureFlag::query()->create([
            'key' => 'checkout',
            'enabled' => false,
            'rollout_percentage' => 100,
            'owner' => 'payments',
            'reason' => 'provider incident',
        ]);
        ProductFeatureFlag::query()->create([
            'key' => 'ai_chat',
            'enabled' => true,
            'rollout_percentage' => 100,
            'owner' => 'learning',
            'reason' => 'expired experiment',
            'expires_at' => now()->subMinute(),
        ]);

        $service = app(ProductFeatureFlagService::class);
        self::assertFalse($service->enabled('checkout', 42));
        self::assertFalse($service->enabled('ai_chat', 42));
    }

    public function test_public_snapshot_applies_deterministic_rollout_bucket(): void
    {
        ProductFeatureFlag::query()->create([
            'key' => 'project_uploads',
            'enabled' => true,
            'rollout_percentage' => 25,
            'owner' => 'learning',
            'reason' => 'staged release',
        ]);

        $service = app(ProductFeatureFlagService::class);
        self::assertTrue($service->clientSnapshot(24)['flags']['project_uploads']);
        self::assertFalse($service->clientSnapshot(25)['flags']['project_uploads']);
    }

    public function test_disaster_recovery_mode_closes_checkout_in_both_server_and_client_contracts(): void
    {
        $service = app(ProductFeatureFlagService::class);
        config(['operations.disaster_recovery_mode' => false]);
        $normal = $service->clientSnapshot(45);

        self::assertTrue($service->enabled('checkout', 45));
        self::assertTrue($normal['flags']['checkout']);

        config(['operations.disaster_recovery_mode' => true]);
        $recovery = $service->clientSnapshot(45);

        self::assertFalse($service->enabled('checkout', 45));
        self::assertFalse($recovery['flags']['checkout']);
        self::assertNotSame($normal['version'], $recovery['version']);
    }

    public function test_versioned_public_endpoint_exposes_only_the_closed_client_snapshot(): void
    {
        ProductFeatureFlag::query()->create([
            'key' => 'checkout',
            'enabled' => false,
            'rollout_percentage' => 100,
            'owner' => 'payments',
            'reason' => 'provider incident',
        ]);

        $this->getJson('/api/v1/product-features?bucket=42')
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.flags.checkout', false)
            ->assertJsonPath('data.flags.playback', true)
            ->assertJsonMissingPath('data.safe_defaults')
            ->assertJsonMissingPath('data.capabilities')
            ->assertJsonMissingPath('data.checkout.owner')
            ->assertJsonMissingPath('data.checkout.reason');
    }

    public function test_administrator_can_change_a_gate_only_with_a_reason(): void
    {
        $admin = User::query()->forceCreate([
            'name' => 'Operations Admin',
            'email' => 'operations@rokn.test',
            'password' => Hash::make('not-used-in-this-test'),
            'role' => 'admin',
            'active' => true,
        ]);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $editorVersion = app(ProductFeatureFlagService::class)
            ->operationalSnapshot()['ai_chat']['editor_version'];

        $this->actingAs($admin)
            ->post(route('admin.product-operations.features.update', 'ai_chat'), [
                'enabled' => '0',
                'rollout_percentage' => 100,
                'reason' => 'OpenRouter provider incident INC-204',
                'editor_version' => $editorVersion,
            ])
            ->assertRedirect();

        $flag = ProductFeatureFlag::query()->where('key', 'ai_chat')->firstOrFail();
        self::assertFalse($flag->enabled);
        self::assertSame('operations@rokn.test', $flag->owner);
        self::assertSame('OpenRouter provider incident INC-204', $flag->reason);
    }

    public function test_stale_feature_gate_form_cannot_reverse_a_newer_operations_decision(): void
    {
        $admin = User::query()->forceCreate([
            'name' => 'Operations Admin',
            'email' => 'operations-stale@rokn.test',
            'password' => Hash::make('not-used-in-this-test'),
            'role' => 'admin',
            'active' => true,
        ]);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $service = app(ProductFeatureFlagService::class);
        $staleVersion = $service->operationalSnapshot()['checkout']['editor_version'];

        $this->actingAs($admin)
            ->post(route('admin.product-operations.features.update', 'checkout'), [
                'enabled' => '0',
                'rollout_percentage' => 0,
                'reason' => 'Provider incident INC-301',
                'editor_version' => $staleVersion,
            ])
            ->assertRedirect();

        $this->post(route('admin.product-operations.features.update', 'checkout'), [
            'enabled' => '1',
            'rollout_percentage' => 100,
            'reason' => 'Stale browser tab reversal',
            'editor_version' => $staleVersion,
        ])->assertSessionHasErrors('editor_version');

        $flag = ProductFeatureFlag::query()->where('key', 'checkout')->firstOrFail();
        self::assertFalse((bool) $flag->enabled);
        self::assertSame(0, (int) $flag->rollout_percentage);
        self::assertSame('Provider incident INC-301', $flag->reason);
    }

    public function test_mutating_product_routes_are_bound_to_their_server_kill_switches(): void
    {
        foreach ([
            ['POST', '/api/v1/payment/initiate', 'product.feature:checkout'],
            ['POST', '/api/v1/lessons/7/playback-manifest', 'product.feature:playback'],
            ['POST', '/api/v1/projects/7/submissions', 'product.feature:project_uploads'],
            ['POST', '/api/v1/courses/7/chat', 'product.feature:ai_chat'],
        ] as [$method, $uri, $middleware]) {
            $route = Route::getRoutes()->match(Request::create($uri, $method));
            self::assertContains($middleware, $route->gatherMiddleware(), $uri);
        }
    }
}
