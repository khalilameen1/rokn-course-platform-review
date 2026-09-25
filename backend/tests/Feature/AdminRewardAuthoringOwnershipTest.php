<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\CoinEarningMethodController;
use App\Models\CoinEarningMethod;
use App\Models\RewardRule;
use App\Models\Setting;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminRewardAuthoringService;
use App\Support\RewardConfigurationVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminRewardAuthoringOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        foreach ([CoinEarningMethodController::class, AdminAuthoringCreateIntentService::class] as $httpOwner) {
            $this->app->bind($httpOwner, static function (): never {
                throw new \LogicException('Reward configuration must not resolve HTTP owners.');
            });
        }
        Setting::query()->firstOrCreate([])->forceFill(['reward_balance_cap' => 1200])->save();
        RewardRule::query()->where('event_key', 'course_completed')->delete();
    }

    public static function kinds(): array
    {
        return ['task' => ['method'], 'rule' => ['rule']];
    }

    #[DataProvider('kinds')]
    public function test_creation_completes_the_receipt_inside_the_configuration_transaction(string $kind): void
    {
        $completedId = null;
        $saved = $this->create($kind, static function ($model) use (&$completedId): void {
            self::assertSame(1, DB::transactionLevel());
            self::assertTrue($model->exists);
            self::assertSame($model->id, $model->fresh()->id);
            $completedId = $model->id;
        });
        self::assertSame($saved->id, $completedId);
        self::assertSame(0, DB::transactionLevel());
        self::assertSame(50, (int) $saved->fresh()->coins_amount);
        Http::assertNothingSent();
    }

    #[DataProvider('kinds')]
    public function test_receipt_failure_rolls_back_the_configuration_and_can_be_retried(string $kind): void
    {
        $modelClass = $kind === 'method' ? CoinEarningMethod::class : RewardRule::class;
        $beforeCount = $modelClass::query()->count();
        try {
            $this->create($kind, static function (): never {
                self::assertSame(1, DB::transactionLevel());
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'test-receipt']);
                throw new \RuntimeException('receipt unavailable');
            });
            self::fail('A failed receipt must roll back the authored configuration.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt unavailable', $error->getMessage());
        }
        self::assertSame($beforeCount, $modelClass::query()->count());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'test-receipt')->exists());
        self::assertSame(0, DB::transactionLevel());
        $saved = $this->create($kind, static function (): void {});
        self::assertSame($beforeCount + 1, $modelClass::query()->count());
        self::assertNotNull($saved->fresh());
    }

    #[DataProvider('kinds')]
    public function test_stale_models_cannot_overwrite_or_delete_a_more_recent_configuration(string $kind): void
    {
        $saved = $this->create($kind, static function (): void {});
        $version = $kind === 'method'
            ? RewardConfigurationVersion::method($saved)
            : RewardConfigurationVersion::rule($saved);
        $saved->fresh()->update(['title_ar' => 'تعديل أحدث']);
        $writer = app(AdminRewardAuthoringService::class);
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $kind === 'method'
                        ? $writer->updateMethod($saved, $this->payload($kind), $version)
                        : $writer->updateRule($saved, $this->payload($kind), $version);
                } else {
                    $kind === 'method'
                        ? $writer->deleteMethod($saved, $version)
                        : $writer->deleteRule($saved, $version);
                }
                self::fail('A stale editor must be rejected against the locked persisted row.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('editor_version', $error->errors());
            }
        }
        self::assertSame('تعديل أحدث', $saved->fresh()->title_ar);
        $fresh = $saved->fresh();
        if ($kind === 'method') {
            $writer->deleteMethod($saved, RewardConfigurationVersion::method($fresh));
        } else {
            $writer->deleteRule($saved, RewardConfigurationVersion::rule($fresh));
        }
        if ($kind === 'method') {
            self::assertNotNull($saved->fresh()->deleted_at);
            self::assertFalse(CoinEarningMethod::query()->whereKey($saved->id)->exists());
        } else {
            self::assertNull($saved->fresh());
        }
    }

    #[DataProvider('kinds')]
    public function test_configuration_caps_are_enforced_without_a_controller_before_receipt_completion(string $kind): void
    {
        Setting::query()->firstOrFail()->forceFill(['reward_balance_cap' => 40])->save();
        $called = false;
        try {
            $this->create($kind, static function () use (&$called): void { $called = true; });
            self::fail('Configuration must not promise an unpayable reward.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('coins_amount', $error->errors());
        }
        self::assertFalse($called);
        self::assertSame(0, DB::transactionLevel());
    }

    public function test_settings_writer_rejects_stale_forms_and_preserves_unrelated_fields(): void
    {
        $settings = Setting::query()->firstOrFail();
        $settings->forceFill(['support_whatsapp_url' => 'https://wa.me/201000000000'])->save();
        $stale = RewardConfigurationVersion::settings($settings);
        $writer = app(AdminRewardAuthoringService::class);
        $writer->updateSettings(['rewards_help_ar' => 'خصمك يظهر قبل الدفع'], $stale);
        self::assertSame('خصمك يظهر قبل الدفع', $settings->fresh()->rewards_help_ar);
        self::assertSame('https://wa.me/201000000000', $settings->fresh()->support_whatsapp_url);
        try {
            $writer->updateSettings(['rewards_help_ar' => 'قديم'], $stale);
            self::fail('A stale settings form must not replace the committed copy.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        self::assertSame('خصمك يظهر قبل الدفع', $settings->fresh()->rewards_help_ar);
    }

    public function test_editor_fingerprints_are_read_only_and_keep_the_existing_wire_format(): void
    {
        $settings = new Setting();
        $task = new CoinEarningMethod($this->payload('method'));
        $rule = new RewardRule($this->payload('rule'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $versions = [RewardConfigurationVersion::settings($settings),
            RewardConfigurationVersion::method($task), RewardConfigurationVersion::rule($rule)];
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        foreach ($versions as $version) self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $version);
        self::assertSame(hash('sha256', json_encode([
            'course_completed', 'مكافأة اختبار', 'Test reward',
            50, 1, null, 500, true, 100,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $versions[2]);
        $rule->title_ar = 'نص آخر';
        self::assertNotSame($versions[2], RewardConfigurationVersion::rule($rule));
        self::assertFalse($rule->exists);
    }

    private function create(string $kind, \Closure $complete): CoinEarningMethod|RewardRule
    {
        $writer = app(AdminRewardAuthoringService::class);
        return $kind === 'method'
            ? $writer->createMethod($this->payload($kind), $complete)
            : $writer->createRule($this->payload($kind), $complete);
    }

    private function payload(string $kind): array
    {
        $common = ['title_ar' => 'مكافأة اختبار', 'title_en' => 'Test reward',
            'coins_amount' => 50, 'is_active' => true, 'sort_order' => 100];
        return $kind === 'method'
            ? $common + ['action_key' => 'internal-test-task', 'requires_external_visit' => false,
                'verification_delay_seconds' => 0, 'is_repeatable' => false]
            : $common + ['event_key' => 'course_completed', 'interval_count' => 1,
                'daily_cap' => null, 'rolling_30_day_cap' => 500];
    }
}
