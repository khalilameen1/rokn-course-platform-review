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

## Evidence at source preparation

- Backend broad local run exercised 1,750 tests and 17,408 assertions. Three compatibility/fixture/migration failures were identified and fixed with focused reruns; this is not recorded as a fully green final run.
- Payment audit fixes include infeasible paid-floor upgrades, valid project-only upgrades, repeated direct checkout and cross-account order binding.
- Native TypeScript, changed-file ESLint and focused checkout regressions passed. A full mobile run's two obsolete assertions were corrected and rerun. The signed build must still pass its complete release gate.
- Artifact digest, final CI runs, deployed source and Play availability are to be recorded only after verification.
