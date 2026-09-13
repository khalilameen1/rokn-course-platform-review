# Store release status — 2026-09-13

Source: the production monorepo, not the older Desktop checkout.
Target metadata: 1.0.56, Android 57, iOS 49.
Status: **Store preparation remains in progress; no submission for store review**.
The current Android AAB was built successfully from clean source `903a13b` and
contains the reviewed portfolio UI changes. Google processed and accepted its
upload as `57 (1.0.56)` and it was saved in an internal draft. The draft has not
been released to testers or submitted for public review.
No signed iOS archive has been produced.

## Implemented locally

- Account-bound, revocable third-party AI consent across chat and project review
  with draft preservation, queued-work enforcement and response reporting
- Sign in with Apple authorization-code exchange and encrypted revocation
  credentials; account deletion revokes the Apple authorization before erasure
- Public portfolio reporting, administrator suspension, administrator preview
  and an owner-visible suspended state without deleting private work
- Pinned iOS build environment and Android release provenance checks that fail
  if Git inspection fails or source changes during the build
- Corrected iOS privacy linkage for account-bound diagnostics and declared the
  actual product-analytics/first-party campaign purposes, without adding data
  collection or changing tracking behavior
- Added missing Apple login/revocation variable names to both backend example
  environments; no real credentials have been inserted or changed
- Owner-approved verified store test fulfillment: Google test/Apple sandbox coins
  retain paid-lot attribution while cash amount, gross revenue and net revenue
  remain zero. Client flags cannot designate a purchase as a verified test.
- Google consumption after committed credit, with database-backed leased retry
  through the existing scheduler. Authenticated notifications recover completed
  purchases; pending/cancelled payments cannot mint coins.
- Repeat refund/reversal cycles use distinct financial-event identities. Apple
  notifications reconcile the newest authenticated signedDate rather than their
  arrival order; missing or contradictory chronology remains under review.

Store-entry material is prepared in [console setup](../store/CONSOLE_SETUP.md),
[reviewer access](REVIEW_ACCESS.md) and the [Arabic/English listing files](../store/listing/README.md).
The Google app record and signing enrollment now exist. Complete listing/privacy
entry, reviewer access, screenshots and native purchase setup still require
actual account/device work. Apple's organization membership remains unverified.

These backend changes and their migrations have **not** been deployed. Coordinate
the mobile/backend rollout using `AI_CONSENT_RELEASE.md`; older clients cannot
complete new AI work once the server starts requiring affirmative consent.
Use [STORE_ROLLOUT.md](STORE_ROLLOUT.md) for the newly approved payment and
pre-publication portfolio deployment requirements.

## Verification completed

September 13 incremental checks (not an end-to-end store certification):

- Billing, notification recovery, refund cycles and reporting: 63 tests,
  598 assertions passed. Independent final source review found no additional
  demonstrated defect in that financial change set.
- A subsequent integration review reproduced a Google cancellation arriving
  before the device persisted its older PURCHASED result. Provider-confirmed
  cancellations now survive until receipt reconciliation. The new regression
  failed before the fix; seven focused tests then passed with 106 assertions.
- Mobile portfolio review contract, sharing/QR, account switching and stale
  reads: the latest focused five suites passed 38 tests. Earlier seven-suite
  integration run passed 55 tests before the two new stale-request regressions.
  The current production build subsequently completed `verify:release` and the
  Android bundle build with exit code 0. Its full test output was truncated, so
  no new aggregate suite/test counts are claimed here.
- Backend portfolio/profile/certificate integration: 73 tests and 616 assertions
  passed. The separate upload/replay/lease selection passed 41 tests and 395
  assertions. These selections overlap and must not be added together.
- The final portfolio integration review found that empty accounts and private
  drafts entered the pending-review inbox. Its regression failed before the fix;
  the complete pre-publication review file then passed 10 tests, 124 assertions.
- Social-proof parsing and presentation: eight mobile suites passed 70 tests,
  with TypeScript and scoped ESLint passing. Seven backend tests passed 121
  assertions for real-count contracts and production seeder restrictions. These
  checks do not establish the authenticity of historical production rows.
- After the AAB build, the known Backend CI failure in `PreviewAppDownloadTest`
  was corrected by expecting the existing versioned download URL. All four tests
  in that file passed with eight assertions. This is a test-expectation update,
  not a runtime/controller fix or a change to the built mobile binary.

September 12 baseline checks for the older artifact:

- Mobile release regression: 269 suites, 2,079 tests passed
- Mobile release lint and TypeScript checks passed
- Focused backend regression: 73 tests, 585 assertions passed for AI
  consent/reporting, project evaluation, Apple authentication/deletion, billing
  evidence, legal content and portfolio reporting
