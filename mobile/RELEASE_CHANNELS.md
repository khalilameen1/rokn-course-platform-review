# Release channels and in-place upgrades

Rokn has one Android application identity (`com.rokn`) and one iOS bundle
identity (`com.rokn`). A channel changes distribution and store behavior; it
must not create a second application or a second user-data silo.

## Android artifacts

| Profile                            | Artifact                 | Signer                               | Purpose                                |
| ---------------------------------- | ------------------------ | ------------------------------------ | -------------------------------------- |
| `preview-direct` / `apk:test`      | `Rokn-internal-test.apk` | Android debug certificate            | Internal devices and emulators only    |
| `production-direct` / `apk:direct` | `Rokn-direct.apk`        | Rokn application-signing certificate | Public direct download from `rokn.app` |
| `production-play` / `aab:play`     | `Rokn-play.aab`          | Google Play upload certificate       | Google Play submission                 |

The debug APK is intentionally not public-distribution eligible. Sideloaded
applications are scanned by Android and Play Protect, and a fresh debug signer
has no public reputation. Do not rename that artifact and present it as the
public APK.

The direct APK must be signed by the certificate Android sees on Play installs.
When Play App Signing is enabled, this is the **app-signing certificate**, not
merely the AAB upload certificate. For a new enrollment that must support both
channels, provide Rokn's own app-signing key to Play and retain its protected
local copy. Google does not offer a private-key download after enrollment;
do not rely on exporting a Google-generated app-signing key later. Inspect any
existing enrollment before choosing a key. If its private key is unavailable
for the direct build, a differently signed direct APK cannot update the Play
installation in place. Downloading a Play-signed APK does not turn its store
billing channel into the distinct direct channel.

See [Android signing guidance](https://developer.android.com/studio/publish/app-signing#app-signing-google-play)
and [console setup](store/CONSOLE_SETUP.md) before accepting first enrollment.

Set `ROKN_ANDROID_APP_SIGNING_SHA256` in the production-direct build environment
to the public SHA-256 fingerprint of that certificate. The direct build fails
unless its actual signer matches. The same fingerprint must be included in the
backend `APP_LINK_ANDROID_SHA256_FINGERPRINTS` value so Android App Links verify
the installed production app.

`versionCode` is one monotonically increasing Android sequence across direct
and Play channels. Never reuse or lower it when moving a user between channels.
The admin may have separate update URLs, but creating an older channel release
is rejected.

Use the checked-in in-place installer for device testing:

```powershell
npm run android:install-artifact -- ./artifacts/Rokn-direct.apk
```

It verifies `com.rokn`, the APK signature, provenance when present, the installed
signer, and `versionCode`, then runs only `adb install -r`. It never uses
`-d`, clears application data, or uninstalls the existing package. A signer
mismatch or downgrade stops before mutation.

## iOS artifact

`production-ios` is the App Store profile. EAS must sign the archive for the
same Apple team and `com.rokn` bundle identifier used by the existing store
record. `buildNumber` is monotonically increasing; `version` is shared with the
native Xcode target. iOS has no public direct-install channel in this project.

The Apple Team ID plus bundle ID belongs in backend `APP_LINK_APPLE_APP_IDS` so
Universal Links verify both `rokn.app` hosts. App Store upgrades preserve the
application container; unsupported downgrades are never offered.

## Updating an obsolete build

The version-policy request is deliberately anonymous and cannot invalidate a
stored session. A forced-update screen blocks unsupported application code, not
account restoration. Updating in place keeps secure credentials, preferences,
and progress caches; server progress remains authoritative after launch.

Before publishing any production artifact:

1. Increment `version` and the platform internal number in `app.json` and the
   corresponding native project.
2. Build from a clean reviewed commit with the appropriate production profile.
3. Verify the generated provenance sidecar and archive symbols.
4. For direct Android, verify the pinned application-signing fingerprint.
5. Create a new admin release row; do not edit the identity of an old release.
6. Test the real in-place update from the last published artifact without
   uninstalling or clearing application storage.

## Store-review device matrix

The static release gate prevents orientation locks broad Android permissions
and drift between Expo and native iOS privacy metadata. Before submission run
the same signed candidate through this small manual matrix because simulators
cannot prove screen-reader focus or system permission behavior:

1. Phone tablet and foldable-size windows in portrait and landscape including
   one rotation while a sheet and the native checkout are open.
2. The largest OS font size with RTL enabled across login home course details
   wallet player profile settings and every purchase or permission sheet.
3. TalkBack and VoiceOver traversal with reduced motion enabled confirming one
   modal focus scope labeled icon controls and no focus behind an open sheet.
4. Keyboard open close and back behavior in code coupon chat saved-list profile
   portfolio feedback and support inputs.
5. First refusal permanent refusal and later revocation for notifications and
   the system photo picker. Rokn must not request camera microphone location or
   broad storage access.
6. Google Play Data safety and App Store privacy answers must cover the actual
   app, backend and provider data flows as well as `ios/Rokn/PrivacyInfo.xcprivacy`.
   Do not classify account-linked diagnostics as anonymous or declare every
   purpose as app functionality. Retained product analytics and optional
   marketing require their corresponding purposes. Apple's and Google's
   purpose definitions differ; a native manifest is not a completed console
   questionnaire. Recheck provider use and production marketing integrations
   before answering the separate tracking/sharing questions.
