# Portfolio upload entitlement — local change, 22 September 2026

## Rule

Uploading portfolio work requires at least one active, unexpired course enrollment
whose captured subscription includes a certificate. Course completion, an issued
certificate and practical projects are not prerequisites. Tier names are not used.
Institutional watch-only grants and subscriptions without certificates do not qualify.
Pending/refunded source payments and course/plan financial holds cannot authorize uploads.
Existing enrollment snapshots, including legacy certificate entitlements, remain authoritative.

## Boundaries

- `PortfolioUploadAccessService` is the shared server decision.
- `GET portfolio/upload-access` exposes a fresh boolean decision to the app.
- New portfolio creation, new image storage, video allocation and video authorization
  renewal enforce the decision server-side. Images already accepted under the same
  request ID may replay their receipt without writing new storage.
- The mobile app checks before opening the create form/file picker and again before
  sending media. The latter also covers background outbox replay and video resume.
  Missing/invalid/offline permission responses never unlock upload.
- Reading, metadata editing, deleting and finalizing already-uploaded work remain available.
  Claiming a video uploaded under a previously issued lease adds no new storage and
  remains available. Provider capabilities already issued remain valid until their
  normal short-lived expiry; this change does not remotely revoke those credentials.
- No database migration, new dashboard flag, automatic deletion or new storage quota.
  The existing dashboard certificate entitlement feeds the captured subscription.

## Verification / rollout

Approved-copy follow-up: mobile subscription messages now share
`src/constants/subscriptionMessages.ts`. Chat explains the restriction before
opening the existing upgrade checkout. Daily limits use their own message and
acknowledgement button, not an upgrade action. Portfolio access responses expose
`has_subscription` so the dialog distinguishes browsing courses from upgrading.
Portfolio dialogs close their underlying editor before navigation. The subscription
action opens the enrolled-course list so the learner chooses which course to upgrade.
The focused mobile chat, portfolio, subscription-copy and upgrade run passed
36 suites / 248 tests, with TypeScript and touched-file ESLint passing.
Backend execution remains blocked by the local PHP application-control restriction.

- Mobile portfolio suite: 23 suites, 152 tests passed.
- TypeScript and ESLint for touched mobile files passed.
- Added backend entitlement matrix and updated portfolio upload fixtures.
  Backend tests NOT executed: Windows Application Control blocked the installed
  PHP executable, including an elevated attempt. No policy changes were made.
- Before rollout, run `php vendor/bin/phpunit --filter Portfolio` in an approved
  PHP 8.4.24+ test runtime and test native file selection on Android.
- Local only: no Git push, server deployment, Android build or Play Console changes.
  Play 60 review and published internal Play 61 were not touched.
- Deploy and verify the backend contract before shipping a client with this change.
  A new client against the old server intentionally refuses uploads rather than
  assuming a certificate entitlement.
