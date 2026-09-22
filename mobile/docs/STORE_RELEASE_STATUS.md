# Store release status — 2026-09-14

Source: the production monorepo, not the older Desktop checkout.
Target metadata: 1.0.57, Android 58, iOS 49.
Status: **Google Play setup is complete and production release 58 is staged for
review; the automated pre-submission checks passed and the ten changes have not
yet been sent**.
The latest Android AAB was built from clean source
`6d718e28f265148df6d12c3fda1c05fce44ce390` as `58 (1.0.57)`.
Its SHA-256 is `4cc831137db744f06f9678a267e0d3619dfeac697c9ac8cc009dec917e38fe89`
and API base is `https://rokn.app/api/v1/`. Google accepted the bundle and the
Console confirmed `58 (1.0.57)` available to internal testers on September 14
at 00:01 Cairo time. Candidate behavior is verified on the owner's phone and on
the Play-enabled emulator with the final reviewer account as recorded below.

The preceding Android AAB was built successfully from clean source `903a13b` and
contains the reviewed portfolio UI changes. Google processed and accepted its
upload as `57 (1.0.56)`. It is now available to the three approved internal testers
on track `4700165170808444276`. It has not been submitted for public review.
No signed iOS archive has been produced.
The matching backend is now deployed as Laravel Cloud deployment `204`, source
`855f7293fe3e96682a87f2b79d69ba4238c98f87`. Its backend code is unchanged from
deployment `202` / `3c72607`; deployment `203` applied the Google billing secret
and deployment `204` applied the two RTDN verification values. Neither changes
the AAB source pin. Authenticated Google Play test delivery was verified on
production and package 4's Google channel is enabled. Native licensed-test
purchases and subsequent mentor-plan enrollments were verified for both the owner
and the final reviewer account below.

## September 14 submission request checkpoint

The owner requested store review after completing a course purchase on release
58. Read-only production command 188 at 21:18:53 UTC on September 13 (00:18:53
Cairo on September 14) confirmed Google purchase 1 / order 17 for user 6:
product `rokn.coins.900`, environment `test`, status `credited`, order approved
and financially settled, verified at 21:08:40 and finalized at 21:08:41 UTC.
There is no reversal or finalization retry. Settlement is `test_purchase`, not
cash revenue. Course order 18 then created active enrollment 8 in course 10,
mentor plan 27 with chat enabled, 50 messages and certificate eligibility.
This supersedes older purchase-pending notes; it does not verify refund/recovery
or all other account screens. Historical reviewer user 13 remains unenrolled,
but the replacement final reviewer is user 14 and is fully provisioned below.

The three remaining Play setup areas were completed and saved. Target audience is
18+ only and Google-known minors are blocked, matching the live privacy policy
and the current AI-provider eligibility boundary. Data safety is complete with
the account-deletion URL and no unsupported separate data-deletion claim. The
store listing is now `Rokn: كورسات هتكملها`, with rewritten Arabic copy, the
generated feature graphic disclosed as AI-generated, and four authentic
1080x1920 screenshots captured from release 58. Production is staged for Egypt,
where the active coin product is priced, and reuses the already tested AAB
`58 (1.0.57)`. Google marks that release ready and its automated pre-submission
checks completed without a reported problem. The ten changes await only the
final review request.

## Android 58 account-page loading fix

- The owner's physical phone was verified as Google Play-installed Android 57
  / 1.0.56, not the older emulator build. Its My Corner screen displayed the
  specific pre-request account-boundary preparation error while a session existed.
- Removed the native Expo digest dependency from deterministic local account and
  guest storage keys. The existing JS SHA-256 implementation produces the same
  key prefixes; authentication persistence and account-change guards are unchanged.
- The exact native exception was not captured on the phone. The shared failing
  branch is established, but successful physical-device behavior after the change
  must still be verified before calling the incident resolved.
- The new regression failed before the source fix and passed afterward. Existing
  cache/race fixtures now exercise real hashes and the account-boundary contract
  instead of relying on the removed native digest call.