- Release configuration, library notices and repository secret scan passed
- Release script regression: 90 tests passed; accessibility audit: 400 source
  files passed
- Android release lint and native bundle build passed. The Android unit-test
  Gradle task reported NO-SOURCE, not a native unit-test pass
- After that Android build: 26 release-provenance tests passed including parsed
  iOS privacy regression checks; release-config validation passed. Listing JSON
  and platform text limits were checked. These later iOS/entry-preparation
  changes do not alter the already built Android byte sequence

These are code checks, not evidence of a successful real store purchase or a
signed iOS archive. Full native device/store review flows remain unverified.

## Release blockers

1. Complete the internal-testing setup before distributing the saved draft.
   Google app record `4974910218344866175` exists as `ركن Rokn` / `com.rokn`.
   Signing enrollment and AAB processing are complete. Internal track
   `4700165170808444276`, draft release `1`, contains `57 (1.0.56)` with ReTrace
   and native symbols attached. It is saved as a draft, not released to testers.
2. Configure native store products and verified server credentials; live package
   responses currently have no Apple/Google product identifiers
3. Deploy and exercise the now-approved verified-test fulfillment and Google
   finalization implementation. The September 13 approval is recorded; it is no
   longer waiting for permission, but actual store configuration and purchases
   have not been verified.
4. Deploy the approved pre-publication portfolio review
   with its owner-facing status UI. Existing shared works also require review.
   Confirm the old release has drained, then wait the media URL lifetime before
   the first administrator approval, as detailed in the rollout document.
5. Configure Apple lifecycle keys, app identity and signing, then produce and
   inspect an actual signed archive on the pinned macOS build environment
6. Deploy the coordinated backend changes, validate migrations and launch
   readiness, and exercise account deletion, purchase recovery, AI consent and
   public-content reporting against the intended production configuration
7. Preserve the now-saved Firebase app-signing fingerprints and deploy the
   pending Android domain-association change. Complete store
   metadata, privacy disclosures and reusable reviewer access, and exercise
   internal-track purchases and native-device flows before requesting review.
   Rebuild if further mobile changes are required; the artifact below covers
   only its recorded source commit

## Current Android artifact

Android artifact: `mobile/artifacts/Rokn-play.aab`

- Source commit: `903a13b18938a992b177adef0905f2a2b9a06dc9`, clean throughout build
- Built at: `2026-09-12T23:25:28.2049631Z` (September 13 in Cairo)
- Size: 67,356,236 bytes
- SHA-256: `a62b8915efd9a5fa9ff837e6c10944c3cdca6cbecb4d4f6a92680cb366dabf5b`
- Upload-certificate SHA-256:
  `0f0cde1dc533559f6f97c0d2df4e474be764b13ad071def14c19dc1a7812586e`
- Build sidecar: `com.rokn`, version `1.0.56` / `57`, minimum API 24,
  target API 36, channel `play`, profile `production`
- API origin recorded by the build: `https://rokn.app/api/v1/`
- Portable build tools: Node 24.19.0, npm 10.9.3, JDK 17.0.20
- Separate Play app-signing SHA-256 observed and approved in the console:
  `5a49ea3dba91df63f27e60fa87998737efb67657fa102ecb162bd1d63e232d9e`

The release build completed successfully and generated the sidecar above.
Independent inspection of this exact `903a13b` AAB then confirmed:

- The SHA-256 and byte size remained unchanged throughout inspection.
- Google bundletool 1.18.3 validation and jarsigner verification passed; the
  upload certificate matches the sidecar and configured upload-key fingerprint.
- The actual manifest is `com.rokn`, `1.0.56` / `57`, minimum API 24 and target
  API 36, not debuggable and with cleartext traffic disabled. Billing client
  9.1.0 is present; external CheckoutActivity is disabled and unexported.
- Bundle configuration declares `PAGE_ALIGNMENT_16K`. All 46 ELF64 libraries
  (23 arm64-v8a and 23 x86_64) and 137 LOAD segments passed alignment checks with
  no rounded RELRO/writable-LOAD collisions.
- A temporary derived universal APK passed `zipalign -c -P 16 -v 4` and APK
  signature verification. It uses an isolated debug key for inspection only
  and must never be distributed as the store build.

