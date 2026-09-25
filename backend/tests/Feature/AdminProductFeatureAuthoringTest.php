<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProductOperationsController;
use App\Models\ProductFeatureFlag;
use App\Services\AdminProductFeatureAuthoringService;
use App\Services\ProductFeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminProductFeatureAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_writer_owns_locked_version_checks_without_an_http_request(): void
    {
        $this->app->bind(ProductOperationsController::class, static function (): never {
            throw new \LogicException('Feature authoring must not resolve a controller.');
        });
        $reader = app(ProductFeatureFlagService::class);
        $writer = app(AdminProductFeatureAuthoringService::class);
        $input = [
            'enabled' => false, 'rollout_percentage' => 0, 'reason' => '  Provider investigation  ',
            'editor_version' => $reader->editorVersion('checkout', null),
        ];
        $writer->update('checkout', $input, 'operations@rokn.test');
        $flag = ProductFeatureFlag::query()->where('key', 'checkout')->sole();
        self::assertFalse($flag->enabled);
        self::assertSame('operations@rokn.test', $flag->owner);
        self::assertSame('Provider investigation', $flag->reason);
        try {
            $writer->update('checkout', [...$input, 'enabled' => true], 'stale@rokn.test');
            self::fail('A stale feature editor must not reverse a newer decision.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        self::assertFalse($flag->fresh()->enabled);
        self::assertSame('operations@rokn.test', $flag->fresh()->owner);
    }

    public function test_expired_input_is_rejected_before_a_flag_is_created(): void
    {
        try {
            app(AdminProductFeatureAuthoringService::class)->update('checkout', [
                'enabled' => true, 'rollout_percentage' => 100, 'reason' => 'Expired change request',
                'expires_at' => '2000-01-01 00:00:00',
                'editor_version' => app(ProductFeatureFlagService::class)->editorVersion('checkout', null),
            ], 'operations@rokn.test');
            self::fail('Expired feature settings must not be saved.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('expires_at', $error->errors());
        }
        self::assertFalse(ProductFeatureFlag::query()->where('key', 'checkout')->exists());
    }
}
