# Android release contract

Rokn has two deliberately separate distribution channels and one test profile.
The build scripts make the differences explicit so a cached JavaScript bundle,
debug key, or development API cannot leak into a store artifact.

## Commands

| Command | Output | Signing | Quality gates |
| --- | --- | --- | --- |
| `npm run apk:test` | `artifacts/Rokn-internal-test.apk` | Debug key | Android compilation |
| `npm run apk:direct` | `artifacts/Rokn-direct.apk` | Direct application-signing key | TypeScript, ESLint, Jest, Android lint/unit tests |
| `npm run aab:play` | `artifacts/Rokn-play.aab` | Google Play upload key | TypeScript, ESLint, Jest, Android lint/unit tests |

`npm run apk` is a convenience alias for the installable test APK. `npm run
apk:play` is a compatibility alias for the Play AAB; Play never receives an APK
from this pipeline.

The test APK includes `armeabi-v7a`, `arm64-v8a`, and `x86_64` so it covers the
Android 7+ device floor and the common emulator. Set
`ROKN_ANDROID_ARCHITECTURES` only when a test device needs a different ABI. The
direct production APK includes both ARM ABIs, and the Play bundle includes all
supported ABIs for Play-managed delivery.

## Production environment

Set `EXPO_PUBLIC_API_URL` in the process environment or in the ignored
`.env.production` file. The value must be an absolute HTTPS URL. The build stops
before Gradle when it is missing or points at a local/example host.

The script injects `EXPO_PUBLIC_DISTRIBUTION_CHANNEL` and
`EXPO_PUBLIC_BUILD_PROFILE`. Do not put either value in an environment file.

## Release signing

Keep these values in `%USERPROFILE%\.gradle\gradle.properties` or inject them
from CI. Never commit the keystore or passwords.

```properties
UPLOAD_STORE_FILE=C:/secure/rokn-upload.keystore
UPLOAD_STORE_PASSWORD=...
UPLOAD_KEY_ALIAS=...
UPLOAD_KEY_PASSWORD=...
```

For `apk:direct`, these variables must identify the exact private key whose
certificate signed the previous public direct APK. The Gradle property names are
historical: a Google Play **upload key** is not automatically the direct
application-signing key. Creating a new keystore, or substituting the debug or
Play upload key, produces an APK that Android cannot install over the existing
application.

If the direct signing credential is managed by EAS, use the existing
`production-direct` project credential or recover that same credential into
protected external storage. Do not let EAS generate a replacement. Before a
release, set `ROKN_ANDROID_APP_SIGNING_SHA256` in the build process to the
certificate fingerprint of the last public direct APK. The script rejects a
different signer without printing or storing any private-key material.

Production and Play configuration fails if any value is missing, incomplete,
or the keystore does not exist. A production APK is also checked after the build
and rejected if its certificate is the Android debug certificate. The Play AAB
is verified with the JDK signer as well. Production artifacts can only be built
from a clean Git tree; local investigations use `npm run apk:test`.

When a previous APK has no provenance sidecar, retain it as the signer reference
until the credential is recovered. Verify an in-place update from that installed
APK; never uninstall it merely to hide a certificate mismatch. APKs signed by
different certificates are separate Android lineages and cannot update one
another.

## Diagnostics and symbol files

Every successful build writes a sidecar JSON file containing the version,
channel, profile, commit, size, artifact SHA-256, signer SHA-256, and the exact
API base with both base and path hashes. Production builds also copy
the Hermes source map and R8 mapping into `artifacts/<artifact>-symbols/`. Archive
the artifact, its JSON file, and the symbol directory together for each release.

JDK 17 is required by the React Native Gradle plugin. The script uses the local
`.jdk17` cache when present, then `JAVA_HOME`. It never rewrites installed files
inside `node_modules`.

Build temporary files use the ignored workspace `.cache/android-tmp` directory;
Jest uses `.cache/jest`. Moving the workspace to an SSD therefore moves these
caches with it instead of continuing to fill the Windows system drive. The build
script changes `TEMP` and `TMP` only for its own process and child processes.