- Complete release gates passed: 273 Jest suites / 2129 tests, 93 release-script
  tests, TypeScript, ESLint, dependency/legal checks, Android release lint and the
  signed production AAB build. These are not end-to-end phone acceptance evidence.
- No phone app data, learner records, purchases or production settings were erased.
- On the owner's next failure report, ADB still showed Play-installed 57 / 1.0.56.
  A subsequent read at 00:08 Cairo time confirmed the phone had updated to 58 /
  1.0.57. The actual screen then showed Blender course checkout, its coin package
  and the native Google Play test-card purchase sheet, explicitly stating no
  charge. The agent did not confirm the purchase. This verifies the course and
  package-loading path progressed on 58; My Corner, other private pages and
  completed purchase fulfillment are not yet verified by this observation.

## Implemented in source

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

These backend changes and their five September 12/13 migrations were deployed
on September 13. Coordinate mobile availability using `AI_CONSENT_RELEASE.md`;
older clients cannot complete new AI work without affirmative consent.
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

## Remaining release work

1. Send the staged ten changes for review and record the resulting status. The
   automated pre-submission checks have completed without a reported problem.
2. Keep the reviewer account, production backend and course media available
   throughout review. Do not delete or repurpose the verified account.
3. After submission, monitor for Play policy questions or rejection details and
   respond against the exact release 58 configuration.
4. Refund/recovery, cancellation retry and disposable-account deletion remain
   useful post-submission regression coverage; they are not presented as
   completed acceptance evidence.
5. Apple remains a separate release stream: organization membership, lifecycle
   keys, app identity, signing and a signed iOS archive are still unverified.

## Current Android artifact

Android artifact: `mobile/artifacts/Rokn-play.aab`

- Source commit: `6d718e28f265148df6d12c3fda1c05fce44ce390`, clean throughout build
- Built at: `2026-09-13T20:56:44.9995657Z` (September 13/14 in Cairo)
- Version: `1.0.57` / `58`, minimum API 24, target API 36
- Size: 67,356,226 bytes
- SHA-256: `4cc831137db744f06f9678a267e0d3619dfeac697c9ac8cc009dec917e38fe89`
- Upload-certificate SHA-256:
  `0f0cde1dc533559f6f97c0d2df4e474be764b13ad071def14c19dc1a7812586e`
- Build sidecar: `com.rokn`, channel `play`, profile `production`, public
  distribution eligible, clean source
- API origin recorded by the build: `https://rokn.app/api/v1/`
- Separate Play app-signing SHA-256 observed and approved in the console:
  `5a49ea3dba91df63f27e60fa87998737efb67657fa102ecb162bd1d63e232d9e`

Google accepted this artifact for internal testing and the production release
draft reuses the same processed bundle. The release review screen shows
`58 (1.0.57)`, minimum API 24, target API 36 and status ready for release.

### Previous Android 57 verification (historical)

The preceding release build completed successfully and generated its sidecar.
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
and native symbols attached. Its processed upload is released to the approved
internal testers only. No physical-device/16 KB runtime or completed internal-track
purchase evidence is recorded. Symbol upload to Sentry remains unverified.

Source `903a13b` was pushed to `origin/codex/mobile-first-landing` after the
owner's confirmation. Later backend migration, test and documentation changes do not alter this
AAB; its sidecar source pin remains authoritative. A push is not a deployment.

## Production and console checkpoints

The earlier read-only production inspection reported missing recovery/mobile-
release evidence in launch readiness. Deployment success and the post-deployment
schema-only preflight do not by themselves clear that separate gate. Do not
fabricate release records to clear it.

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
processed the AAB as `57 (1.0.56)` and the release was initially saved as internal
draft `1`, with ReTrace and native symbols attached. It was subsequently released
to the two approved internal testers on September 13. No public review submission
occurred. Upload acceptance, account verification and app creation/
signing enrollment are not store review approval.
Public policy, contact and account-deletion pages returned HTTP 200 again after
deployment. The Android domain association now includes the old direct and new
Play app-signing certificates, not the upload certificate. The earlier main
Apple association check returned 404; no new Apple association verification is
recorded. These checks do not prove sign-in or app links on a Play-installed build.

