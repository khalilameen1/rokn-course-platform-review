<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\VerifiedSocialIdentity;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAccountBindingService;
use App\Services\SocialAuthProviderRegistry;
use App\Services\SocialIdentityGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SocialAccountBindingOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->freezeTime();
        $this->app->bind(SocialAuthProviderRegistry::class,
            static fn () => throw new \LogicException('Binding must not reverify provider credentials.'));
    }

    public function test_first_login_creates_one_client_identity_without_tokens_or_reward_side_effects(): void
    {
        User::creating(static function (User $user): void {
            self::assertSame('client', $user->role);
            self::assertTrue((bool) $user->active);
            self::assertNotNull($user->email_verified_at);
        });
        $identity = $this->identity();
        $user = app(SocialAccountBindingService::class)->bind($identity, now(), 'en');
        $replay = app(SocialAccountBindingService::class)->bind($identity, now());
        self::assertSame($user->id, $replay->id);
        self::assertSame(1, User::query()->count());
        self::assertSame(1, SocialAccount::query()->count());
        self::assertSame('client', $replay->role);
        self::assertTrue((bool) $replay->active);
        self::assertNotNull($replay->email_verified_at);
        self::assertSame('en', $replay->preferred_locale);
        self::assertFalse((bool) $replay->notifications_status);
        self::assertNotNull($replay->terms_accepted_at);
        self::assertNotEmpty($replay->portfolio_slug);
        self::assertSame(0, DB::table('api_tokens')->count());
        self::assertSame(0, DB::table('wallet_transactions')->count());
        Http::assertNothingSent();
    }

    public function test_verified_email_can_link_a_second_provider_without_replacing_the_chosen_profile(): void
    {
        $service = app(SocialAccountBindingService::class);
        $first = $service->bind($this->identity(), now());
        $first->forceFill(['name' => 'Chosen learner name', 'profile_image' => 'profiles/chosen.jpg'])->save();
        $linked = $service->bind($this->identity('facebook', 'facebook-id'), now());
        self::assertSame($first->id, $linked->id);
        self::assertSame('Chosen learner name', $linked->getRawOriginal('name'));
        self::assertSame('profiles/chosen.jpg', $linked->getRawOriginal('profile_image'));
        self::assertSame('google', $linked->social_provider);
        self::assertSame(2, SocialAccount::query()->where('user_id', $first->id)->count());
    }

    #[DataProvider('emailOwners')]
    public function test_email_linking_rejects_ineligible_owners(array $attributes, string $reason): void
    {
        $user = User::query()->forceCreate(array_replace([
            'name' => 'Existing owner', 'email' => 'learner@example.test', 'password' => 'unused',
            'role' => 'client', 'active' => true, 'email_verified_at' => now(),
        ], $attributes));
        $before = $user->fresh()->getAttributes();
        try {
            app(SocialAccountBindingService::class)->bind($this->identity(), now());
            self::fail('Ineligible email ownership must not become a provider link.');
        } catch (\DomainException $error) {
            self::assertSame($reason, $error->getMessage());
        }
        self::assertSame($before, $user->fresh()->getAttributes());
        self::assertSame(0, SocialAccount::query()->count());
        self::assertSame(1, User::withTrashed()->count());
    }

    public static function emailOwners(): array
    {
        return [
            'typed but unverified email' => [['email_verified_at' => null], 'social_account_email_unverified'],
            'administrator' => [['role' => 'admin'], 'social_account_email_reserved'],
            'inactive learner' => [['active' => false], 'social_account_disabled'],
        ];
    }

    public function test_unverified_provider_email_never_claims_an_existing_account(): void
    {
        $existing = User::query()->forceCreate(['name' => 'Existing', 'email' => 'learner@example.test',
            'password' => 'unused', 'role' => 'client', 'active' => true, 'email_verified_at' => now()]);
        $new = app(SocialAccountBindingService::class)->bind(
            $this->identity(verified: false), now()
        );
        self::assertNotSame($existing->id, $new->id);
        self::assertSame('google-'.hash('sha256', 'google-id').'@accounts.rokn.app', $new->email);
        self::assertNull($new->email_verified_at);
        self::assertSame($new->id, SocialAccount::query()->sole()->user_id);
    }

    public function test_a_second_identity_for_the_same_provider_does_not_replace_the_link(): void
    {
        $service = app(SocialAccountBindingService::class);
        $user = $service->bind($this->identity(), now());
        try {
            $service->bind($this->identity(providerId: 'another-google-id'), now());
            self::fail('A verified email must not replace an existing same-provider identity.');
        } catch (\DomainException $error) {
            self::assertSame('social_account_conflict', $error->getMessage());
        }
        self::assertSame('google-id', SocialAccount::query()->sole()->provider_user_id);
        self::assertSame('google-id', $user->fresh()->social_id);
    }

    public function test_link_failure_rolls_back_new_account_and_portfolio_identity_together(): void
    {
        SocialAccount::creating(static fn () => throw new \RuntimeException('link write failed'));
        try {
            app(SocialAccountBindingService::class)->bind($this->identity(), now());
            self::fail('A failed link must not leave an orphan account.');
        } catch (\RuntimeException $error) {
            self::assertSame('link write failed', $error->getMessage());
        }
        self::assertSame(0, User::query()->count());
        self::assertSame(0, SocialAccount::query()->count());
        self::assertSame(0, DB::table('social_identity_guards')->count());
    }

    public function test_stale_pre_deletion_attempt_cannot_rebind_the_identity(): void
    {
        $service = app(SocialAccountBindingService::class);
        $user = $service->bind($this->identity(), now()->subMinute());
        app(SocialIdentityGuardService::class)->markDeletionStarted($user->id);
        try {
            $service->bind($this->identity(), now()->subSecond());
            self::fail('A pre-deletion attempt must not mutate the account.');
        } catch (\DomainException $error) {
            self::assertSame('social_login_predates_account_deletion', $error->getMessage());
        }
        self::assertSame(1, User::query()->count());
        self::assertSame(1, SocialAccount::query()->count());
    }

    public function test_apple_label_is_not_identity_proof_and_the_grant_remains_encrypted(): void
    {
        $identity = VerifiedSocialIdentity::fromProvider('apple', [
            'id' => 'apple-id', 'name' => null, 'email' => null, 'email_verified' => false,
            'apple_grant' => ['apple_refresh_token' => 'test-private-grant', 'apple_client_id' => 'com.rokn'],
        ], 'اسم الطالب');
        $user = app(SocialAccountBindingService::class)->bind($identity, now());
        self::assertSame('اسم الطالب', $user->getRawOriginal('name'));
        self::assertNull($user->email_verified_at);
        $account = SocialAccount::query()->sole();
        self::assertSame('test-private-grant', $account->apple_refresh_token);
        self::assertNotSame('test-private-grant', $account->getRawOriginal('apple_refresh_token'));
        self::assertArrayNotHasKey('apple_refresh_token', $account->toArray());
    }

    public function test_display_only_input_cannot_change_non_apple_provider_identity(): void
    {
        $identity = VerifiedSocialIdentity::fromProvider('google', [
            'id' => 'verified-id', 'name' => 'Verified name',
            'email' => 'invalid-email', 'email_verified' => true,
            'apple_grant' => ['apple_refresh_token' => 'not-a-google-grant'],
        ], 'Untrusted label');
        self::assertSame('verified-id', $identity->providerUserId);
        self::assertSame('Verified name', $identity->name);
        self::assertNull($identity->email);
        self::assertFalse($identity->emailVerified);
        self::assertSame([], $identity->appleGrant);
    }

    public function test_inactive_linked_identity_is_not_recreated_or_moved_to_another_user(): void
    {
        $service = app(SocialAccountBindingService::class);
        $user = $service->bind($this->identity(), now());
        $user->forceFill(['active' => false])->save();
        try {
            $service->bind($this->identity(), now());
            self::fail('A disabled linked account must not be recreated.');
        } catch (\DomainException $error) {
            self::assertSame('social_account_disabled', $error->getMessage());
        }
        self::assertSame(1, User::withTrashed()->count());
        self::assertSame($user->id, SocialAccount::query()->sole()->user_id);
        self::assertFalse((bool) $user->fresh()->active);
    }

    public function test_binding_repairs_only_placeholder_identity_and_not_a_different_edited_email(): void
    {
        $service = app(SocialAccountBindingService::class);
        $user = $service->bind($this->identity(), now());
        $user->forceFill(['name' => 'طالب ركن', 'name_ar' => 'حساب المراجعة',
            'name_en' => 'حساب المراجعة', 'email' => 'edited@example.test', 'email_verified_at' => null])->save();
        $repaired = $service->bind($this->identity(), now());
        self::assertSame('Provider learner', $repaired->getRawOriginal('name'));
        self::assertNull($repaired->getRawOriginal('name_ar'));
        self::assertNull($repaired->getRawOriginal('name_en'));
        self::assertSame('edited@example.test', $repaired->email);
        self::assertNull($repaired->email_verified_at);
    }

    private function identity(
        string $provider = 'google',
        string $providerId = 'google-id',
        bool $verified = true
    ): VerifiedSocialIdentity {
        return VerifiedSocialIdentity::fromProvider($provider, [
            'id' => $providerId, 'name' => 'Provider learner', 'email' => 'LEARNER@example.test',
            'email_verified' => $verified, 'picture' => 'https://example.test/avatar.jpg',
        ]);
    }
}
