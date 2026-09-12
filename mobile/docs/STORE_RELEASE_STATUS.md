# Store release status — 2026-09-12

Source: the production monorepo, not the older Desktop checkout.
Target metadata: 1.0.56, Android 57, iOS 49.
Status: **Android upload bundle built and inspected locally; not submitted and
not yet approved for release**. iOS has not been built.

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
7. Match the Android upload certificate against Play Console, complete store
   metadata, privacy disclosures and reusable reviewer access, and exercise
   internal-track purchases and native-device flows before requesting review.
   Rebuild if further mobile changes are required; the artifact below covers
   only its recorded source commit

## Artifact state

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

Store acceptance is decided by Apple/Google after reviewing the actual binary,
configuration and listing. Passing the checks above does not guarantee approval.
