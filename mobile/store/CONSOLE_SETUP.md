# Rokn store console setup

Updated 2026-09-13. Completed console steps are identified below; the remaining
entry sequence is not evidence that its fields were saved.
Use [release status](../docs/STORE_RELEASE_STATUS.md) for artifact
pins and remaining blockers and [review access](../docs/REVIEW_ACCESS.md) for
reviewer journeys and native product configuration.

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
attached. It was saved to internal track `4700165170808444276`, draft release
`1`. The accepted upload has not been released to testers or submitted for
public review.
Apple's official sign-in page is open, but organization membership, Team ID and
App Store Connect record have not been verified.
Do not substitute a personal developer account or another
country. Legal-address, tax, banking and identity fields must come from the
company's verified records, not placeholders in this repository.

## Android signing and internal upload

Observed and approved in the company Play Console on September 13:

- App record: `4974910218344866175`, package `com.rokn`, title `ركن Rokn`
- App-signing SHA-256:
  `5a49ea3dba91df63f27e60fa87998737efb67657fa102ecb162bd1d63e232d9e`
- Upload-certificate SHA-256:
  `0f0cde1dc533559f6f97c0d2df4e474be764b13ad071def14c19dc1a7812586e`
- Current AAB source: `903a13b18938a992b177adef0905f2a2b9a06dc9`
- Current AAB SHA-256:
  `a62b8915efd9a5fa9ff837e6c10944c3cdca6cbecb4d4f6a92680cb366dabf5b`

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

Independent static inspection of this exact AAB passed bundletool validation,
jarsigner verification, upload-certificate matching, `PAGE_ALIGNMENT_16K`, all
46 ELF64 / 137 LOAD alignment checks and RELRO checks. A derived inspection APK
passed 16 KB zip alignment and APK signature verification with a temporary
debug key; it is not distributable. The evidence location and remaining runtime
checks are recorded in [release status](../docs/STORE_RELEASE_STATUS.md).

Remaining steps:

1. Complete internal-testing setup before releasing saved draft `1` to testers.
   Google has accepted the upload, not reviewed or approved the public release.
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

Use the platform definitions, not interchangeable checkbox answers:
[Google Data safety](https://support.google.com/googleplay/android-developer/answer/10787469)
and [Apple App Privacy](https://developer.apple.com/app-store/app-privacy-details/).
Provider/account-level settings and final declarations remain unverified.

## Visual assets and review evidence

The iOS marketing icon at
`ios/Rokn/Images.xcassets/AppIcon.appiconset/ItunesArtwork@2x.png` is a verified
1024-by-1024 RGB PNG without an alpha channel. The Expo source icon is RGBA;
do not substitute it blindly for that prepared iOS asset.

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
