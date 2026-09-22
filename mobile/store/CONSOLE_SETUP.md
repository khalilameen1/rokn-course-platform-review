# Rokn store console setup

Updated 2026-09-14. Completed console steps are identified below; the remaining
entry sequence is not evidence that its fields were saved.
Use [release status](../docs/STORE_RELEASE_STATUS.md) for artifact
pins and remaining blockers and [review access](../docs/REVIEW_ACCESS.md) for
reviewer journeys and native product configuration.

Current checkpoint: internal release **58 (1.0.57)** is available. Command 188
verified the owner's Google licensed-test purchase and course enrollment, not
reviewer access; user 13 remains unenrolled. Play shows **7 of 11** setup tasks
complete: App access, Target audience, Data safety and Store listing are still
unfinished. Candidate phone screenshots are not yet evidenced. **Send app for
review** remains disabled; no review request or production release was submitted.
The intended 12+ audience / current AI-provider eligibility issue is unresolved.

## Account and app identity

- Company: ROKN FOR DIGITAL PRODUCTION AND CONTENT
- Organization country: Egypt
- Intended application identifier on both platforms: `com.rokn`
- Category: Education
- Public support email: `support@rokn.app`
- Website: `https://rokn.app/`
- Support: `https://rokn.app/contact`
- Privacy: `https://rokn.app/privacy-policy`
- Account/data deletion: `https://rokn.app/account-deletion`

The company Play Console account was inspected on September 13 while signed in
as Rokn. Website ownership of `https://rokn.app` was verified after the owner's
confirmed request. Both contact and developer phones were then verified using
the owner's SMS code and saved successfully. After the owner confirmed the required
declarations, the Arabic free-to-download draft `ركن Rokn` was created as app
record `4974910218344866175`. Signing enrollment is complete with the fingerprints
below. Google processed the AAB built from clean source `903a13b` as
`57 (1.0.56)`, minimum API 24 and target API 36, with ReTrace and native symbols
attached. It was initially saved as draft release `1`, then released to the two
approved internal testers on track `4700165170808444276` on September 13.
That release is now superseded by `58 (1.0.57)`, available to internal testers
on September 14 at 00:01 Cairo. Neither was submitted for public review.
The working opt-in URL is
`https://play.google.com/apps/internaltest/4700165170808444276`.
Public support email `support@rokn.app` and website `https://rokn.app` were
subsequently saved with the Console's Publish action; this did not submit the
application for public review.
Apple's official sign-in page is open, but organization membership, Team ID and
App Store Connect record have not been verified.
Do not substitute a personal developer account or another
country. Legal-address, tax, banking and identity fields must come from the
company's verified records, not placeholders in this repository.

## Android signing and internal upload

Signing observed and approved in the company Play Console on September 13;
current artifact updated September 14:

- App record: `4974910218344866175`, package `com.rokn`, title `ركن Rokn`
- App-signing SHA-256:
  `5a49ea3dba91df63f27e60fa87998737efb67657fa102ecb162bd1d63e232d9e`
- Upload-certificate SHA-256:
  `0f0cde1dc533559f6f97c0d2df4e474be764b13ad071def14c19dc1a7812586e`
- Current AAB: `58 (1.0.57)`
- Current AAB source: `6d718e28f265148df6d12c3fda1c05fce44ce390`
- Current AAB SHA-256:
  `4cc831137db744f06f9678a267e0d3619dfeac697c9ac8cc009dec917e38fe89`
- Historical release 57 source: `903a13b18938a992b177adef0905f2a2b9a06dc9`;
  SHA-256 `a62b8915efd9a5fa9ff837e6c10944c3cdca6cbecb4d4f6a92680cb366dabf5b`

The signing and upload keys have different roles. Keep private keystores and
passwords outside the repository. Preserve the direct/Play upgrade design in
[release channels](../RELEASE_CHANNELS.md); do not substitute the upload key or
the old debug key as the identity of an installed Play application.

