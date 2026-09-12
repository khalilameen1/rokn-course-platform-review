# Store release status — 2026-09-12

Source: the production monorepo, not the older Desktop checkout.
Target metadata: 1.0.56, Android 57, iOS 49.
Status: **not submitted and not yet a release candidate**.

## Implemented locally

- Account-bound, revocable third-party AI consent across chat and project review
  with draft preservation, queued-work enforcement and response reporting
- Sign in with Apple authorization-code exchange and encrypted revocation
  credentials; account deletion revokes the Apple authorization before erasure
- Public portfolio reporting, administrator suspension, administrator preview
  and an owner-visible suspended state without deleting private work
- Pinned iOS build environment and Android release provenance checks that fail
  if Git inspection fails or source changes during the build

These backend changes and their migrations have **not** been deployed. Coordinate
the mobile/backend rollout using `AI_CONSENT_RELEASE.md`; older clients cannot
complete new AI work once the server starts requiring affirmative consent.

## Verification completed

- Mobile release regression: 269 suites, 2,075 tests passed
- Mobile release lint and TypeScript checks passed
- Focused backend tests passed for AI consent/reporting, project evaluation,
  Apple authentication/deletion, billing evidence and portfolio reporting
- Release configuration, library notices and repository secret scan passed

These are code checks, not evidence of a successful real store purchase or a
signed iOS archive. Full native device/store review flows remain unverified.

## Release blockers

1. Complete Google identity verification for the company Play Console account
   and inspect the existing app, upload-key registration and product setup
2. Configure native store products and verified server credentials; live package
   responses currently have no Apple/Google product identifiers
3. Obtain explicit approval before changing production financial fulfillment to
   accept verified store sandbox purchases with zero revenue and finalize valid
   Google purchases server-side. Gateway/model scaffolding exists but fulfillment
   is intentionally unchanged and production sandbox receipts remain rejected
4. Obtain explicit approval before introducing pre-publication portfolio review
   that would pause existing shared portfolios until approved. Reporting and
   suspension exist; the rejected moderation migration was not applied
5. Configure Apple lifecycle keys, app identity and signing, then produce and
   inspect an actual signed archive on the pinned macOS build environment
6. Deploy the coordinated backend changes, validate migrations and launch
   readiness, and exercise account deletion, purchase recovery, AI consent and
   public-content reporting against the intended production configuration
7. Build signed artifacts from the final clean commit, verify signature and
   bundle/16 KB compatibility, then complete store metadata, privacy disclosures,
   reviewer access and internal testing before requesting review

## Artifact state

The Android AAB attempt failed at lint because the local SDK path was not escaped
in `android/local.properties`. The local path is corrected, but no new successful
signed AAB has been produced after these changes. The previous debug-signed APK
is not a store candidate. Do not distribute it as this version.

Production was inspected read-only. Its catalog and authentication-method routes
under `/api/v1/` respond successfully; launch readiness still reports missing
recovery/mobile-release evidence. Do not fabricate release records to clear it.

Store acceptance is decided by Apple/Google after reviewing the actual binary,
configuration and listing. Passing the checks above does not guarantee approval.
