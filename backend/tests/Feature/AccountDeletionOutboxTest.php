<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteAccountFile;
use App\Jobs\CleanupDeletedAccountPortfolioMedia;
use App\Exceptions\SocialProviderUnavailableException;
use App\Models\AccountFileDeletion;
use App\Models\User;
use App\Models\SocialAccount;
use App\Services\AccountDeletionService;
use App\Services\AccountAiDataErasureService;
use App\Services\AccountPortfolioErasureService;
use App\Services\BunnyService;
use App\Services\StoredFileReferenceService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

final class AccountDeletionOutboxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('profile_image')->nullable();
            $table->boolean('active')->default(true);
            $table->string('gender')->nullable();
            $table->string('social_provider')->nullable();
            $table->string('social_id')->nullable();
            $table->string('ai_consent_version')->nullable();
            $table->timestamp('ai_consent_accepted_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('account_file_deletions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('disk', 64);
            $table->string('path_hash', 64);
            $table->text('path')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error', 190)->nullable();
            $table->timestamps();
            $table->unique(['disk', 'path_hash']);
        });
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->text('apple_refresh_token')->nullable();
            $table->string('apple_client_id')->nullable();
            $table->timestamps();
        });
        Schema::create('social_identity_guards', function (Blueprint $table): void {
            $table->char('identity_hash', 64)->primary();
            $table->timestamp('deletion_started_at')->nullable();
            $table->timestamps();
        });
        Schema::create('deleted_social_reward_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->char('identity_hmac', 64);
            $table->json('consumed_reward_keys');
            $table->timestamps();
            $table->unique(['provider', 'identity_hmac']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('portfolio_media');
        Schema::dropIfExists('portfolio_items');
        Schema::dropIfExists('deleted_social_reward_tombstones');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('social_identity_guards');
        Schema::dropIfExists('account_file_deletions');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_file_reference_is_committed_to_durable_outbox_before_account_path_is_cleared(): void
    {
        Storage::fake('public');
        Queue::fake();
        Storage::disk('public')->put('profiles/private.jpg', 'personal bytes');
        $user = User::query()->create([
            'name' => 'Delete Me',
            'email' => 'delete@example.test',
            'phone' => '01000000000',
            'password' => bcrypt('password'),
            'profile_image' => 'profiles/private.jpg',
            'active' => true,
            'gender' => 'other',
        ]);
        $user->forceFill(['ai_consent_version' => 'test-v1', 'ai_consent_accepted_at' => now()])->save();

        $result = app(AccountDeletionService::class)->delete($user);

        self::assertTrue($result['local_cleanup_pending']);
        Storage::disk('public')->assertExists('profiles/private.jpg');
        $outbox = AccountFileDeletion::query()->firstOrFail();
        self::assertSame('profiles/private.jpg', $outbox->path);
        self::assertNotSame('profiles/private.jpg', $outbox->getRawOriginal('path'));
        self::assertSame(AccountFileDeletion::STATUS_PENDING, $outbox->status);
        self::assertNotNull(User::withTrashed()->findOrFail($user->id)->deleted_at);
        self::assertNull(User::withTrashed()->findOrFail($user->id)->profile_image);
        self::assertNull(User::withTrashed()->findOrFail($user->id)->ai_consent_version);
        self::assertNull(User::withTrashed()->findOrFail($user->id)->ai_consent_accepted_at);
        Queue::assertPushed(DeleteAccountFile::class, fn ($job) => $job->deletionId === $outbox->id);

        app(DeleteAccountFile::class, ['deletionId' => $outbox->id])
            ->handle(app(StoredFileReferenceService::class));
        Storage::disk('public')->assertMissing('profiles/private.jpg');
        $outbox->refresh();
        self::assertSame(AccountFileDeletion::STATUS_COMPLETED, $outbox->status);
        self::assertNull($outbox->path);

        // The worker is safely idempotent after completion.
        app(DeleteAccountFile::class, ['deletionId' => $outbox->id])
            ->handle(app(StoredFileReferenceService::class));
        self::assertSame(AccountFileDeletion::STATUS_COMPLETED, $outbox->fresh()->status);
    }

    public function test_apple_revocation_failure_preserves_account_and_can_be_retried_before_erasure(): void
    {
        Queue::fake();
        $this->configureAppleTestKey();
        $user = User::query()->create([
            'name' => 'Apple Learner', 'email' => 'apple@example.test',
            'password' => bcrypt('password'), 'active' => true, 'gender' => 'other',
        ]);
        $account = SocialAccount::query()->create([
            'user_id' => $user->id, 'provider' => 'apple', 'provider_user_id' => 'apple-user',
            'apple_client_id' => 'com.rokn', 'apple_refresh_token' => 'encrypted-delete-token',
        ]);
        $stored = $account->getRawOriginal('apple_refresh_token');
        self::assertNotSame('encrypted-delete-token', $stored);
        Http::fake(['https://appleid.apple.com/auth/revoke' => Http::sequence()->push([], 503)->push([], 200)]);
        try {
            app(AccountDeletionService::class)->delete($user);
            self::fail('An Apple outage must not erase the account or its grant.');
        } catch (SocialProviderUnavailableException $exception) {
            self::assertSame('Apple authorization revocation is unavailable.', $exception->getMessage());
        }
        self::assertNull($user->fresh()->deleted_at);
        self::assertSame('Apple Learner', $user->fresh()->name);
        self::assertSame($stored, $account->fresh()->getRawOriginal('apple_refresh_token'));
        self::assertSame(0, AccountFileDeletion::query()->count());

        app(AccountDeletionService::class)->delete($user);
        self::assertNotNull(User::withTrashed()->findOrFail($user->id)->deleted_at);
        self::assertSame(0, SocialAccount::query()->where('user_id', $user->id)->count());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://appleid.apple.com/auth/revoke'
            && $request['token_type_hint'] === 'refresh_token'
            && $request['token'] === 'encrypted-delete-token'
            && $request['client_id'] === 'com.rokn');
    }

    public function test_legacy_apple_account_without_retained_tokens_is_still_deletable(): void
    {
        Queue::fake();
        Http::fake();
        $user = User::query()->create([
            'name' => 'Legacy Apple', 'email' => 'legacy-apple@example.test',
            'password' => bcrypt('password'), 'active' => true, 'gender' => 'other',
        ]);
        SocialAccount::query()->create([
            'user_id' => $user->id, 'provider' => 'apple', 'provider_user_id' => 'legacy-user',
        ]);
        app(AccountDeletionService::class)->delete($user);
        self::assertNotNull(User::withTrashed()->findOrFail($user->id)->deleted_at);
        Http::assertNothingSent();
    }

    public function test_local_rollback_after_apple_revocation_can_repeat_the_idempotent_revoke(): void
    {
        Queue::fake();
        Storage::fake('public');
        Storage::disk('public')->put('profiles/rollback.jpg', 'private bytes');
        $this->configureAppleTestKey();
        $user = User::query()->create([
            'name' => 'Rollback Learner', 'email' => 'rollback@example.test',
            'password' => bcrypt('password'), 'active' => true, 'gender' => 'other',
            'profile_image' => 'profiles/rollback.jpg',
        ]);
        $account = SocialAccount::query()->create([
            'user_id' => $user->id, 'provider' => 'apple', 'provider_user_id' => 'rollback-user',
            'apple_client_id' => 'com.rokn', 'apple_refresh_token' => 'rollback-refresh-token',
        ]);
        Http::fake(['https://appleid.apple.com/auth/revoke' => Http::response('', 200)]);
        $failOnce = true;
        User::saving(function (User $saving) use (&$failOnce): void {
            if ($failOnce && $saving->name === 'حساب محذوف') {
                $failOnce = false;
                throw new \RuntimeException('Simulated local transaction failure.');
            }
        });
        try {
            app(AccountDeletionService::class)->delete($user);
            self::fail('The simulated transaction must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated local transaction failure.', $exception->getMessage());
        }
        self::assertNotNull($account->fresh());
        self::assertNull($user->fresh()->deleted_at);
        self::assertSame('profiles/rollback.jpg', $user->fresh()->profile_image);
        self::assertSame(0, AccountFileDeletion::query()->count());
        Storage::disk('public')->assertExists('profiles/rollback.jpg');
        Queue::assertNothingPushed();
        app(AccountDeletionService::class)->delete($user);
        self::assertNotNull(User::withTrashed()->findOrFail($user->id)->deleted_at);
        self::assertSame('profiles/rollback.jpg', AccountFileDeletion::query()->sole()->path);
        Queue::assertPushed(DeleteAccountFile::class, 1);
        Http::assertSentCount(2);
    }

    public function test_data_erasure_owners_reject_calls_outside_the_account_transaction(): void
    {
        foreach ([AccountAiDataErasureService::class, AccountPortfolioErasureService::class] as $service) {
            try {
                app($service)->eraseWithinDeletion(7);
                self::fail('Account data erasure must be transactional.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('account-deletion transaction', $error->getMessage());
            }
        }
        self::assertSame(0, DB::transactionLevel());
    }

    public function test_account_erases_portfolio_content_but_keeps_private_remote_references_until_cleanup(): void
    {
        Queue::fake();
        [$user, $itemId, $mediaId, $emptyItemId, $otherItemId] = $this->portfolioFixture();
        $result = app(AccountDeletionService::class)->delete($user);
        self::assertTrue($result['remote_portfolio_cleanup_pending']);
        self::assertFalse($result['local_cleanup_pending']);
        self::assertFalse(DB::table('portfolio_items')->where('id', $emptyItemId)->exists());
        $item = DB::table('portfolio_items')->find($itemId);
        foreach (['title', 'description', 'slug', 'role', 'tools', 'external_url'] as $field) {
            self::assertNull($item->$field);
        }
        self::assertFalse((bool) $item->is_public);
        self::assertFalse((bool) $item->is_featured);
        $media = DB::table('portfolio_media')->find($mediaId);
        self::assertNull($media->caption);
        self::assertSame('portfolio/private.jpg', $media->file_path);
        self::assertSame('Other learner', DB::table('portfolio_items')->where('id', $otherItemId)->value('title'));
        Queue::assertPushed(CleanupDeletedAccountPortfolioMedia::class, 1);
    }

    public function test_portfolio_erasure_and_its_callback_roll_back_with_account_identity(): void
    {
        Queue::fake();
        [$user, $itemId, $mediaId, $emptyItemId] = $this->portfolioFixture();
        $failOnce = true;
        User::saving(static function (User $saving) use (&$failOnce): void {
            if ($failOnce && $saving->name === 'حساب محذوف') {
                $failOnce = false;
                throw new \RuntimeException('identity write failed');
            }
        });
        try {
            app(AccountDeletionService::class)->delete($user);
            self::fail('The simulated identity failure must roll back portfolio erasure.');
        } catch (\RuntimeException $error) {
            self::assertSame('identity write failed', $error->getMessage());
        }
        self::assertSame('Private work', DB::table('portfolio_items')->where('id', $itemId)->value('title'));
        self::assertSame('Private caption', DB::table('portfolio_media')->where('id', $mediaId)->value('caption'));
        self::assertTrue(DB::table('portfolio_items')->where('id', $emptyItemId)->exists());
        self::assertNull($user->fresh()->deleted_at);
        Queue::assertNothingPushed();
        app(AccountDeletionService::class)->delete($user);
        Queue::assertPushed(CleanupDeletedAccountPortfolioMedia::class, 1);
    }

    public function test_portfolio_queue_failure_preserves_private_recovery_rows_for_the_scheduler(): void
    {
        Queue::fake();
        [$user, $itemId, $mediaId] = $this->portfolioFixture();
        $original = app(Dispatcher::class);
        $failed = Mockery::mock(Dispatcher::class);
        $failed->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('broker unavailable'));
        $this->app->instance(Dispatcher::class, $failed);
        $result = app(AccountDeletionService::class)->delete($user);
        self::assertTrue($result['remote_portfolio_cleanup_pending']);
        self::assertNotNull(User::withTrashed()->findOrFail($user->id)->deleted_at);
        self::assertFalse((bool) DB::table('portfolio_items')->where('id', $itemId)->value('is_public'));
        self::assertSame('portfolio/private.jpg', DB::table('portfolio_media')->where('id', $mediaId)->value('file_path'));
        $this->app->instance(Dispatcher::class, $original);
        $this->artisan('privacy:cleanup-portfolio-media')->assertExitCode(0);
        Queue::assertPushed(CleanupDeletedAccountPortfolioMedia::class,
            fn ($job) => $job->uniqueId() === 'deleted-account-portfolio:'.$user->id);
    }

    public function test_failed_remote_cleanup_can_retry_without_losing_the_original_file_reference(): void
    {
        Queue::fake();
        [$user, $itemId, $mediaId] = $this->portfolioFixture();
        app(AccountDeletionService::class)->delete($user);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('deleteFileFromStorage')->twice()->with('portfolio/private.jpg')->andReturn(false, true);
        $job = new CleanupDeletedAccountPortfolioMedia($user->id);
        try {
            $job->handle($bunny, app(StoredFileReferenceService::class));
            self::fail('Remote failure must preserve retryable references.');
        } catch (\RuntimeException $error) {
            self::assertSame('Bunny portfolio media cleanup is temporarily unavailable.', $error->getMessage());
        }
        self::assertTrue(DB::table('portfolio_items')->where('id', $itemId)->exists());
        self::assertSame('portfolio/private.jpg', DB::table('portfolio_media')->where('id', $mediaId)->value('file_path'));
        $job->handle($bunny, app(StoredFileReferenceService::class));
        self::assertFalse(DB::table('portfolio_media')->where('id', $mediaId)->exists());
        self::assertFalse(DB::table('portfolio_items')->where('id', $itemId)->exists());
        // Replayed delivery is harmless after the last reference was removed.
        $job->handle($bunny, app(StoredFileReferenceService::class));
    }

    public function test_empty_portfolio_is_removed_without_scheduling_remote_work(): void
    {
        Queue::fake();
        [$user, $itemId, $mediaId, $emptyItemId] = $this->portfolioFixture();
        DB::table('portfolio_media')->where('id', $mediaId)->delete();
        $result = app(AccountDeletionService::class)->delete($user);
        self::assertFalse($result['remote_portfolio_cleanup_pending']);
        self::assertSame(0, DB::table('portfolio_items')->whereIn('id', [$itemId, $emptyItemId])->count());
        Queue::assertNothingPushed();
    }

    /** @return array{User, int, int, int, int} */
    private function portfolioFixture(): array
    {
        Schema::create('portfolio_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            foreach (['title', 'description', 'slug', 'role', 'tools', 'external_url'] as $field) {
                $table->text($field)->nullable();
            }
            $table->boolean('is_public')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });
        Schema::create('portfolio_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_item_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_type');
            $table->string('thumbnail_path')->nullable();
            $table->text('caption')->nullable();
            $table->timestamps();
        });
        $user = User::query()->create(['name' => 'Portfolio learner', 'email' => 'portfolio@example.test',
            'password' => bcrypt('password'), 'active' => true, 'gender' => 'other']);
        $itemId = DB::table('portfolio_items')->insertGetId([
            'user_id' => $user->id, 'title' => 'Private work', 'description' => 'Private description',
            'slug' => 'private-work', 'role' => 'Designer', 'tools' => '["design"]',
            'external_url' => 'https://example.test/private', 'is_public' => true, 'is_featured' => true,
        ]);
        $mediaId = DB::table('portfolio_media')->insertGetId([
            'portfolio_item_id' => $itemId, 'file_path' => 'portfolio/private.jpg',
            'file_type' => 'image', 'caption' => 'Private caption',
        ]);
        $emptyItemId = DB::table('portfolio_items')->insertGetId(['user_id' => $user->id, 'title' => 'Empty work']);
        $otherItemId = DB::table('portfolio_items')->insertGetId(['user_id' => $user->id + 1, 'title' => 'Other learner']);
        return [$user, $itemId, $mediaId, $emptyItemId, $otherItemId];
    }

    private function configureAppleTestKey(): void
    {
        $configurationPath = storage_path('openssl-test.cnf');
        file_put_contents($configurationPath, "[ req ]\ndistinguished_name = req_distinguished_name\n[ req_distinguished_name ]\n");
        $key = openssl_pkey_new([
            'config' => $configurationPath,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, $pem, null, ['config' => $configurationPath]));
        $keyPath = storage_path('apple-test.p8');
        file_put_contents($keyPath, $pem);
        config([
            'services.apple.client_id' => 'com.rokn', 'services.apple.team_id' => 'TESTTEAM01',
            'services.apple.key_id' => 'TESTKEY001', 'services.apple.key_file' => $keyPath,
        ]);
    }
}