With the owner's specific approval, Firebase saved the new app-signing SHA-1
and SHA-256 for `com.rokn` in `rokn-production-2026`. The production backend
Google web client belongs to the same project number, `112556080712`. The app
currently uses the backend browser-authentication flow, not its unused native
Google helper. These matching settings do not prove an installed Play login.
The saved Android association environment change was applied in deployment
`202`. The post-deployment response was verified with both fingerprints:

- Existing direct: `01:97:0F:4D:0A:A5:9B:F4:D8:F4:DE:FB:CA:7C:B8:77:34:6D:69:BF:B7:15:A5:B6:4F:A6:DC:D2:73:3F:89:3D`
- Play app-signing: `5A:49:EA:3D:BA:91:DF:63:F2:7E:60:FA:87:99:87:37:EF:B6:76:57:FA:10:2E:CB:16:2B:D1:D6:3E:23:2D:9E`

Before promotion, Laravel Cloud's active deployment was
`31e192c5c58b8755d8f2ccec77f9539f1c0dc1cd` from `main`, titled
`Ship Rokn 1.0.55 test APK`. Push to deploy and deployment hooks were both off.
The configured build runs Composer install, npm ci, npm run production and
`php artisan optimize`. Deploy commands are
`php artisan rokn:preflight --configuration-only --connectivity` followed by
`php artisan rokn:release-migrate`. The following subsequent promotion is now
recorded separately from the earlier inspection:

