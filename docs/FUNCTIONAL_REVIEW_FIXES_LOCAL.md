# Functional review fixes — local only

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
