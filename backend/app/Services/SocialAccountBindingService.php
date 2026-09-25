<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\VerifiedSocialIdentity;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** App account linking only; browser top-up must never create or link an account. */
final class SocialAccountBindingService
{
    public function __construct(
        private readonly SocialIdentityGuardService $identityGuards,
        private readonly PortfolioShareIdentityService $portfolioShares
    ) {
    }

    public function bind(
        VerifiedSocialIdentity $identity,
        CarbonInterface $attemptStartedAt,
        ?string $preferredLocale = null
    ): User {
        return DB::transaction(function () use ($identity, $attemptStartedAt, $preferredLocale): User {
            $this->identityGuards->assertLoginStartedAfterLastDeletion(
                $identity->provider,
                $identity->providerUserId,
                $attemptStartedAt
            );
            if ($identity->emailVerified) {
                $this->identityGuards->lockVerifiedEmailLink((string) $identity->email);
            }
            $socialAccount = SocialAccount::query()
                ->where('provider', $identity->provider)
                ->where('provider_user_id', $identity->providerUserId)
                ->lockForUpdate()
                ->first();

            $user = $socialAccount
                ? User::withTrashed()
                    ->whereKey($socialAccount->user_id)
                    ->lockForUpdate()
                    ->first()
                : null;
            if ($socialAccount && (!$user || $user->trashed() || !(bool) $user->active)) {
                throw new \DomainException('social_account_disabled');
            }

            // Linking by email is allowed only when the provider itself verified that email.
            if (!$user && $identity->emailVerified) {
                $emailOwner = User::withTrashed()
                    ->where('email', $identity->email)
                    ->lockForUpdate()
                    ->first();
                if ($emailOwner && ($emailOwner->trashed() || !(bool) $emailOwner->active)) {
                    throw new \DomainException('social_account_disabled');
                }
                if ($emailOwner && strtolower((string) $emailOwner->role) !== 'client') {
                    throw new \DomainException('social_account_email_reserved');
                }
                if ($emailOwner && !$emailOwner->email_verified_at) {
                    // A learner may type any available address while
                    // editing the profile. That unverified string is not
                    // proof that a later provider identity owns this row.
                    throw new \DomainException('social_account_email_unverified');
                }
                $user = $emailOwner;
            }

            if (!$user) {
                $internalEmail = $identity->emailVerified
                    ? $identity->email
                    : sprintf('%s-%s@accounts.rokn.app', $identity->provider, hash('sha256', $identity->providerUserId));

                // These privileged fields are server-owned, never request
                // mass assignment. The first INSERT must already be a valid
                // client identity; role has no database default.
                $user = User::query()->forceCreate([
                    'name' => $identity->name,
                    'email' => $internalEmail,
                    'password' => Hash::make(Str::random(48)),
                    'social_provider' => $identity->provider,
                    'social_id' => $identity->providerUserId,
                    'profile_image' => $identity->picture,
                    // Push is opt-in on the device. The inbox and welcome
                    // credit still work before a learner accepts the prompt.
                    'notifications_status' => false,
                    // Continuing from the social sign-in screen accepts the
                    // linked terms and privacy notice shown directly below it.
                    'terms_accepted_at' => now(),
                    'privacy_notice_acknowledged_at' => now(),
                    'legal_notice_version' => (string) config('social_auth.legal_notice_version', '2026-08-06'),
                    'email_verified_at' => $identity->emailVerified ? now() : null,
                    'role' => 'client',
                    'active' => true,
                ]);

                if (empty($user->portfolio_slug)) {
                    $this->portfolioShares->ensure($user);
                }
            }

            $conflictingAccount = SocialAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', $identity->provider)
                ->where('provider_user_id', '!=', $identity->providerUserId)
                ->exists();
            if ($conflictingAccount) {
                throw new \DomainException('social_account_conflict');
            }

            SocialAccount::updateOrCreate(
                ['provider' => $identity->provider, 'provider_user_id' => $identity->providerUserId],
                [
                    'user_id' => $user->id,
                    'provider_email' => $identity->email,
                    'provider_name' => $identity->name,
                    'avatar_url' => $identity->picture,
                    'last_verified_at' => now(),
                    ...$identity->appleGrant,
                ]
            );

            $updates = [];
            // Repair only empty/demo identity fields. Linking a provider to
            // an existing account must never overwrite a name the learner
            // has already chosen.
            $staleNames = ['طالب ركن', 'محمد السكماني', 'حساب المراجعة'];
            $rawName = trim((string) $user->getRawOriginal('name'));
            if ($identity->name !== '' && ($rawName === '' || in_array($rawName, $staleNames, true))) {
                $updates['name'] = $identity->name;
            }
            foreach (['name_ar', 'name_en'] as $localizedNameColumn) {
                $localizedName = trim((string) $user->getRawOriginal($localizedNameColumn));
                if (in_array($localizedName, $staleNames, true)) {
                    // Let the model fall back to the verified provider name.
                    // This also repairs rows created by the old bilingual-name migration.
                    $updates[$localizedNameColumn] = null;
                }
            }
            if (empty($user->social_id) || $user->social_provider === $identity->provider) {
                $updates['social_provider'] = $identity->provider;
                $updates['social_id'] = $identity->providerUserId;
            }
            if ($identity->picture && !$user->profile_image) {
                $updates['profile_image'] = $identity->picture;
            }
            $currentEmail = Str::lower((string) $user->email);
            $hasInternalEmail = Str::endsWith($currentEmail, '@placeholder.com')
                || Str::endsWith($currentEmail, '@accounts.rokn.app');
            if ($identity->emailVerified && ($identity->email === $currentEmail || $hasInternalEmail)) {
                if ($identity->email !== $currentEmail) {
                    $updates['email'] = $identity->email;
                }
                if (!$user->email_verified_at) {
                    // Never mark a separately edited email as verified just
                    // because the provider verified a different address.
                    $updates['email_verified_at'] = now();
                }
            }
            if ($updates !== []) {
                $user->forceFill($updates)->save();
            }
            if ($preferredLocale !== null && $user->preferred_locale !== $preferredLocale) {
                $user->forceFill(['preferred_locale' => $preferredLocale])->save();
            }

            return $user;
        }, 3);
    }
}