- Backend CI [34727103158](https://github.com/khalilameen1/rokn-course-platform-review/actions/runs/34727103158)
  succeeded for `3c7260785db3696224672ea399bdd11fa6d37c24`: 1,709 passed,
  4 skipped, 17,211 assertions. `main` was fast-forwarded to that exact commit.
- Cloud manual backup `before-store-3c72607-20260913` completed at
  `2026-09-13 00:25:47 UTC`, size 110.3 MB, before deployment. This records the
  provider backup result, not a new signed-artifact verification or restore drill.
- Laravel Cloud deployment `202` of `3c72607` succeeded at
  `2026-09-13 00:30:56 UTC` in 1 minute 45 seconds. All five September 12/13
  migrations completed; Cloud showed App healthy, one ready instance and
  routing for three domains.
- Post-deployment `/api/v1/courses/list` returned 12 courses; authentication
  methods advertised Google and TikTok. Policy/contact/deletion pages returned
  HTTP 200, and the Android association fingerprints matched those above.
- Cloud command `165`, `php artisan rokn:preflight --schema-only`, passed.
  This is not proof that all old workers or in-flight requests have drained.
  No portfolio approval is recorded; retain the drain and media-lifetime gate
  in [STORE_ROLLOUT.md](STORE_ROLLOUT.md).
- Runtime command `166` finished at `2026-09-13T00:39:31Z`: environment
  `production`, queue connection `redis`; scheduler heartbeat healthy at 27
  seconds old. All seven queues (`default`, `notifications`, `ai-chat`,
  `ai-feedback`, `media`, `operations`, `webhooks`) were healthy, each size 0,
  with heartbeat ages 26–27 seconds. Google finalization counts were 0 pending,
  0 due and 0 deferred. No new purchase or actual store charge was performed.
  The command returned `deployment=null` because deployment metadata was not
  populated; source identity comes from Cloud deployment `202`, not that field.
  Fresh heartbeats prove queue activity, not old-worker drain or native purchase
  success. These checks complete the recorded deployment runtime inspection,
  not the separate strict launch-readiness or store-review gates.

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

The focused migration tests used SQLite. The later full Backend CI and actual
production migration execution are recorded above; the earlier deployment hold
was lifted only after the exact-commit CI result and the owner's authorization.

### Internal billing setup (September 13 continuation)

- The company Google Payments profile is now accessible from Play Console;
  the one-time-product screen is no longer blocked by merchant setup. No payout
  method, real charge or service-fee-program enrollment was completed here.
- Internal tester list `Rokn internal QA` was saved and attached to track
  `4700165170808444276` with only `roknproduction@gmail.com` and
  `khalilameen52@gmail.com`. Release 57 is now active and available to internal
  testers, marked unreviewed. The opt-in URL was opened successfully and displays
  an invitation, not a completed installation:
  `https://play.google.com/apps/internaltest/4700165170808444276`.
  The same two accounts were saved as License Testing accounts after the owner's
  specific confirmation of its developer-account-wide scope; response mode is
  `RESPOND_NORMALLY`.
- Service account `rokn-play-billing@rokn-production-2026.iam.gserviceaccount.com`
  was created without project-wide IAM roles. Android Publisher API is enabled.
  Exactly one JSON key was created following confirmation. Its validated value
  was saved as the Cloud organization secret `GOOGLE_PLAY_SERVICE_ACCOUNT_BASE64`,
  linked only to this application's production environment. The plaintext local
  download remains pending owner-specific cleanup confirmation; no deletion was
  performed and it must never be committed. After the owner's explicit approval
  including refunds, the Play invitation
  was saved and the service account became Active. Readback showed only the four
  approved permissions for `com.rokn`: app read, app-quality information,
  financial-data view and order/subscription management including refunds. No
  account-wide administrator or release permission was granted.
- Cloud command `167` confirmed package name `com.rokn`, no configured Google
  service-account file/base64 credentials and no RTDN audience/service account.
  That earlier inspection is superseded by the credential/package updates and
  authenticated notification-transport verification below.
- `rokn.coins.900` was saved and activated after the earlier incomplete form.
  Its single `standard` buy option is backward-compatible, Egypt-only, EGP 11.11,
  with multi-quantity disabled. No duplicate product or other regional sale was
  created. The earlier automatically rounded EGP 12.99 was not retained.
- Deployment `203` / `855f7293fe3e96682a87f2b79d69ba4238c98f87` succeeded after
  linking the secret. Compared with deployment `202`, Git changes are mobile
  documentation only; backend code and infrastructure sizing are unchanged.
- Cloud command `168` finished successfully. It validated the deployed credential's
  type/project/client identity without printing the secret and atomically bound
  package 4 to `rokn.coins.900`. Readback: 900 coins, price 11.11, active, direct
  enabled, Google disabled, Apple disabled. Existing prices, coin quantities and
  other channels were preserved. This is configuration evidence, not an OAuth or
  purchase-verification success.
- Cloud command `170` then confirmed OAuth and credential identity, and read the
  active `rokn.coins.900` product with its backward-compatible buy option and
  Egypt price EGP 11.11. Its voided-purchase GET returned HTTP 401. A fresh bounded
  probe in command `171`, completed at `2026-09-13T11:22:10Z`, again reported
  `oauth_ok=true`, `app_purchase_read_ok=false`, HTTP 401, domain
  `androidpublisher`, reason `permissionDenied`. The four saved permissions were
  re-read and still matched the approved scope. Propagation is possible but not
  established; no broader permission change, purchase or refund was performed.
  Package 4's Google channel remained `false` at this checkpoint; command `175`
  below records its later enablement after the access and RTDN checks.
- The Pixel_9a emulator was later updated to signed release 58. After the final
  reviewer account accepted the internal-test invitation and the app restarted,
  Play Billing resolved the package despite the ADB-installed build reporting no
  installer package. A licensed test purchase and course enrollment then succeeded.
  Refund, cancellation/pending and repeated-purchase cases remain unverified.
- Follow-up command `172`, completed at `2026-09-13T12:23:52Z`, reran the same
  bounded, read-only OAuth and voided-purchases probe. It returned
  `oauth_ok=true` and `app_purchase_read_ok=true`. This supersedes the unresolved
  access result of commands 170/171; it does not establish why the earlier
  denial occurred. No credential rotation, broader permission, purchase, refund
  or Google package enablement was performed for this check.
- The first dedicated review Google account was created and registered as user 13,
  then replaced because its password was not reusable. The final account is user
  14. `Rokn internal QA` now contains four identities without removing any prior
  member and remains selected for both internal and license testing. User 14
  accepted the invitation, signed into the Play-enabled emulator and completed
  the release-58 billing and mentor-course verification described below. See
  [review access](REVIEW_ACCESS.md).
- At the earlier billing checkpoint, Google Cloud showed no linked billing account. The free-trial
  signup is open for the Rokn account with Egypt selected, optional marketing
  unchecked, and an advertised $300 / 90-day offer with no automatic charges.
  With the owner's confirmation, `Agree & continue` advanced to payment
  verification. Google selected the existing Egyptian organization payments
  profile and an existing Mastercard. After explicit owner confirmation to use
  the saved card for the free trial, `Start free` was clicked. Google then displayed
  `One-time prepayment required`: this payment method requires a USD 30 prepayment
  before the trial becomes active, refundable on closing the Cloud billing
  account. The owner subsequently explicitly authorized this USD 30 payment.
  The exact amount and saved Mastercard were verified on the payment review
  screen and `Pay now` was submitted once. CIB's 3-D Secure challenge then requested
  a six-digit SMS code for Google / USD 30.00. Charge and trial activation were
  not established at that checkpoint; the confirmed result is recorded below.
  Do not submit another payment.
  No paid upgrade was selected in that flow. This pending-payment observation
  was superseded by the September 13 follow-up below.

### Cloud activation and tax follow-up (September 13)

- Google Payments emailed confirmation that the USD 30 prepayment succeeded
  and was received for billing account `019AE0-CF89EB-504632`. The Cloud console
  subsequently displayed an active free trial with USD 300 credit and 90 days
  remaining, with an Upgrade button still offered. No second payment was made.
- Firebase separately emailed that linking the Cloud billing account switched
  the project to its Blaze plan. This is not evidence that the Cloud free-trial
  billing account itself was upgraded to an unrestricted paid account.
- Egypt tax info asks for TRN and UIN, not company-document uploads. No tax form
  was submitted. The owner explicitly deferred UIN completion and requested
  continuation of store setup. Do not enter the commercial-register unified
  number in the UIN field or mark tax verification complete. No observed Play
  rejection or review blocker has been attributed to the missing UIN.
- An authenticated taxpayer portal account is accessible, but adding the
  company's official correspondence settings returned a duplicate tax-ID
  error. No company or previous non-core linking request appears in that
  account. This does not establish which other account, if any, holds the ID.
  Mail searches found today's account-verification email, not a UIN or prior
  correspondence-confirmation message. No tax filing or new taxpayer
  registration was submitted.

### Google Play notification transport (September 13 continuation)

- Created topic `projects/rokn-production-2026/topics/rokn-play-rtdn` without
  a default subscription, exports, transforms or topic-level retention.
- After explicit action-time confirmation, granted
  `google-play-developer-notifications@system.gserviceaccount.com` the
  `Pub/Sub Publisher` role on this topic only. The Console confirmed
  `Policy updated`; no project-wide administrator access was granted.
- Created the authenticated push subscription
  `rokn-play-rtdn-push` with endpoint
  `https://rokn.app/api/store-notifications/google`. The route prefix was
  checked in RouteServiceProvider; this endpoint is outside the `/api/v1`
  course API group. Payload unwrapping remains off.
- Created the dedicated service identity `rokn-play-rtdn-push` after the owner's
  action-time confirmation. The Console confirmed creation and lists it enabled
  with no keys, unique ID `102220628208040821922`. No project-wide roles were
  assigned to it.
- The saved push subscription uses the same endpoint as its explicit audience,
  a 60-second acknowledgement deadline, 10-to-600-second exponential backoff,
  seven-day unacknowledged-message retention and no inactivity expiration.
  Authenticated delivery is enabled; payload unwrapping is off.
- After action-time confirmation, granted Pub/Sub service agent
  `service-112556080712@gcp-sa-pubsub.iam.gserviceaccount.com`
  `Service Account Token Creator` on the dedicated push identity only, not the
  project. Readback showed `No inheritance` on this service-account resource.
- Added only `GOOGLE_PLAY_RTDN_AUDIENCE` and
  `GOOGLE_PLAY_RTDN_SERVICE_ACCOUNT_EMAIL` to the existing production environment
  after comparing the full editor contents with the intended change. Deployment
  `204` succeeded using unchanged source `855f7293fe3e96682a87f2b79d69ba4238c98f87`.
- Saved the topic in Google Play monetization settings with subscriptions,
  voided purchases and all one-time products selected. Sent one official Play
  test notification after deployment. Production read-only command `173` at
  `2026-09-13T13:58:05Z` confirmed both expected RTDN values and event
  `21807048248284341`, received and processed at `2026-09-13T13:57:27Z`, with no
  error. Its `other` / `ignored` state is expected for a test notification: it
  proves authenticated transport without creating a purchase or granting coins.
- Read-only command `174` at `2026-09-13T13:59:36Z` verified active product
  `rokn.coins.900`, package `com.rokn`, legacy-compatible option `standard`,
  Egypt availability and EGP 11.11, matching local package 4's 900 coins.
  Guarded command `175` then enabled only that package's Google channel and
  confirmed Google configuration readiness. Prices, coin quantities, direct
  availability and Apple configuration were unchanged. No purchase, consumption,
  refund or credit to a user was performed. Native purchase testing remains open.

### Listing setup (September 13 continuation)

- Arabic name `ركن Rokn`, short/full descriptions, Education category and
  `https://rokn.app/privacy-policy` were saved without submitting for review.
- The existing Rokn icon was rendered to the required 512-by-512 RGB PNG; the
  1024-by-500 feature graphic uses that same mark and `سكرول واتعلم`. Both were
  visually inspected and uploaded successfully. Reproducible sources and checksums
  are in `mobile/store/assets/`; no synthetic app screenshot was used.
- At least two actual phone/tablet screenshots of the released candidate remain
  missing. Do not substitute the emulator's older version 36 or mockups.
- Public support email `support@rokn.app` and website `https://rokn.app` were
  saved with the Console's Publish action. This contact update did not submit
  the application for public review. Following the owner's explicit confirmation,
  Ads was saved as No and the Console outstanding count decreased to eight.
  These eight App content declarations remain incomplete: app access, content rating, target audience, Data safety,
  advertising ID, government, financial features and health. IARC terms were
  accepted with the support email and category Other apps. Its draft currently
  records no rating-relevant bundled content and yes to user-content sharing;
  the remaining exact questions/authorization and final rating were not completed
  at that checkpoint. Reusable reviewer access was also missing then; both the
  rating and reviewer-access history are superseded by the later sections below.
- The owner subsequently specified **12+**, replacing the earlier 18+ proposal,
  and authorized changing Gemini through OpenRouter if required. This has not
  been entered in the blocked Target audience form and is not a final IARC rating.
  Both [Gemini API terms](https://ai.google.dev/gemini-api/terms) and
  [Google Cloud terms, section 20(d)](https://cloud.google.com/terms/service-terms)
  restrict generative-AI use in services directed to or likely accessed by
  under-18s. [OpenRouter sections 2 and 5.2](https://openrouter.ai/terms/) also
  require clarification of downstream minors' eligibility under its own 18+
  Service rule; merely replacing Gemini is not a verified resolution. Claude
  permits minor-serving products subject to its additional safeguards and
  disclosures, but that does not establish OpenRouter permission. No runtime
  model, age gate or production setting was changed, and no provider request
  has yet been sent. See CONSOLE_SETUP.md for primary sources and next action.
  Competitors' displayed ratings do not establish their target-audience choices.
- Publishing overview contains unsent changes. The public review action is
  disabled until setup is complete; managed publishing was observed off and was
  not changed. Internal availability is not acceptance for public distribution.
- Read-only Cloud command `169` at `2026-09-13T11:05:20Z` confirmed actual
  production settings: OpenRouter provider data collection `allow`, ZDR `false`,
  backend Sentry DSN absent, Nightwatch enabled and request-payload capture off.
  No setting or secret was changed. Do not claim provider non-retention, no data
  sharing, or active backend Sentry merely from repository defaults/SDK presence.
- Earlier inspection found empty topic/subscription lists and no linked Cloud
  billing account. This historical state is superseded by the Cloud activation
  and notification transport updates above. A Play Payments profile alone is
  not a Google Cloud billing account.

### September 13 follow-up: live media billing blocker

- Signed into Bunny using the authorized `roknproduction@gmail.com` Google
  identity. The account's own overview and Billing pages explicitly show an
  expired free trial, $0 paid balance, $20 expired trial credits, $0.04 trial
  usage and no billing records. Recharge was initially disabled until billing
  information was completed. That pre-payment state is superseded below.
- Production read-only command `177` at `2026-09-13T14:39:07Z` confirms Stream
  library `739603`, CDN `vz-946c2d1a-bba.b-cdn.net` and storage zone/CDN
  `rokn-production-assets` / `rokn-production-assets.b-cdn.net`, matching the
  authenticated Bunny account. This is a production dependency, not a different
  unused trial account. Post-payment media verification is recorded below;
  this does not establish the complete candidate-app journey.
- Command `176` failed before executing PHP because the command lacked the
  `php artisan` prefix. It made no application changes; command `177` is the
  successful read-only replacement.
- After the owner's explicit confirmation, the company name and Egyptian
  billing address were saved. Bunny confirmed "Account details successfully
  updated. Saved" and enabled Recharge Account. The owner then completed
  the authorized $10 card recharge. Billing confirmed a September 13 payment
  and $10 available balance. The live tax rate now displays 14%.
  Auto-recharge remains disabled; no second payment was submitted.
- Library `739603` shows 48 videos and 13 GB storage. Its video
  `ab15c084-d9ef-4dfd-aecf-5d2f41f3b9cd` played in the embedded player and
  advanced to about 38 seconds before being paused. This is provider-player
  verification, not a candidate-57 native-device test.
- Public `/api/v1/courses/list` and course details succeeded. Fresh guest
  preview URLs for courses 3, 8, 9, 10, 13 and 14 returned valid HLS masters,
  a rendition playlist and HTTP 200 for the first media segment (HEAD).
  Paid lessons, every rendition and native playback remain unverified.
- Removed legacy demonstration course 1 (30 videos / one minute total,
  two-second guest preview) after the owner's explicit deletion approval and
  successful dashboard MFA. The dashboard first unlisted it. Since
  `AdminCourseLifecycleService::archive` only unlists published courses,
  production command 178 then soft-deleted this exact, already hidden course
  with identity checks and a transaction at 2026-09-13 18:16:38 UTC.
  Verified `soft_deleted: true`; its two orders, two enrollments and 30 lesson
  records were retained. No physical media were deleted. The operation is
  recoverable and did not change other courses. Public catalog verification
  lists real courses 3, 8, 9, 10, 13 and 14 plus five coming-soon cards; course 1
  is absent and its public details endpoint returns HTTP 404.
- The separately confirmed Google Play Advertising ID declaration was saved
  as No. Google confirmed the save; public review was not submitted.
- The owner also confirmed and Google saved three declarations: non-government,
  no health features, and rewards/points/incentives only under Financial
  features. No banking, money-transfer or cryptocurrency feature was declared;
  Google requested no additional financial documentation for this selection.
  At that checkpoint App content showed four remaining declarations. The later
  content-rating save below reduced the count to three. No public review submission.
- An earlier recheck found no saved Google Play App access declaration and required
  paid access for reviewers. This historical blocker was cleared on September 14
  by saving the final reusable login and its verified mentor entitlement.
- Generated and inspected a new campaign feature graphic using the built-in
  image generator and the existing course artwork. Preserved source and
  1024x500 opaque PNG export under `mobile/store/assets/play-feature-generated-v2*`.
  Prompt/provenance and export script are beside it. It was uploaded to the Play
  listing and classified there as AI-generated; authentic screenshots are kept
  separate.

### September 13 follow-up: content declarations and reviewer provisioning

- IARC questionnaire completed and saved in Play Console. The generated ratings
  include IARC Generic 3+, PEGI 3 and ESRB Everyone with user interaction and
  in-app purchase descriptors. These are content ratings, not approval of the
  intended 12+ audience or the AI provider's downstream-user eligibility.
  App content's outstanding count decreased from four to three: App access,
  Target audience and Data safety. No public review was submitted.
- Data safety's 17 selected data types were completed and saved as a draft.
  The expanded preview was checked, including device-ID purposes for push
  communications and marketing. Optional account/content/purchase data and
  required interactions/diagnostics/device identifiers are distinguished.
  OAuth, encryption in transit and the live account-deletion URL are included.
  Sharing exclusions rely on the actual user-initiated/explicit-consent and
  processor flows, not a claim that OpenRouter never retains data. Production
  provider data collection remains allowed and ZDR remains false. The final
  declaration was later completed after the 18+ target audience was saved.
- Production command 179 confirmed reviewer user 13 and published course 3's
  mentor plan 12, with 50 messages and normal project/chat budgets. Attempts
  180-182 to provision a complimentary full-plan enrollment all rolled back:
  the first lacked the plan-order link, the second failed the paid-contribution
  floor, and the third's zero-floor snapshot was rejected by plan validation.
  No runtime constraint was removed and no paid credits or receipts were faked.
  Read-only command 183 confirmed zero matching enrollments and zero generated
  review codes after these attempts. Historical user 13 still lacks paid-feature
  access and is not the final reviewer identity.
- At that checkpoint the next planned route was a licensed-test purchase followed
  by normal course purchase and entitlement verification. The replacement user 14
  completed that route on release 58 as recorded below. Four candidate
  store-listing screenshots were later captured from this exact release.

### September 14 completion: final Android reviewer access

- Created the replacement Google reviewer identity and registered it through the
  normal ROKN Google OAuth flow as user 14. No OTP or two-step challenge appeared
  during the verified login. Credentials are not stored in this repository.
- Added the identity to the existing `Rokn internal QA` list, now four members,
  which is selected for internal testing and license testing. The identity accepted
  the internal-test invitation for track `4700165170808444276`.
- After invitation acceptance and an app restart, release 58 resolved
  `rokn.coins.900` at EGP 11.11. Google displayed its always-approves test card and
  stated that the order was a test with no charge. The purchase succeeded and the
  app credited 900 coins, bringing the balance to 935.
- The reviewer then bought **أساسيات الرسم والتحريك في Blender** course 3,
  mentor plan 12, through the normal 900-coin app checkout. Balance became 35.
  The first lesson, mentor Ask panel and My Corner Continue entry were verified.
- Saved `Google Play reviewer account` in Play Console App access with the final
  username/password, English sign-in and navigation instructions, and full access
  to paid/premium content selected. The change is saved in Publishing overview.
  It was deliberately not sent for public review in this step.
- The remaining Play setup was subsequently completed: 18+ audience with known
  minors blocked, final Data safety answers, rewritten Arabic listing, the
  disclosed generated feature graphic, and four authentic release-58 screenshots.
- A production release using the same processed AAB was staged for a full rollout
  in Egypt. Google marks it ready and the automated checks completed without a
  reported problem; the combined changes are waiting for the final review request.

Apple's official sign-in page is open only. Organization membership, Team ID,
App Store Connect record and signed iOS archive are not verified.

Store acceptance is decided by Apple/Google after reviewing the actual binary,
configuration and listing. Passing the checks above does not guarantee approval.