Jarsigner reported certificate-chain/self-signing, missing-timestamp,
JarInputStream manifest-order and unsigned-POSIX-attribute warnings, not a failed
signature. No artifact rewrite or mobile rebuild was performed for inspection.
The local evidence record is
`C:/Users/TechNook/.codex/visualizations/2026/08/11/019ff0d4-72c9-77d0-ba28-6c94f828b54c/aab-903a13b-inspection-aa7c5071858644d4a38d4cb0d65e3b4f/verification.json`.

Google Play displays `57 (1.0.56)`, minimum API 24 and target API 36, with ReTrace
and native symbols attached. Its processed upload is saved in the internal
draft only. No physical-device/16 KB runtime or completed internal-track
purchase evidence is recorded. Symbol upload to Sentry remains unverified.

Source `903a13b` was pushed to `origin/codex/mobile-first-landing` after the
owner's confirmation. Later backend migration, test and documentation changes do not alter this
AAB; its sidecar source pin remains authoritative. A push is not a deployment.

## Production and console checkpoints

Production was inspected read-only. Its catalog and authentication-method routes
under `/api/v1/` respond successfully; launch readiness still reports missing
recovery/mobile-release evidence. Do not fabricate release records to clear it.

The company Play Console account was inspected on September 13 while signed in
as Rokn. After the owner's specific confirmation, the
website-verification request completed and Google displayed that ownership of
`https://rokn.app` was verified. After reloading the console, phone-verification
controls became available. The owner supplied the SMS code and Google confirmed
both the contact and public developer phone fields; the changes were saved.
The Arabic, free-to-download application draft uses the neutral title `ركن Rokn`.
After the owner's action-time confirmation of the required declarations, Google
created app record `4974910218344866175`. App signing was then enrolled with the
distinct app-signing/upload certificate fingerprints recorded above. Google
processed the AAB as `57 (1.0.56)` and the release was saved as internal draft `1`,
with ReTrace and native symbols attached. No release to testers or public review
submission occurred. Upload acceptance, account verification and app creation/
signing enrollment are not store review approval.
Public privacy, support and deletion pages return 200. Both Android association
files advertise the old certificate only; the main Apple association URL returns
404. Complete the verified identity/key configuration before claiming native
sign-in or app links work for store-installed builds.

With the owner's specific approval, Firebase saved the new app-signing SHA-1
and SHA-256 for `com.rokn` in `rokn-production-2026`. The production backend
Google web client belongs to the same project number, `112556080712`. The app
currently uses the backend browser-authentication flow, not its unused native
Google helper. These matching settings do not prove an installed Play login.
Laravel Cloud saved the new app-signing SHA-256 alongside the existing direct
certificate as one pending environment change. It has not been deployed or
verified in a live association response.

Laravel Cloud was inspected directly on September 13. Its active deployment is
`31e192c5c58b8755d8f2ccec77f9539f1c0dc1cd` from `main`, titled
`Ship Rokn 1.0.55 test APK`. Push to deploy and deployment hooks are both off.
The configured build runs Composer install, npm ci, npm run production and
`php artisan optimize`. Deploy commands are
`php artisan rokn:preflight --configuration-only --connectivity` followed by
`php artisan rokn:release-migrate`. These are inspected settings, not commands
executed during this work. No new backend deployment or production migration
has occurred. Promote only after the exact candidate Backend CI succeeds and
then follow the coordinated rollout, not merely after uploading the Android AAB.

Backend CI run `34725386001` on `903a13b` failed in the full test suite
(46 failed, 4 skipped, 1,640 passed). The demonstrated failures were traced to
missing explicit AI consent in older test fixtures, a stale versioned-download
expectation, and a non-resumable store recovery migration. Consent is now
recorded only in the individual AI fixtures; rejection and revocation checks
remain enabled. The five September 12/13 migrations now resume at each committed
DDL boundary without replacing persisted account bindings or consent/review data.

Focused verification after those fixes:

- Hardening, project lineage, submission lookup and upload failures: 95 tests,
  872 assertions passed.
- Project report/reply/presentation recovery plus AI consent/reporting, including
  missing and withdrawn consent: 63 tests, 555 assertions passed.
- Migration resumability: 26 tests, 229 assertions passed. Fresh migration and
  two store-recovery integration cases: 3 tests, 64 assertions passed.
- Preview download: 4 tests, 8 assertions passed as noted above.

Migration execution was verified on test SQLite, not a live MySQL database.
These focused results do not replace the full Backend CI release gate. The
corrected branch must pass that gate before promotion; server deployment is held.

Apple's official sign-in page is open only. Organization membership, Team ID,
App Store Connect record and signed iOS archive are not verified.

Store acceptance is decided by Apple/Google after reviewing the actual binary,
configuration and listing. Passing the checks above does not guarantee approval.
