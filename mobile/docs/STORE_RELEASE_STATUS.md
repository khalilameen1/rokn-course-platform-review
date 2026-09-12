# Store release status — 2026-09-13

Source: the production monorepo, not the older Desktop checkout.
Target metadata: 1.0.56, Android 57, iOS 49.
Status: **Store preparation remains in progress and has not been submitted**.
The inspected Android bundle below predates the portfolio review UI changes and
must be rebuilt from the final reviewed source. iOS has not been built.

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
These drafts have not been entered into either console. Reviewer access,
screenshots, membership, signing enrollment and native purchase setup still
require actual account/device work.

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
  The production build will run the complete release gate again.
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

1. Finish the first Google app record and its signing/product configuration.
   Website ownership and both phone fields were verified on September 13 and
   saved successfully. Create app is enabled and `com.rokn` is available.
   The owner confirmed the required declarations and the app draft was created
   as `ركن Rokn`, app record `4974910218344866175`. No binary has been uploaded.
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
7. Match the Android upload certificate against Play Console, complete store
   metadata, privacy disclosures and reusable reviewer access, and exercise
   internal-track purchases and native-device flows before requesting review.
   Rebuild if further mobile changes are required; the artifact below covers
   only its recorded source commit

## Older inspected artifact — replacement pending

Android artifact: `mobile/artifacts/Rokn-play.aab`

- Source commit: `dee87a51f1c3c0b65be19eab84725d97b30655b7`, clean throughout build
- Built at: `2026-09-12T20:12:48.7474280Z`
- Size: 67,355,456 bytes
- SHA-256: `51a97f8b00d6c4c3bd73e24aa3b837435e6365bbf554da20761164b3ad1c1976`
- Upload-certificate SHA-256:
  `0f0cde1dc533559f6f97c0d2df4e474be764b13ad071def14c19dc1a7812586e`
- Actual manifest: `com.rokn`, version `1.0.56` / `57`, minimum API 24,
  target API 36, not debuggable, cleartext traffic disabled
- Billing client 9.1.0 is present; external CheckoutActivity is disabled and
  unexported in this Play build
- API origin recorded by the build: `https://rokn.app/api/v1/`
- Portable build tools: Node 24.19.0, npm 10.9.3, JDK 17.0.20
- Bundled build metadata identifies Android Gradle Plugin 8.12.0 (distinct
  from the Gradle 9 build runner)

Google bundletool 1.18.3 validated the AAB successfully. Jarsigner verified the
signature and the certificate matched the local upload-key pin. This does not
establish a match with an existing Play Console app or its separate app-signing
key. Jarsigner also emitted self-signed-certificate/no-timestamp and streaming
manifest-order warnings; bundletool validation and APK generation succeeded.
The streaming warning comes from Signflinger placing MANIFEST.MF at the end
while JarInputStream expects it at the start. OpenJDK reports that reader-order
discrepancy separately from signature verification failures; no artifact rewrite
or re-signing was performed to suppress the warning.

Bundle configuration declares PAGE_ALIGNMENT_16K. A universal inspection APK
generated from this exact AAB passed zipalign with `-P 16` and APK signature
verification. That temporary APK was signed with a debug key by bundletool for
inspection only; it is not a distributable release or proof of Play signing.

The actual AAB contains 46 ELF64 libraries (23 arm64-v8a and 23 x86_64). All 137
LOAD segments have alignment of at least 16 KB and matching file/virtual-address
alignment. No rounded RELRO protection overlaps writable LOAD bytes. These are
static checks, not a successful launch on a 16 KB device; no suitable connected
device was available. Physical-device flows and backcompat-disabled runtime
checks remain pending.

Matching JavaScript and R8 symbol maps are saved in
`mobile/artifacts/Rokn-play-symbols/`. Their upload to Sentry is not verified.
The earlier SDK-path lint failure was corrected before this successful build.
The previous debug-signed APK is not this store version and must not be handed
out as it. This document may have a later documentation-only commit than the
artifact; the source pin above remains the authoritative artifact revision.

Production was inspected read-only. Its catalog and authentication-method routes
under `/api/v1/` respond successfully; launch readiness still reports missing
recovery/mobile-release evidence. Do not fabricate release records to clear it.

The company Play Console account was inspected on September 13 while signed in
as Rokn. No app records exist. After the owner's specific confirmation, the
website-verification request completed and Google displayed that ownership of
`https://rokn.app` was verified. After reloading the console, phone-verification
controls became available. The owner supplied the SMS code and Google confirmed
both the contact and public developer phone fields; the changes were saved.
Create app is now enabled and the package-name check reports `com.rokn` available.
The Arabic, free-to-download application draft uses the neutral title `ركن Rokn`.
After the owner's action-time confirmation of the required declarations, Google
created app record `4974910218344866175`. No binary upload or publication has
occurred at this checkpoint. Neither account verification nor app creation is
store review approval.
Public privacy, support and deletion pages return 200. Both Android association
files advertise the old certificate only; the main Apple association URL returns
404. Complete the verified identity/key configuration before claiming native
sign-in or app links work for store-installed builds. No console upload, public
release, backend deployment or production migration has occurred in this work.

Store acceptance is decided by Apple/Google after reviewing the actual binary,
configuration and listing. Passing the checks above does not guarantee approval.
