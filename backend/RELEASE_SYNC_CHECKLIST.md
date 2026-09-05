# Rokn release synchronization checklist

The mobile app, API and dashboard share one production contract. A mobile APK
must not be promoted until the matching backend revision and migrations are
deployed.

## Source of truth

| Capability | Dashboard owner | API contract | Mobile consumer |
| --- | --- | --- | --- |
| Courses, modules, short lessons, previews and projects | Courses | `/courses`, `/courses/{id}`, progress/project endpoints | Home, course details, course player |
| Course price and unlock | Courses / Packages | wallet snapshot and `/courses/authorize` | Course purchase sheet |
| Paid versus reward coins | Packages / Settings | wallet ledger | Wallet details |
| Reward limits | Settings → wallet/support | `/economy-config`, `/rewards/daily`, watch history and completion events | Home and course player |
| College grants and promo access | Course codes | `/course-codes/redeem` | Course details |
| Saved folders | Learner data | `/saved-folders` | Player and saved content |
| Watch history and resume | Learner data | `/user/watch-history` | Player and My Corner |
| Portfolio and certificates | Learner data / Courses | portfolio and certificate endpoints | Profile; certificate QR opens the public certificate verification page |
| Promotions | Notifications | student notifications | Home campaign and notifications |
| Support and coin rules | Settings | `/settings` | Settings and Wallet |

## Required deployment order

The production Cloud environment must not use **Push to deploy**. Backend CI
must pass for the exact commit being promoted before a deployment is triggered.
Check the deployed commit after Cloud finishes; a successful CI run for another
commit is not approval. Mobile-only CI does not replace this backend gate, and
unrelated mobile jobs need not delay a backend-only hotfix. Until a gated deploy
hook is configured, deployment is manual after that exact-commit check.

1. Back up the production database and matching object stores. Verify the exact
   artifact with `ops:verify-backup`; signed backup/restore evidence must remain
   within the configured RPO/RTO window.
2. Build the backend revision as an inactive candidate artifact.
3. Run `php artisan rokn:preflight --configuration-only --connectivity` from that artifact.
4. Run `php artisan rokn:release-migrate` from exactly one candidate release process while the compatible old revision still serves traffic. It performs the isolated migration with a bounded lock wait and runs the schema/connectivity gate with the temporary mixed-release allowance.
5. Do not switch traffic unless that command completes successfully with no pending migration or missing required schema.
6. Warm config, route, and view caches inside the candidate artifact, then switch traffic atomically. Do not clear shared caches after the switch.
7. Restart queue workers with `php artisan queue:restart` and verify fresh heartbeats for every required queue. After the previous revision's maximum request/job timeout has elapsed, run `php artisan rokn:release-finalize --old-workers-drained`.
8. Repeat the schema/connectivity preflight and require `/api/health/launch-ready` to pass.
9. Keep exactly one scheduler running every minute; it finalizes delayed project reviews.
10. Verify Redis is used for cache and queues in production.
11. Confirm `/api/v1/economy-config` returns the values shown in dashboard settings.
12. Confirm a real social login receives the configured first-registration bonus once.
13. Confirm a Kashier package order appears as EGP revenue, while a course unlock appears only in coin-consumption reporting.
14. Promote the matching APK only after the checks above.

Application rollback means switching traffic and workers back to the previous
artifact while keeping additive schema changes. Never run migration rollback
against a live database shared with either release.

## Accounting invariants

- Kashier package orders are cash revenue in EGP.
- Course wallet orders are virtual coin consumption, never EGP revenue.
- Every course order stores immutable `total_coins`, `paid_coins` and `reward_coins`.
- Reward credit events have idempotency keys and bounded daily/rolling limits.
- A course can consume no more reward coins than the dashboard-configured course cap.

## Android release channels

- Stakeholder/phone test: `npm run apk` writes `mobile/artifacts/Rokn-internal-test.apk`.
  This build may include the deterministic local demo and is debug-signed. It
  must never be uploaded to a store or distributed as a production release.
- Production direct distribution: `npm run apk:direct` writes
  `app/artifacts/Rokn-direct.apk`. It requires an explicit production
  `EXPO_PUBLIC_API_URL`, release keystore and matching backend deployment; the
  local demo is disabled.
- Google Play: `npm run aab:play` produces the Play App Bundle. It requires the
  Play billing/review configuration appropriate to the chosen commercial model.

Do not publish any build that points to a backend revision older than this
checklist. Archive the artifact metadata and SHA-256 digest with every release.
