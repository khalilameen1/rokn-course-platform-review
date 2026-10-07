# Upload the existing signed iOS build

`apple-upload.yml` is manual only. It uploads an existing, approved IPA; it does
not rebuild, publish an App Store version, submit review, or distribute to testers.

## Actual reuse

- Apple's existing Xcode `altool` performs validation, API-key authentication,
  binary transfer and upload error handling. We did not write those protocols.
- GitHub's existing `gh run download` retrieves the signed artifact.
- Rokn-specific glue binds the successful build run, source commit, immutable IPA
  hash and bundle identity, and rejects mobile changes since that build.
- Apple documents altool as an App Store upload tool:
  https://developer.apple.com/help/app-store-connect/manage-builds/upload-builds/
- The established Tauri release guide uses the same iOS altool/API-key path:
  https://tauri.app/distribute/app-store/
  This is corroboration of an existing integration, not a claim that we copied
  Tauri application code or verified its adoption from stars alone.

## Authentication

The team API key is Developer, not Admin. Team keys cover the organization's
apps; they are not app-scoped. Store the `.p8`, key ID and issuer ID only in
`ROKN_APPSTORE_CONNECT_API_KEY_P8`, `ROKN_APPSTORE_CONNECT_API_KEY_ID` and
`ROKN_APPSTORE_CONNECT_API_ISSUER_ID` Actions Secrets, never in repository files.
Only the upload step receives these secrets. It creates a private temporary key
file and removes it on exit, with an always-run cleanup as well.

## Acceptance

A successful job proves successful validation and the upload command. Apple
processing and actual TestFlight availability require separate live evidence.
The job requires a nonempty Apple JSON `success-message` and no `product-errors`,
not just exit zero: actual Xcode 26 false-success reports were identified in
https://github.com/fastlane/fastlane/issues/29739. Unknown/malformed output fails
the gate rather than claiming acceptance. This is Rokn integration checking of
the vendor response, not copied Fastlane source or a replacement uploader.
Existing signed-artifact evidence is not rewritten to claim native acceptance.
If a job's upload outcome is uncertain, inspect App Store Connect before retrying
the same version/build number. Do not rebuild merely because observation timed out.
