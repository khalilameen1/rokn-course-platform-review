# Rokn store console setup

Prepared 2026-09-12. This is the entry sequence, not evidence that console fields
were saved. Use [release status](../docs/STORE_RELEASE_STATUS.md) for artifact
pins and remaining blockers and [review access](../docs/REVIEW_ACCESS.md) for
reviewer journeys and native product configuration.

## Account and app identity

- Company: ROKN FOR DIGITAL PRODUCTION AND CONTENT
- Organization country: Egypt
- Application identifier on both platforms: `com.rokn`
- Category: Education
- Public support email: `support@rokn.app`
- Website: `https://rokn.app/`
- Support: `https://rokn.app/contact`
- Privacy: `https://rokn.app/privacy-policy`
- Account/data deletion: `https://rokn.app/account-deletion`

Inspect the existing company records before creating anything. The current
Google sign-in session is expired and Apple's organization enrollment has not
been verified. Do not substitute a personal developer account or another
country. Legal-address, tax, banking and identity fields must come from the
company's verified records, not placeholders in this repository.

## Android signing before the first upload

1. Open the `com.rokn` record in the company Play Console and inspect App signing.
   If no record exists, complete the company account and app-creation steps first.
2. For first enrollment, preserve the direct/Play upgrade design in
   [release channels](../RELEASE_CHANNELS.md). Use the existing compatible
   app-signing key if present and suitable. Never choose the old debug key as
   a permanent production identity. The new local upload certificate is not
   automatically the installation certificate.
3. Compare the registered upload certificate to the AAB sidecar before upload.
   Keep the private keystore and passwords outside the repository.
4. Record the actual **app-signing** SHA-1 and SHA-256 from Play. Register the
   installed-app certificate with the matching Google OAuth/Firebase Android
   app (`com.rokn`). Preserve the server/web client identity used by sign-in.
5. Add the actual app-signing SHA-256 to backend
   `APP_LINK_ANDROID_SHA256_FINGERPRINTS`. Keep any still-supported direct
   certificate; do not replace every entry with the upload certificate.
6. Check both hosts' `/.well-known/assetlinks.json`, then verify a Play-installed
   build can sign in and open course links. Local signing success is not this
   verification.

Observed on 2026-09-12: both hosts return HTTP 200 but advertise only
`01:97:0F:4D:0A:A5:9B:F4:D8:F4:DE:FB:CA:7C:B8:77:34:6D:69:BF:B7:15:A5:B6:4F:A6:DC:D2:73:3F:89:3D`.
No actual Play app-signing fingerprint has been read from the console yet.
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

The three public support/privacy/deletion URLs above returned HTTP 200 on
2026-09-12. That does not prove the deletion request completes or the newly
edited server policy is deployed. Verify both before submission.

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
