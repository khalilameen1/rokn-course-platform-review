# Functional review fixes — local only

## Review-report reconciliation — 2026-10-24

Working checkout: `C:/rkn-deploy67`, branch `codex/review-fixes-20261024`.
Base: `1db2bc6660d3fff6415c759e41d66013ca35125a`.
Only the `cloud` remote (`khalilameen1/rokn-course-platform-review`) is the
authorized target. This review does not deploy production or publish a store build.

### Requirement-to-evidence map

- Upgrade refund ancestry: `FinancialProvenanceTest` exercises Basic → Plus → Pro,
  both reversal/resolution orders, independent replacement upgrades, and one
  funding package shared by the base and upgrades. `CoursePlanLineageService`
  follows immutable parent orders and the financial hold's captured root/tip;
  enrollment writes still belong to `CourseEnrollmentWriter`.
- Certificate entitlement after a reversed upgrade: the same suite verifies a
  Basic fallback revokes the credential, a certificate-bearing fallback retains
  it, and mixed base/upgrade holds restore it only when entitled. Existing manual
  revocation and repeated-settlement tests remain in scope.
- Abandoned checkout: `courseSubscriptionCheckout.test.tsx` explicitly exercises
  cancellation and a new quote when the payment URL can no longer resume.
  Existing Kashier expiry/reconciliation tests cover backend recovery. The report's
  claim that the user is permanently trapped does not match this implementation.
- Course-code replacement: `CourseCodeRedemptionOwnershipTest` exercises pending
  AI reservations during replacement of inactive access. The writer reuses
  `cancelOutstandingReservations`; consumed usage is retained, safe reservations
  are cancelled, and provider-started work is detached for later cost settlement.
- Playback completion and stale-session evidence: `playbackEvidenceStorageOrdering`
  covers delayed local storage, completion followed by stop, offline sessions,
  restart/retry, and completion behind an in-flight heartbeat. `sectionCompletionOwnership`
  covers the account boundary. The existing durable outbox remains the only engine.
- Hero selection: `CourseHeroSelectionConcurrencyTest` preserves explicit draft
  selection across partial saves/publication. The intentional one-public-hero
  fallback is unchanged.
- Staff permissions: `GranularStaffWorkflowParityTest` and
  `FilamentFinancialOverviewTest` cover finance, student editing, authoring cost,
  and support assignees through the existing Spatie-backed permission matrix.
  The mixed-domain TodayStats widget remains owner-only.
- Receipt-time uncertainty: `CourseCheckoutServiceTest` includes Google/Apple
  earlier or missing timestamps and verifies credit is retained and a fresh
  authorization does not require another payment. This does not establish that
  a real store/server clock mismatch occurred, and no receipt guard was weakened.
- Photo query growth: `SavedFolderEndpointTest` and `PortfolioEndpointTest` check
  batching across multiple courses; application code reuses Eloquent eager loading.
- Unknown saved-lesson duration: `SavedFolderEndpointTest` verifies the API emits
  numeric zero; `savedLessonsMapper.test.ts` accepts that unknown duration without
  dropping the list and still rejects malformed contracts.

### References and reuse boundaries

- Spatie Laravel Permission is already installed; its actual permission query
  implementation is reused, not replaced: https://packagist.org/packages/spatie/laravel-permission
- TanStack Query is already installed and is used in Sentry:
  https://github.com/getsentry/sentry/blob/master/package.json
  Playback ordering changes reuse Rokn's existing durable outbox, not claimed
  copies of TanStack internals or a closed-source video application's code.
- Medusa's enterprise payment/order architecture is a reference only:
  https://medusajs.com/enterprise
  Its Heineken case reports more than 10,000 daily orders. No drop-in Medusa
  gateway code was transplanted into Laravel/Kashier; Rokn's purchase ancestry
  and coin-funded entitlement rules remain product-specific.
- Eloquent eager loading: https://laravel.com/docs/12.x/eloquent-relationships#eager-loading
- The locked Livewire release was updated from 4.4.7 to 4.4.8 for the official
  authorization fix: https://github.com/advisories/GHSA-mhf7-xp6r-6qrc

### Verification status (not release approval)

- Full mobile Jest run: 379 suites, 3484 tests passed; process exited 0.
- Backend baseline CI run `40064916797`: success, including full tests,
  MySQL contracts and certificate artwork checks, before the financial lineage changes.
- Dashboard browser run `40066370265`: success for commit `91c3fc36`.
- Backend financial run `40066370288`: 3065 passed, 6 skipped, 5 failed.
  All five failures occurred while constructing a new test receipt: its pass-only
  report mode still retained the seeded guided plan's nonzero report budget.
  The fixture was corrected without relaxing production snapshot validation.
  Later commits and the final financial scenarios still require a green run.
- Local Larastan and touched-file Pint checks passed; Windows Application Control
  blocks the local SQLite extension, so behavioral PHP results must come from CI.
- No native-device purchase/upload test, release build, production migration,
  or store publication is implied by these checks.

---

Date: 2026-09-22

## Scope

Three confirmed review findings, without deploying the backend, changing Play Console, building a store release, or modifying the reviewer account.

### Chat-specific upgrades

- The chat entry point passes `requiredFeature: chat` into the shared upgrade/checkout flow.
- Offered plans must enable chat and provide a positive message allowance.
- The requirement is included in both checkout quotes, including the quote binding the native store product.
- The backend validates the effective public capabilities, persists the requirement in the intent, and revalidates it before authorization and fulfillment.
- The requirement is optional for compatibility with existing clients and intents. Projects-only upgrades remain valid when not entered to buy chat.
- Existing project-discussion upgrades use the same requirement contract.

### No higher tier

- `already_upgraded` no longer calls the success callback or closes the shared upgrade sheet.
- No eligible plan produces an explicit unavailable state instead of an endless retry prompt.
- Exhausted chat shows that there is no available higher subscription; it does not promise renewed messages or silently reset usage.
- Opening/reopening the chat explanation is tested through rendered behavior, replacing the stale assertion against a literal effect condition.

### Pending payment belonging to another course

- The checkout hook recognizes the server's `checkout_already_pending` response and keeps its validated active-checkout reference separately from the current quote.
- The same sheet offers checking the previous payment and cancelling its request while locking current plan selection.
- Recovery uses the existing authenticated resume/cancel endpoints. It never starts a second store payment.
- A previous course completing cannot trigger the current course's purchased callback. The current quote is refreshed against the latest balance after recovery.
- A pending result or network failure retains the recovery actions. Server prevention of overlapping authorizations remains unchanged.

## Verification boundary

Executed after the changes:

- Full mobile Jest suite: 290 suites passed, 2263 tests passed, zero failures.
- TypeScript `tsc --noEmit`: passed.
- ESLint on the changed mobile implementation/tests: passed.
- `git diff --check` on the changed checkout implementation/backend tests: passed (only repository line-ending conversion notices).

Mobile regression coverage includes plan capabilities, no-higher-tier behavior, explicit tap/reopen lifecycle, checkout requirement serialization, cross-course recovery/cancellation, still-pending and network-failure recovery, and the rendered recovery actions.

Backend regression tests cover rejecting a projects-only plan for a chat intent without spending and rechecking a capability removed after quoting. PHP execution is blocked by local Windows Application Control, so these backend tests require an approved PHP environment before deployment. This does not bypass or change the machine's application-control policy.

Native device purchase/upload end-to-end checks are not implied by the JavaScript tests. This change set is not a new Play Store build.
