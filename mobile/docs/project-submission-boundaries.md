# Project submission editor boundaries

The public `useProjectSubmission` return contract stays unchanged. The editor,
course/reel presentation and submission outbox do not need alternate adapters.

## Owners

- `projectTransition/useProjectSubmission.ts` coordinates explicit learner
  actions, file picking/validation, submission admission, consent and UI outcomes.
  It remains responsible for single-flight submission and native picker visit
  ownership. It does not hydrate or mutate the private draft lifecycle.
- `projectTransition/useProjectDraftEditor.ts` owns editable files/note,
  hydration, restore failure/retry, debounced/background/unmount persistence and
  consuming an accepted draft. Its session identity changes only when the
  project changes. Callers can inspect boundary/readiness/snapshot and request
  `persist` or `consume`, but cannot assign private lifecycle fields. Its public
  setters reject calls from a departed editor as well as writes before hydration.
- `projectTransition/projectDraftRevision.ts` owns the revision response
  contract and preparing durable draft work for a current destination. It has no
  React, Alert or navigation dependency. Each confirmation re-reads the original
  project, so a destination retired by a second publish cannot be accepted merely
  because the earlier confirmation named it.
- `services/projectSubmissionDraft.ts` remains the only durable draft storage
  owner: account-scoped locks/keys, TTL, file references, source retention and
  compare-before-replacing a destination. Neither hook reimplements those rules.

## Transitions to preserve

A server status refresh is not authorization to clear the visible draft. A
recovered prior pass may describe an earlier attempt; preserve current edits
when `preserveDraft` is true. Only the guarded matching accepted outcome calls
`session.consume`, using the actual submitted file list for cleanup.

Hydration errors leave editing/submission disabled until retry succeeds. A
retry can renew the same account's session but cannot adopt another account.
The outgoing lifecycle snapshots its own latest work when a project changes;
backgrounding the new editor cannot save an empty replacement while its draft
is still being loaded. Changed project requirements do not delete incompatible
text/files; they remain visible for explicit learner editing.

Revision preparation never navigates. The current UI visit verifies its own
identity after the probe and after persistence, presents destination-conflict
confirmation and only then publishes the existing revision navigation event.
A removed project retains the source draft; a copied draft retains the source
as well. Neither operation migrates an outbox retry/attempt identity.

## Verification

`projectDraftEditorLifecycle.test.tsx` exercises the draft owner without the
submission controller: pending next-editor hydration, outgoing flush, stable
session on status refresh, accepted consumption, stale setters and account
replacement. Existing rendered-hook coverage remains in
`projectSubmissionHydration`, `projectDraftHydrationFailure`,
`projectSubmissionPickerLifecycle` and `projectDraftRevisionTransition`.
`courseGateContracts` points source-level ownership assertions at the new owner
while retaining the accepted/preserveDraft gate. Storage-level ownership and
durability tests remain unchanged.

Run the complete Jest suite, TypeScript checks and scoped ESLint after changing
these boundaries. These checks are local; they are not an Android/iOS device or
live upload verification.
