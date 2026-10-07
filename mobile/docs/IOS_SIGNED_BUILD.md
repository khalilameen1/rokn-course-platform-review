# Signed iOS build

The existing `Mobile quality gates` workflow has an opt-in signed job. Ordinary
push/PR checks and the unsigned `ios_only` validation retain their previous
behavior. No backend deployment, Google Play change, Apple account management or
App Store upload is part of this job.

## Reference and actual reuse

The temporary-keychain/import/partition-list/cleanup sequence is adapted from
[GitHub's signing example](https://docs.github.com/en/actions/how-tos/deploy/deploy-to-third-party-platforms/sign-xcode-applications).
This is a signing integration reference, not a claim of access to another
application's private code or evidence of its UX success. The project's existing
CocoaPods `xcodeproj` 1.27.0 library is reused to configure only Rokn's Release
application target. The same pinned Node/npm/Ruby/Bundler/CocoaPods toolchain that
already passed the unsigned iOS device build is retained.

Xcode 26 profiles are installed in `Library/Developer/Xcode/UserData/Provisioning
Profiles`, with the legacy location also populated. This follows the current
[fastlane signing implementation guidance](https://github.com/fastlane/fastlane/blob/master/fastlane/lib/fastlane/actions/docs/sync_code_signing.md).
No new signing dependency or Apple management API key is required.

## Dispatch

Use the correct `khalilameen1/rokn-course-platform-review` repository and the
intended release branch. Set `ios_only=true`, `build_signed_ios=true`, and
`expected_commit` to the full commit SHA. The job rejects a different checkout
before importing signing secrets. Signed execution has separate, non-cancelling
concurrency from ordinary checks and unsigned iOS validation.

The existing full-history source audit runs on Ubuntu before the macOS job can
start, against the same required commit. This reuses the normal JavaScript
quality gate's scanner and hosted platform (successful run `34903448286`). Two
diagnostic attempts observed macOS `git grep` being SIGKILLed at its first signature;
limiting its workers did not resolve that failure. The audit is not waived,
replaced with a tree-only check, or repeated on macOS. No signing secrets are
available to the source-audit job. A failed audit prevents signing from starting.

The first Ubuntu attempt (`37560558518`) also terminated before signing: GitHub
reported the source job exceeding its 35-minute limit. This is an actual terminal
CI failure, not a local observation timeout. Repeating every unchanged blob for
each revision has now been replaced with mobile-scoped `git ls-tree` inventories,
deduplicated blob IDs, bounded `git cat-file --batch` reads and the same POSIX
`git grep` expressions over those exact blobs (`--no-index --text --threads=1`).
Every reachable commit and every original path remain represented; a public
Firebase exception is still evaluated by its original path. Binary bytes and the
existing locale are preserved. This is actual reuse of Git's object/grep engines,
not a new scanner dependency or a claimed competitor implementation.

The 19 focused scanner checks passed, including deleted binary material,
monorepo scope, branch-only copies of identical public-client blobs, redacted
errors and owned-workspace cleanup. The full real local repository history passed
in 109.6 seconds. Independent review by `/root/ios_signing_review` found no
practical blocker. Hosted completion and signed IPA generation remain separate
requirements. The next build must also include the later CourseDetails safe-area
source correction; Android 65 is unchanged and does not contain that correction.

The already-approved repository secrets are:

- `ROKN_IOS_DISTRIBUTION_P12_BASE64`
- `ROKN_IOS_DISTRIBUTION_P12_PASSWORD`
- `ROKN_IOS_APPSTORE_PROFILE_BASE64`

The job validates team `VMHVLW746S`, app `com.rokn`, certificate fingerprint,
expiry, App Store profile type and required capabilities. Provisioning settings
are not applied globally to CocoaPods targets. Xcode archives and exports the
IPA without making an Apple account request.

## Evidence and boundary

The output includes the signed IPA, dSYMs, Xcode export diagnostics and
`release-evidence.json`, under a commit-specific GitHub artifact name. Private
keys, P12 passwords, temporary keychains and source provisioning files are not
included. The app's normal embedded public distribution profile is part of the
IPA. Signing material is removed even on failure; the hosted runner is ephemeral.

The exported IPA is checked with Apple's `codesign`, its own embedded profile,
certificate, bundle identity, version, SDK and entitlements. Its configured API
is recorded from the pinned production environment, source configuration gate
and URL presence in the bundle. That is **not** evidence that the effective API
worked on a running iOS device. `nativeRuntimeAccepted` remains false until that
acceptance happens. Successful export does not mean TestFlight upload, review
submission or public availability.

Android 65 at `739c7251` remains immutable. These build-only changes do not
retroactively change its source provenance or require another Android build.