After the owner's explicit confirmation, Firebase saved the app-signing SHA-1
`ae13b83943fe895c3944b016a95721698c53156e` and the SHA-256 above for `com.rokn`
in `rokn-production-2026`. The production backend's Google web client belongs to
the same project number, `112556080712`. The current mobile authentication flow
uses the backend browser flow; this is not evidence of a tested native login.
Laravel Cloud applied the saved app-signing SHA-256 alongside the existing direct
certificate in deployment `202` / `3c72607` on September 13. The post-deployment
Android association response contains both certificates, not the upload key.
No other environment value was intentionally changed for this association update.

Independent static inspection of the historical release 57 AAB passed bundletool validation,
jarsigner verification, upload-certificate matching, `PAGE_ALIGNMENT_16K`, all
46 ELF64 / 137 LOAD alignment checks and RELRO checks. A derived inspection APK
passed 16 KB zip alignment and APK signature verification with a temporary
debug key; it is not distributable. The evidence location and remaining runtime
checks are recorded in [release status](../docs/STORE_RELEASE_STATUS.md).

Remaining steps:

1. Complete reviewer-device verification on internal release `58 (1.0.57)`
   through Play using an approved tester account. The owner's release 58 purchase
   is verified, but does not establish reviewer access or public-review approval.
2. Preserve the registered Firebase fingerprints and the existing server/web
   client identity used by sign-in.
3. Preserve the deployed `APP_LINK_ANDROID_SHA256_FINGERPRINTS` association:
   the existing direct certificate remains alongside the new app-signing certificate.
4. Verify a Play-installed build can sign in and open course links. Successful
   association responses and local signing are not native-device verification.

