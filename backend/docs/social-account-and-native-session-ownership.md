# Social account binding and native session ownership

## Trust and responsibility boundaries

Provider credentials are verified by `SocialAuthProviderRegistry`.
`SocialLoginAction` owns the application sequence: provider verification,
freshness, account binding, claim-protected session issuance and recoverable
post-login work. It takes typed credentials/device facts and returns a
`SocialLoginResult`, not a Request or JSON response.

The HTTP boundary validates native Apple nonce/authorization-code inputs and
browser PKCE/return destinations. `SocialLoginCredentials::fromClaimedAttempt`
copies server-owned attempt identity, timestamp, nonce hash and claim ID from the
persisted claimed model. The native factory cannot supply these browser facts.
`ClientDeviceInput` owns common HTTP device validation/header mapping and produces
`ClientDeviceContext`. `SocialLoginResponse` alone renders the shared mobile
envelope and the lightweight profile with the just-verified session provider.

`VerifiedSocialIdentity` normalizes only verified provider output for account
binding. Apple's first-consent display name may label an account but does not
establish provider ID, email ownership or an authorization grant. Apple grant
data is not accepted for other providers.

`SocialAccountBindingService` owns the transactional account mutation:

- lock the provider deletion guard, then the verified-email linking guard
- lock an existing provider link and its user, or resolve a verified email owner
- reject inactive/deleted accounts, reserved administrative emails, unverified
  user-entered emails and conflicting same-provider identities
- create a complete client identity on the first INSERT, not a partial user that
  depends on database defaults before a later role update
- persist the provider link and encrypted Apple grant where applicable
- repair empty/placeholder profile fields without overwriting a chosen name or
  verifying a separately edited email
- update a requested locale without resetting it on a replay lacking that input

Creation uses an explicit server-authored `forceCreate` attribute list. The User
model's guarded role/active/verification fields remain guarded against request
mass assignment. The real migrated users schema requires a role with no default;
the prior two-stage creation failed before reaching its second write.

Binding does not issue API tokens, enforce device policy, call a provider again,
or grant welcome credits. Query/domain failures roll back user creation, share
identity and link together.

`NativeLoginSessionService` owns native bearer issuance under the user lock. It
requires the caller's transaction, reloads the user to reject deletion or disable
between binding and issuance, applies the device policy, issues the token with
the just-verified provider/subject, and enforces the session limit. Device
rotation, old-token retirement, push retirement and the new bearer either commit
together or roll back together.

The service receives only provider/subject strings, not provider credentials or
Apple grant data. Coarse token metadata is the existing storage contract. HTTP
headers are mapped outside the owner; token sanitization stays in `HasApiTokens`.

## OAuth claim and delivery

The application action wraps browser-triggered native issuance in
`SocialOAuthAttemptService::whileCompletionClaimIsOwned`. A reclaimed, consumed or
expired claim cannot execute the session write. Native Apple issuance still uses
its original database transaction. Neither refactor changes completion replay,
PKCE checks, token encryption, return URLs, or the public API response shape.

Push registration and welcome credit remain recoverable post-login work; their
failure must not replace a successful authentication response. They are not
part of account linking or native session issuance. `PushDeviceRegistrationService`
is the one transactional owner used by login and the authenticated refresh route.
It rechecks the learner under lock, normalizes the OS, reassigns the unique token,
retires prior installation tokens and preserves notification consent. The refresh
route takes the installation ID from the bearer in preference to the payload.

`SocialOAuthController` no longer builds a synthetic Request or calls
`SignController`. Both entry points call the application action and the same
response renderer. Browser replay/finalization remains at the completion boundary,
including revocation if claim finalization loses ownership and retry after a
transient failure. The browser credential validation limits are preserved.

Browser recharge login intentionally has a different contract: it may resolve
only an already-linked exact provider identity and must never create/link an
account by matching email. `WebWalletAuthController` does not use the new binding
owner. Do not merge the two policies merely to reuse code.

## Evidence and remaining work

- `SocialAccountBindingOwnershipTest` covers first-insert identity, replay,
  verified-email linking, profile preservation, rejected ownership, rollback,
  deletion guards and encrypted Apple grants.
- `SocialLoginBindingEndpointTest` exercises actual browser completion through
  the HTTP route, replaying the same bearer and preserving retryable conflicts.
  It also proves request-supplied role/active values cannot control the account.
- `NativeLoginSessionOwnershipTest` covers transaction requirements, device
  rotation, rollback after token failure, disabled/deleted users, permanent-device
  denial, and a stale OAuth claimant attempting real bearer issuance.
- `SocialLoginActionOwnershipTest` invokes the request-free action directly,
  preserving immutable claim facts, provider failure disposition and successful
  sessions during push/welcome failures. A controller binding deliberately throws
  if application login attempts to resolve it.
- `PushDeviceRegistrationOwnershipTest` covers reassignment, rotation rollback,
  inactive/deleted users, no-token OS updates and the real authenticated endpoint's
  bearer-owned installation ID.
- `AppleNativeLoginSessionTest` exercises the real native HTTP route, registry,
  signature/nonce verification and credential exchange with disposable test keys
  and fake provider responses. It verifies retry, encrypted grants, old identity
  rejection and that request fields cannot fabricate browser verification.
- The browser transient-failure test now exercises real account/session creation
  on the migrated schema instead of mocking a sibling controller's JSON response.
  Other browser endpoint tests preserve header metadata and replay one bearer.

The existing authentication, Apple, device-policy, account-erasure, welcome-reward
and browser recharge suites remain regression coverage. This is not a claim that
the entire authentication controller or repository is fully reorganized. Discovery,
logout/account deletion and remaining browser exchange orchestration still need
their own evidence-based assessment. All provider traffic above is faked; passing
tests do not claim a live Apple/Google sign-in or physical-device verification.
