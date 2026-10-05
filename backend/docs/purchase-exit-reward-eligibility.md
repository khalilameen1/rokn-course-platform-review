# Purchase-exit task eligibility — local source, runtime acceptance pending

## Reference and reuse

Reference: [OneSignal in-app triggers](https://documentation.onesignal.com/docs/en/iam-triggers): audience, action triggers and frequency are distinct; invalidated triggers are removed. [OneSignal's January 2020 adoption report](https://onesignal.com/blog/record-breaking-momentum-and-exceptional-growth-in-2019/) reports more than 10,000 in-app-message customers in 2019. This is historical real adoption, not evidence of Rokn's performance.

This change follows that trigger architecture. No OneSignal SDK or closed-source competitor code was copied. No accessible implementation matching Rokn's course floors, grant/promotion history and indivisible task rewards was found. The original integration reuses Rokn's existing Laravel quote calculator, wallet owner, task/tombstone models and engagement template; no second financial formula or payment flow was introduced.

## Contract

- Only closing course subscription selection before an explicit buy/pay press can request an offer. Authorization errors, cancellation and declined payments are not this audience.
- Buy/pay press consumes this course's exit-offer opportunity immediately, before HTTP or provider launch. Restoring an already pending payment (including a blocking prior checkout) and cancelling it also notify the same owner, so a fresh process cannot misclassify recovery as a first no-attempt close. Showing the offer also consumes it. This is bounded in-process frequency per account/course, not an installation-once or cross-device durable guarantee.
- `/engagement/next` without context retains the existing generic response. With both `course_id` and `access_plan_code`, it returns an additive `purchase_exit` context only for useful course-specific candidates. Incomplete context fails validation; old/generic responses never authorize the new popup.
- The read uses `CourseCheckoutQuoteService::calculate` without funding options, not the durable checkout creation service. It creates no checkout/order/debit/enrollment. Additional useful rewards are the minimum of unpaid shortfall and unused reward capacity after the same promotion allowance, paid floor and current reward allocation used by purchase. An already owned course or unavailable offer has no purchase-exit candidate. Upgrade payments do not use this entry.
- A pending course payment anywhere on the account suppresses the task detour.
- Contextual offers use the existing `NotificationDeliveryPolicy::allowsInbox` marketing audience, honoring the user's offers/news opt-out independently of OS push permission. Generic legacy selection is left unchanged by this audience gate.
- One task selector handles generic and contextual recommendations: active dashboard window/order, usable destination, remaining global capacity, unclaimed ledger/attempt and acquisition tombstones. The complete task credit must fit the configured reward wallet cap; task credit is never silently reduced to its useful course portion.
- Claim and payment still revalidate under their existing write locks. Recommendation is not a reservation of capacity or discount, and cannot promise a specific cash amount or a free course.
- `coin_offer` activation/schedule gates presentation; task amount/order and wallet/promotion settings remain dashboard-owned. Course-exit UI copy is app-owned, not represented as editable template copy. The dashboard hint states this explicitly. The user approved the prototype with heading `اكسب عملات مجانية`, body `تستخدمها في شراء الكورسات`, primary `اكتشف المهام`, secondary `ليس الآن`. It is implemented locally as a centered, vertically scrollable RN Modal card, not a second bottom sheet. Coin-stack uses the same dashboard AppArtwork provider and known-default framing/fallback as welcome; no duplicate image or asset upload was needed.
- Screen focus, foreground, account/course/plan identity, ownership, reopened subscription and payment intent invalidate an in-flight read. Failure quietly omits the optional offer. No background HTTP retry/outbox or fabricated eligibility.

## Acceptance

Independent source review accepted this unit after correcting early restored-payment ownership and the marketing audience opt-out. The follow-up review confirmed the sheet-to-entry early-recovery binding evidence: captured callback identity, nonpending recovery before close, and distinct account keys per scenario. This acceptance covers source coherence only, not executed tests, native pixels, payment/task integration, deployment or the overall project goal.

Authored, not run: backend financial-read-only, useful benefit, wallet full-credit room, saturated promotions/floors, paid coverage, claimed tasks, disabled template, pending recovery, partial context and ownership; mobile no-attempt entry, failed-attempt suppression, once-per-process, unavailable/offline and stale context; mobile response matching/invalid benefit/old backend; real sheet-to-entry restored-pending/cancel/requote/close binding with mocked checkout transport; approved card copy, scrolling/insets, dashboard upload containment/offline fallback and close-before-wallet action.

Final combined gate must execute these tests and prove on phones that closing selection gives one eligible offer, pressing buy never does, navigation/background/account changes cannot surface a stale card, old version 60 remains compatible, and current dashboard/task/payment changes are respected. The local HTML prototype was visibly inspected; actual native pixels/large text/rotation and end-to-end task/payment behavior remain unproven by source review. No tests, build, push or deploy performed for this unit.

## Final gate — required task fixture fields, 5 October 2026

The checkout suite's two raw task inserts omitted `title_en`, required both by
the real migration and the dashboard's task authoring validation. The fixtures
now supply that field; no production schema, financial formula or assertion
was changed. Independent review accepted this limited data correction.
The complete suite ran 54 cases: 52 passed, with one archive-version error and
one opt-out/opt-in offer failure still open. The correction exposes the actual
behavior rather than removing the schema constraint to avoid testing it.
Evidence: `mobile/.cache/final-gate-20261005/backend-exit-task-fixture-recheck.log`.
This is not checkout, native task/payment or release acceptance.

The request helper subsequently starts each feature HTTP request with a fresh
real bearer guard. PHPUnit shares its application container between requests,
while the custom guard retains its request and authenticated model. Reusing
that guard made the next opt-in read the previous opt-out and could mask a
different learner's payment state. Authentication still runs through the real
bearer token; no `actingAs` or production policy change was introduced.
Independent review accepted the limited request-owner correction. The complete
suite now has 53 passing cases and only the archive-version error remains.
Evidence: `mobile/.cache/final-gate-20261005/backend-exit-request-owner-recheck.log`.

The archive case now refreshes its newly created course before reading the
expected editor version. Eloquent's insert hydrates the generated ID, not the
database's `authoring_version` default of 1. Its previous null-to-zero cast
correctly failed the unchanged archive concurrency guard. Reading the persisted
version matches the real archive form; no version is hardcoded and the guard
still compares the supplied version under its existing transaction lock.
Independent review accepted this final fixture correction. The complete
checkout suite now passes 54 tests and 422 assertions, including draft upgrade
rejection without balance or order changes; PHP syntax validation also passed.
Evidence: `mobile/.cache/final-gate-20261005/backend-checkout-fixture-final.log`.
This does not prove a real gateway, native payment, MySQL locking, deployed
version compatibility or the full-project gate. No build or deployment ran.

## Final gate — actual task action control, 5 October 2026

The card suite freshly reproduced one failure: the immediate parent of the
`اكتشف المهام` text was not the button and had no `onPress`. The test now selects
the existing button role containing that visible text, then calls the actual
button's unchanged handler. Production code, copy, styles and artwork were not
modified. Exact close-before-wallet ordering and native Modal close assertions,
safe-area scrolling, dashboard artwork containment and offline fallback remain.

The complete suite passes 1/1. Independent read-only review accepted the actual
control binding and unchanged assertions without rerunning it. Evidence:
`mobile/.cache/final-gate-20261005/mobile-purchase-exit-action-reproduction.log`
and `mobile-purchase-exit-action-final.log` in the same directory. This is test
binding acceptance, not native pixels, actual task/payment integration or full
release acceptance. The reference/reuse boundary above remains unchanged.

## Final gate — shared task selector contract, 5 October 2026

The economy-dashboard source contract freshly passed seven cases and failed
the old expectation that catalogue filtering/order lived in EngagementController.
The corrected assertion follows its injected EngagementTaskReadService and
actual wallet-credit-room argument. The service must still use learnerTask and
dashboard sort_order, with id as deterministic tie-breaker. No task catalogue,
quote formula, financial mutation, API response or dashboard behavior changed.

The full contract suite passes eight tests/66 assertions in
`mobile/.cache/final-gate-20261005/backend-economy-dashboard-contract-final.log`;
the reproduction is `backend-economy-dashboard-contract-reproduction.log`.
Independent read-only review accepted this test alignment. These static checks
do not prove economic integration, database concurrency or release readiness.
The earlier 54-case checkout evidence remains separate, not rerun here.

## Final gate — primary-action dependency, 5 October 2026

Release lint identified courseId as an unused dependency of runPrimaryAction.
Only that redundant dependency was removed; its body and all actual dependencies
are unchanged. Course/account keys remain in login, payment-attempt and offer
owners, including early suppression for restored payment recovery. Scoped lint
is clean and four complete purchase-entry/exit suites pass 33/33 in
`mobile/.cache/final-gate-20261005/mobile-purchase-entry-dependency-final.log`.
Independent read-only review accepted this small correction, not native payment
or whole-tree readiness. No API, payment formula or dashboard change occurred.