Observed on 2026-09-12: both hosts return HTTP 200 but advertise only
`01:97:0F:4D:0A:A5:9B:F4:D8:F4:DE:FB:CA:7C:B8:77:34:6D:69:BF:B7:15:A5:B6:4F:A6:DC:D2:73:3F:89:3D`.
After deployment `202` on September 13, the association was verified with that
existing fingerprint and
`5A:49:EA:3D:BA:91:DF:63:F2:7E:60:FA:87:99:87:37:EF:B6:76:57:FA:10:2E:CB:16:2B:D1:D6:3E:23:2D:9E`.
The upload-certificate fingerprint is not an installed-app association identity.
Signing behavior is documented by [Android](https://developer.android.com/studio/publish/app-signing).

## Google billing checkpoint

The service account is Active with only the four approved `com.rokn` permissions:
app read, app-quality information, financial-data view and order/subscription
management including refunds. No account-wide administrator or release permission
was granted. The production credential is deployed and `rokn.coins.900` is Active,
bound to package 4, with one backward-compatible buy option at EGP 11.11 in Egypt.
Cloud command `170` verified OAuth, identity and the product read, but its
voided-purchase GET returned HTTP 401. The fresh probe `171` at
`2026-09-13T11:22:10Z` confirmed `androidpublisher:permissionDenied` for purchase
access while OAuth succeeded. Command `172` at `2026-09-13T12:23:52Z` then
returned both OAuth and purchase-read success using the same permission scope.
The earlier denial no longer reproduces; its cause is not established.
Command `174` reverified product/price on September 13 at 13:59:36 UTC and
command `175` enabled package 4's Google channel after authenticated RTDN evidence.
At that September 13 checkpoint, native purchase, consumption and refund were
unverified. Command 188 below supersedes the purchase/consumption status only.

Authenticated RTDN is configured with topic
`projects/rokn-production-2026/topics/rokn-play-rtdn` and push subscription
`rokn-play-rtdn-push`. Endpoint and audience are
`https://rokn.app/api/store-notifications/google`; push identity is
`rokn-play-rtdn-push@rokn-production-2026.iam.gserviceaccount.com` with no keys.
Publisher access is topic-scoped and Pub/Sub token-creator access is scoped to
the push identity. Cloud trial billing is active; no unrestricted paid upgrade
was selected. Egypt UIN completion was explicitly deferred, not falsely completed.
Deployment 204 applied the two RTDN environment values. Play's official test
event `21807048248284341` was authenticated, recorded and processed without
error on production at 13:57:27 UTC, confirmed in read-only command 173. Its
`ignored` status is expected because a test does not create an order or coins.
The internal and license test lists both contain the three approved accounts.
Read-only command 188 at 00:18:53 Cairo on September 14 verified user 6's
Google purchase 1 / order 17 for `rokn.coins.900`: environment `test`, credited,
approved/settled, finalized and zero cash revenue, with no reversal or retry.
Course order 18 created active enrollment 8 in course 10, mentor plan 27 with
chat enabled, 50 messages and certificate eligibility. This followed the owner's
purchase on Play release 58. Reviewer user 13 still has no enrollment; duplicate
credit protection, repeat purchase and refund/recovery are not established by
this one successful purchase or by notification transport alone.

## Apple identity and links

Verify the organization's active membership, Team ID and existing `com.rokn`
App ID. Enable Sign in with Apple for that identity and configure backend
`APPLE_CLIENT_ID`, `APPLE_TEAM_ID`, `APPLE_KEY_ID` and `APPLE_KEY_FILE` for code
exchange and revocation. The `.p8` file must be a protected readable server
file. These login keys are not interchangeable with `APPLE_STORE_*` billing keys.

Set `APP_LINK_APPLE_APP_IDS` to the verified `TEAMID.com.rokn` value and check
the AASA response on both hosts. The main host's
`/.well-known/apple-app-site-association` returned HTTP 404 on 2026-09-12;
this is not configured yet. Then sign/build `production-ios` with that team
and inspect the actual archive before creating the TestFlight review build.

## Listing and privacy entry

Use [listing drafts](listing/README.md) for field mapping and validated text
lengths. Confirm the name is available before saving either locale.
The rejected Arabic descriptor was removed from the draft. `ركن Rokn` remains
the neutral working title of the created draft until the final store name is
chosen; no proposed descriptor from the naming discussion has been submitted.

The public policy/contact/deletion URLs returned HTTP 200 again after deployment
`202` on 2026-09-13. This proves page availability, not completion of an account-
deletion request; exercise that flow before submission.

The earlier September 13 checkpoint had eight incomplete App content declarations
and an unfinished IARC draft using `support@rokn.app`, category Other apps and
user-content sharing. Later saves below supersede that count: IARC is complete,
and App access, Target audience and Data safety are the three outstanding content
declarations. Data safety's 17 types are saved as a draft. Store listing is the
fourth unfinished setup task. Reusable reviewer sign-in and paid-feature access
remain unverified.

Do not answer "no data collected". Account/profile data, learning and purchase
records, submitted messages/media/documents, support requests and operational
data leave the device. Code references include `src/services/sentryTelemetry.ts`,
`src/services/productAnalytics.ts`, `ios/Rokn/PrivacyInfo.xcprivacy` and
`backend/resources/lang/en/privacy.php` (from the repository root).

- Google: distinguish collected from shared and assess applicable provider
  exceptions against actual provider terms/configuration. Saved AI messages
  and reports are not ephemeral. Include uploaded files/documents separately
  from photos/videos and in-app messages. Account IDs and correlated crashes
  are not anonymous merely because names/emails are scrubbed.
- Apple: mirror actual data types, linkage and purposes in App Privacy.
  Operational diagnostics and product analytics are not the same purpose.
  First-party marketing is distinct from tracking; any transfer for third-party
  retargeting requires a fresh assessment before enabling it.
- Answer target audience and the age-rating questionnaire for the actual
  catalog and enabled AI/user-content features. Do not select Kids/Families,
  "no user content" or an age rating simply because it looks easier to approve.

The owner explicitly selected an intended audience of **12 and above** and
authorized replacing Gemini through OpenRouter if necessary. This supersedes
the earlier proposed 18+ audience. No target-audience selection has been saved;
IARC's saved Generic 3+ content rating is separate from intended-age eligibility.
[Gemini API terms](https://ai.google.dev/gemini-api/terms) and
[Google Cloud terms, section 20(d)](https://cloud.google.com/terms/service-terms)
restrict generative-AI use in services directed to or likely accessed by under-18s;
[OpenRouter terms sections 2 and 5.2](https://openrouter.ai/terms/) also set an
18+ Service eligibility rule and require customers' use to comply with that
agreement and provider terms. Do not assume an adult company account exempts
12-year-old downstream students without written clarification from OpenRouter.
Switching model IDs alone does not resolve this. No runtime model, consent,
age-gate or production setting has been changed for the audience decision.

Claude is a candidate, not an approved rollout: [Anthropic's Usage Policy](https://www.anthropic.com/legal/aup)
explicitly addresses products serving minors and requires additional safeguards
and AI disclosure at the beginning of each chat session. Existing project-review
use does not establish compliance for younger users. [OpenAI's API guidance](https://developers.openai.com/api/docs/guides/safety-checks/under-18-api-guidance)
also supports minor-serving applications with safeguards, but requires zero data
retention before processing personal data of children under 13 or the applicable
digital-consent age. Neither alternative settles OpenRouter's own eligibility.

Next provider clarification: can Rokn's adult-owned company account use the API
in an educational app whose end customers are 12+, including student messages
and project attachments? Request the applicable agreement and allowed model /
inference-provider route in writing. Do not send student data, credentials or
private projects with that question. No clarification request has been sent yet.

The owner subsequently directed keeping the intended audience at 12+ and
continuing preparation without contacting OpenRouter or changing the current
service. Record this as an owner instruction, not evidence of provider permission
or store approval. No provider clarification request will be sent under that
instruction. The eligibility issue above remains unresolved.

Console update on 2026-09-13: after the owner explicitly confirmed no ads, the
Ads declaration was saved as No. Google displayed its saved-change confirmation,
and the App content outstanding count decreased from nine to eight. This was a
saved declaration, not submission for public review. Source inspection found no
ad-network dependency or ad display integration in the current mobile package,
Android manifest and source. The separate Advertising ID declaration was then
incomplete and was subsequently saved as No, as recorded below; its answer was
not inferred merely from having no visible ads.

Target audience still requires completion of App access. Its live form says
reviewers cannot create accounts or make purchases for access. No answer has
been entered into that form and no reusable reviewer login with access to paid
features has been provisioned. Do not select unrestricted access for this app.

Before a rollout, align the approved route across chat, guest chat, project
review/report/follow-up, queued work and fallback models. Check web-search and
file-parser processors separately; inference ZDR does not cover these tools.
[Google distinguishes target audience from content rating](https://support.google.com/googleplay/android-developer/answer/9867159?hl=en),
so a competitor's displayed rating does not establish its chosen target audience.

Use the platform definitions, not interchangeable checkbox answers:
[Google Data safety](https://support.google.com/googleplay/android-developer/answer/10787469)
and [Apple App Privacy](https://developer.apple.com/app-store/app-privacy-details/).
Runtime command `169` confirmed provider data collection `allow`, ZDR `false`,
backend Sentry not configured, Nightwatch enabled and request-payload capture off.
The outstanding declarations remain incomplete; do not replace these observations
with SDK defaults or treat the saved Data safety draft as a submission.

## Visual assets and review evidence

### September 13: three additional declarations saved

With explicit owner confirmation, Google saved Government apps = No,
Health features = None, and Financial features = Rewards, points, frequent
flier miles and other incentives only. Rokn's closed-loop rewards are not
declared as banking, transferable money or cryptocurrency. Google requested
no additional financial documents for that selection. The App content page
then showed four outstanding declarations: App access, Content ratings,
Target audience and Data safety. These saves did not submit public review.

### September 13: Advertising ID declaration saved

The actual `mobile/artifacts/Rokn-play.aab` was rehashed as
`a62b8915efd9a5fa9ff837e6c10944c3cdca6cbecb4d4f6a92680cb366dabf5b`.
Its embedded `base/manifest/AndroidManifest.xml` (38,295 bytes) contains neither
`com.google.android.gms.permission.AD_ID` nor
`android.permission.ACCESS_ADSERVICES_AD_ID`. It also contains neither
`READ_MEDIA_IMAGES` nor `READ_MEDIA_VIDEO`. The generated packaged release
manifest identifies version 57 / 1.0.56, target 36 / minimum 24. Source and
package review found no advertising-ID integration. The appropriate Advertising
ID answer is **No**, independently of the already saved No-ads answer.
After the owner's explicit confirmation, the live Advertising ID answer was
saved as **No**. Google confirmed the save and directed submission through
Publishing overview. This is not an app submission or review approval.

Additional Data safety preparation from current implementation:

- Account names, email and user identifiers are collected for account and app
  functionality. Guest browsing is supported; optional-vs-required must follow
  Google's definition rather than the fact login is required for a purchase.
- Purchase history, coin balances and order references are collected. Rokn does
  not itself hold full card numbers or security codes; payment-provider handling
  must be assessed separately rather than equating purchase history with cards.
- Photos/videos, files/documents, in-app messages and other user content need
  separate consideration: uploads, learning questions, project reports,
  portfolios and support messages cannot all be labeled merely "profile data".
  Persisted messages/review results are not ephemeral processing.
- App interactions are collected for analytics and functionality, including
  guests. `productAnalytics.ts`, `ProductEventController::EVENT_FIELDS` and
  `ProductEventService::record` show event/session identifiers, course/lesson
  references and progress milestones. Guest pseudonyms can become linked to
  accounts; these records are not anonymous. The product-event schema does not
  accept raw search text, but this does not establish that the separate course
  search request or infrastructure logs never process search text.
- Diagnostics and device/other identifiers need declaration. Mobile Sentry
  removes the request/extra fields and retains a user ID where set. Sanitizing
  names or bearer strings does not make a correlated error anonymous. Backend
  Sentry being absent does not mean the mobile SDK is inactive.
- Do not infer birthday/gender collection from the legacy `ProfileRequest`:
  current `ProfileController::update` uses inline validation, not that request
  class. It accepts name, professional heading, image and preferences, prohibits
  direct phone/email edits and does not accept birthday/gender updates. WhatsApp
  linking is a separate source of phone data and still needs accounting.
- AI disclosure in `src/services/aiConsent.ts` names OpenRouter/model providers,
  attachments and course/conversation context; its acceptance is stored on the
  server and revocable. Assess Google's disclosure/user-initiated transfer
  exception against this actual interaction, not simply against a privacy-policy
  link. Provider `data_collection=allow` and `zdr=false` remain the confirmed
  production settings; do not claim non-retention or a service-provider exception
  merely because a processor is an API vendor.
- The release network config rejects cleartext traffic. This supports app-server
  transport protection but does not alone prove every backend provider transfer
  is encrypted. Do not certify a separate independent security review.

These were preparation notes. The later September 13 live follow-up completed
all 17 selected Data safety types and saved the draft; the expanded preview was
checked. Final submission remains blocked by Target audience. No independent
security certification or provider non-retention claim was made. The sharing
answers depend on actual processor and user-initiated/explicit-consent exceptions.
IARC was also completed and saved with generated Generic 3+ / PEGI 3 / ESRB
Everyone ratings, distinct from the unresolved intended 12+ audience. App access,
Target audience and Data safety remain the three outstanding declarations.
Source of field definitions: [Google Data safety](https://support.google.com/googleplay/android-developer/answer/10787469?hl=en),
read September 13. No binary or runtime code was changed during this inspection.

The iOS marketing icon at
`ios/Rokn/Images.xcassets/AppIcon.appiconset/ItunesArtwork@2x.png` is a verified
1024-by-1024 RGB PNG without an alpha channel. The Expo source icon is RGBA;
do not substitute it blindly for that prepared iOS asset.

The existing brand icon and feature graphic have been rendered, visually checked
and saved to the Play listing. Reproducible sources are in [assets](assets/README.md).
Store screenshots are not ready. Capture the actual store candidate with
review-ready content and working purchases, not screenshots from an older
debug APK or a mockup. Supply the phone/tablet sizes requested by each console
and the Play icon/feature graphic at its current accepted dimensions. Check
[Google asset requirements](https://support.google.com/googleplay/android-developer/answer/9866151)
and [Apple screenshot sizes](https://developer.apple.com/help/app-store-connect/reference/app-information/screenshot-specifications/).

Upload to internal testing/TestFlight first. Record the accepted build identity,
reviewer access and native purchase evidence. Keep public release/manual review
submission separate from successfully uploading a binary. Do not promote while
the blockers in the release-status file remain unresolved.
