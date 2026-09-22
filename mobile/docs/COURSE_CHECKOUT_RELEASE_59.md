# Course checkout release 59

## Scope

Android `59 / 1.0.58`, package `com.rokn`, intended for the existing Google Play internal testing track only. This is not permission to submit production review or promote a public rollout.

- A shared compact subscription sheet selects Basic / Plus / Pro, shows the actual store price, purchased balance, rewards used and any purchased balance left over.
- One explicit authorization binds the top-up to enrollment or upgrade. The server remains the financial authority. Native receipt verification, recovery and the existing wallet ledger are reused.
- Promotion is a combined lifetime course allowance of up to 20 percent for coupons and earned coins. Purchased coins remain unrestricted. Rewards are not spent unnecessarily when they cannot reduce the selected top-up.
- Quotes expire after 15 minutes. Cancellation, stale offers and uncertain receipts never authorize a new automatic charge. Valid store credits are preserved independently of course fulfillment.
- New Basic offers are watch-only. Historical purchased snapshots preserve their original projects and certificates. Completing the watch-only path does not become practical completion merely by upgrading.

## Deployment order

1. Pass complete mobile release checks and backend CI, including the mandatory isolated MySQL snapshot and migration replay gates.
2. Retain a fresh provider database backup. Apply the additive September 15 migrations through `rokn:release-migrate` before enabling the new app.
3. Check schema, scheduler, queues, authenticated checkout quotation and ordinary course access. The scheduled `courses:resume-checkouts` command recovers authorized funded intents.
4. Publish future watch-only Basic offers through the existing staged authoring workflow only after inspecting for unrelated pending drafts. Do not edit existing order/enrollment snapshots.
5. Build a clean, committed source in an isolated release worktree with the existing Play upload key. Preserve release 58 and unrelated local store documentation/assets.
6. Upload only the signed release-59 AAB to internal testing. Inspect the actual native subscription sheet and test licensed-store success, cancellation, retry and upgrade before commercial activation.

## Commercial limits

Existing live prices and purchased balances are not automatically converted. The proposed 0.60 / 1.00 / 1.50 positioning is not a substitute for actual production and delivery costs. The current internal-test product catalogue is not approved as a profitable commercial catalogue.

Populate verified net paid-coin value and per-enrollment delivery allocations before enabling `ROKN_ENFORCE_COMMERCIAL_FLOOR=true` for commercial activation. Unknown costs remain unknown. See `backend/docs/course-checkout-commercial-policy.md`.

## Verified evidence — September 15, 2026

### Source and backend deployment

- Canonical repository: `khalilameen1/rokn-course-platform-review`. `main` is at `a5b770b1e785277e3095dadb91e2913a48b719e0`.
- Following explicit user approval, [Laravel Cloud deployment 205](https://cloud.laravel.com/rokn-production/rokn-course-platform-review/production/deployments/205/2f38815) reached `DEPLOYED` on backend commit `2f38815e491c0148650dcc17c76f2e8c31d38172`. The later `a5b770b` native accessibility fix does not change the deployed backend.
- The pre-deployment database backup `before-course-checkout-r59-20260915` completed at **2026-09-14 21:47:19 UTC**, size **110.8 MB**.
- [Backend CI 34901791179](https://github.com/khalilameen1/rokn-course-platform-review/actions/runs/34901791179) passed on the deployed backend commit: **1,760 passed, 5 skipped, 17,519 assertions** in the full suite. The mandatory MySQL suite separately passed **16 tests / 113 assertions**; the SQLite skips are MySQL-only cases. Wallet/shared payment contracts passed **107 tests / 546 assertions**.
- Post-deployment `rokn:preflight --schema-only --connectivity` passed. `schedule:list` confirmed `courses:resume-checkouts` runs every minute.
- Public settings and course 3 returned HTTP 200. Unauthenticated access to the checkout endpoints returned HTTP 401 as expected. These public probes are not a substitute for an authenticated native purchase test.

### Watch-only Basic activation

The dedicated Basic command completed both dry run and apply successfully for all six intended courses through normal staged publication. Published revisions were verified as follows:

| Course ID | Published revision |
| --- | --- |
| 3 | 36 |
| 8 | 22 |
| 9 | 18 |
| 10 | 23 |
| 13 | 17 |
| 14 | 19 |

Public API checks verified `projects_enabled=false`, `certificate_enabled=false` and `chat_enabled=false` for Basic on all six courses. Basic / Plus / Pro prices remained **400 / 650 / 900 coins**. The rollout preserves plan identities and purchased receipt terms; normal staged publication changes content graph IDs while retaining their learner-state lineage.

The user clarified that the existing accounts are internal tests, not actual historical customers. Cost inputs are still unverified, so this remains an **internal-test catalogue only**, not approval of commercial profitability.

### Mobile verification and remaining gates

- The earlier full mobile run on `0f1ea8b520f69a2fcc001eec10041b974fa96035` passed **275 suites / 2,148 tests**.
- A subsequent accessibility check found a real missing loading-modal accessibility prop. Commit `a5b770b` fixes it; the **10 targeted tests**, accessibility scan of **404 source files**, changed-file ESLint and TypeScript checks passed afterward. The source-file count is not a test count.
- The complete quality gates within signed-build session `97828` now passed on `a5b770b1e785277e3095dadb91e2913a48b719e0`: **276 suites / 2,150 tests**, duration **49.551 seconds**; accessibility scan of **404 source files**; full ESLint and TypeScript checks; and **93 release-script checks**.
- The same run passed the secrets scan of **967 files** and repository history, dependency audits, and licence verification covering **734 npm / 241 Maven / 127 CocoaPods dependencies**.
- The native **Gradle build is still running** in session `97828`. Passing the complete quality gates does not mean the signed artifact is finished. No final signed artifact or artifact digest has been verified yet.

Remaining release gates:

- [x] Pass the complete pre-build quality gates on the final mobile source `a5b770b`.
- [ ] Complete the native Gradle build and final signing/artifact checks; record the artifact path, version, source commit, signing verification and SHA-256 digest.
- [ ] Inspect the actual native subscription sheet and verify licensed-store purchase, automatic enrollment, cancellation, retry/recovery and upgrade behavior.
- [ ] Upload the verified signed AAB to the existing Google Play **internal testing** track, then verify its availability. No final release-59 upload or public review submission is recorded as completed.
