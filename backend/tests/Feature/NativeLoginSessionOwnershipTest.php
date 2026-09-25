<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\SocialAccount;
use App\Models\UserDeviceToken;
use App\Services\DeviceLoginService;
use App\Services\NativeLoginSessionService;
use App\Services\SocialOAuthAttemptService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\API\ApiTestCase;

/** Real commit boundaries over the isolated authentication fixture schema. */
final class NativeLoginSessionOwnershipTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('settings', static fn (Blueprint $table) => $table->string('device_login_policy')->nullable());
        DB::table('settings')->update(['device_login_policy' => DeviceLoginService::POLICY_SINGLE]);
        SocialAccount::query()->create(['user_id' => $this->user->id, 'provider' => 'google',
            'provider_user_id' => 'verified-subject', 'last_verified_at' => now()]);
    }

    public function test_session_owner_requires_the_callers_transaction(): void
    {
        try {
            $this->issue((string) Str::uuid());
            self::fail('Bearer issuance requires the completion transaction.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('login-completion transaction', $error->getMessage());
        }
        self::assertSame(0, DB::table('api_tokens')->count());
    }

    public function test_new_session_uses_verified_provider_and_owns_device_rotation_atomically(): void
    {
        $oldDevice = (string) Str::uuid();
        $newDevice = (string) Str::uuid();
        $this->user->forceFill(['social_provider' => 'facebook', 'social_id' => 'original-provider',
            'locked_device_id' => $oldDevice])->save();
        $this->user->generateApiToken('facebook', 'original-provider', ['device_id' => $oldDevice]);
        $this->pushRegistration($oldDevice);
        $result = DB::transaction(fn () => $this->issue($newDevice));
        self::assertTrue($result['access']['allowed']);
        $token = ApiToken::query()->sole();
        self::assertSame(hash('sha256', $result['api_token']), $token->token);
        self::assertSame('google', $token->auth_provider);
        self::assertSame('verified-subject', $token->auth_provider_user_id);
        self::assertSame($newDevice, $token->device_id);
        self::assertSame('android', $token->platform);
        self::assertSame('phone', $token->device_class);
        self::assertSame($newDevice, $this->user->fresh()->locked_device_id);
        self::assertSame(0, UserDeviceToken::query()->count());
        self::assertSame('facebook', $this->user->fresh()->social_provider);
    }

    public function test_failed_token_write_restores_previous_device_tokens_and_push_registration(): void
    {
        $oldDevice = (string) Str::uuid();
        $this->user->forceFill(['locked_device_id' => $oldDevice])->save();
        $oldToken = $this->user->generateApiToken(session: ['device_id' => $oldDevice]);
        $this->pushRegistration($oldDevice);
        ApiToken::creating(static fn () => throw new \RuntimeException('token write failed'));
        try {
            DB::transaction(fn () => $this->issue((string) Str::uuid()));
            self::fail('The failed token write must roll back device rotation.');
        } catch (\RuntimeException $error) {
            self::assertSame('token write failed', $error->getMessage());
        }
        self::assertSame(hash('sha256', $oldToken), ApiToken::query()->sole()->token);
        self::assertNull(ApiToken::query()->sole()->revoked_at);
        self::assertSame($oldDevice, $this->user->fresh()->locked_device_id);
        self::assertSame($oldDevice, UserDeviceToken::query()->sole()->device_id);
    }

    public function test_disabled_or_deleted_user_is_rechecked_under_the_session_lock(): void
    {
        $this->user->forceFill(['active' => false])->save();
        $result = DB::transaction(fn () => $this->issue((string) Str::uuid()));
        self::assertSame('account_disabled', $result['access']['code']);
        self::assertNull($result['api_token']);
        $this->user->forceFill(['active' => true])->save();
        $this->user->deleteQuietly();
        $result = DB::transaction(fn () => $this->issue((string) Str::uuid()));
        self::assertSame('account_disabled', $result['access']['code']);
        self::assertNull($result['api_token']);
        self::assertSame(0, DB::table('api_tokens')->count());
    }

    public function test_permanent_device_denial_does_not_mutate_the_existing_session(): void
    {
        DB::table('settings')->update(['device_login_policy' => DeviceLoginService::POLICY_SINGLE_PERMANENT]);
        $device = (string) Str::uuid();
        $this->user->forceFill(['locked_device_id' => $device])->save();
        $oldToken = $this->user->generateApiToken(session: ['device_id' => $device]);
        $result = DB::transaction(fn () => $this->issue((string) Str::uuid()));
        self::assertFalse($result['access']['allowed']);
        self::assertNull($result['api_token']);
        self::assertSame(hash('sha256', $oldToken), ApiToken::query()->sole()->token);
        self::assertSame($device, $this->user->fresh()->locked_device_id);
    }

    public function test_reclaimed_oauth_attempt_cannot_issue_a_bearer_from_the_stale_request(): void
    {
        $attempts = app(SocialOAuthAttemptService::class);
        $code = Str::random(64);
        $attempt = $attempts->begin(Str::random(64), 'google', 'rokn://auth', Str::random(43));
        $attempts->issueCompletion($attempt, $code, Crypt::encryptString('fixture-token'));
        $first = $attempts->claimCompletion($code);
        DB::table('social_oauth_attempts')->where('id', $attempt->id)
            ->update(['completion_processing_at' => now()->subMinutes(3)]);
        $second = $attempts->claimCompletion($code);
        self::assertNotSame($first->completion_claim_id, $second->completion_claim_id);
        $callback = fn () => $this->issue((string) Str::uuid());
        self::assertNull($attempts->whileCompletionClaimIsOwned($attempt->id, $first->completion_claim_id, $callback));
        self::assertSame(0, DB::table('api_tokens')->count());
        $result = $attempts->whileCompletionClaimIsOwned($attempt->id, $second->completion_claim_id, $callback);
        self::assertTrue($result['access']['allowed']);
        self::assertSame(1, DB::table('api_tokens')->count());
    }

    private function issue(string $deviceId): array
    {
        return app(NativeLoginSessionService::class)->issueWithinTransaction(
            userId: (int) $this->user->id,
            provider: 'google',
            providerUserId: 'verified-subject',
            metadata: ['device_id' => $deviceId, 'platform' => 'android', 'device_class' => 'phone']
        );
    }

    private function pushRegistration(string $deviceId): void
    {
        UserDeviceToken::query()->create(['user_id' => $this->user->id,
            'device_token' => 'old-phone-push', 'device_type' => 'android', 'device_os' => 'android',
            'device_id' => $deviceId]);
    }
}
