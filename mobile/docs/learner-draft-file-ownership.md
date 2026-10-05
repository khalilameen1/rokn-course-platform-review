# Learner draft attachment ownership

`learnerDraftFiles` owns attachment copying, size checks, account serialization,
provisional batch protection and orphan eviction. It is the existing public entry
point; callers do not assemble filesystem and registry operations themselves.
Drafts/outboxes own their content and durable storage writes. The native registry
and durable draft records together determine which copied files are still used.

`learnerDraftFiles/paths` owns managed path/account classification and extension
selection. `learnerDraftFiles/referenceStore` owns registry decoding, staged
replacement/backup recovery and reading references from durable drafts. It never
copies or evicts attachment bytes. Registry mutations run under the public file
owner's account queue, not a second independent lock. The common keyed queue
primitive keeps all file operations for one account ordered while different
accounts remain independent. Moving a registry operation outside that owner
would break the copy/retain/cleanup ordering even if the JSON write itself works.

`learnerDraftStorage` declares the persisted namespaces and account positions of
the records that can own these files. Each producer imports its namespace from
this declaration. Cleanup reads only those records for the exact account, not
every account-scoped AsyncStorage key. Payment recovery IDs, preferences and
support tracking receipts are not draft JSON and must not break file selection.
Project/course IDs that resemble an account ID do not change ownership.

When adding a persistent attachment owner, declare its namespace/account position
and use that namespace in its producer. Add a cleanup behavior test with its
actual persisted shape. Do not work around a missing declaration by ignoring
parse errors for active drafts: unreadable active owners must still stop eviction.
Quarantined `:corrupt` records are not active drafts; selectable support conflicts
are active and their embedded JSON must still be inspected for references.

No stored key or format is migrated by this declaration. Existing namespace
strings, retention limits, provisional grace, file-copy verification and atomic
registry replacement remain unchanged. A genuine storage/read failure does not
mean files are abandoned. Cleanup cannot evict a durable or provisional owner to
make room for a new pick.

`learnerDraftReferenceCleanup.test.ts` reproduces the native-purchase-binding
conflict and covers every declared draft family, corrupt/incomplete active reads,
unrelated account data, registry recovery and failed deletion accounting.
`learnerDraftStorage.test.ts` checks account positions and namespace boundaries.
`learnerDraftBatchOwnership.test.ts` covers multi-select budget protection. Other
draft, upload and chat suites exercise producers through their public APIs.
`learnerDraftRegistryCommit.test.ts` exercises temporary-write and rename failures,
rollback/backup recovery, concurrent owners and independent account commits
through the public file owner rather than bypassing its queue.

These are local mocked-filesystem tests, not device picker or OS cache-purge tests.

## Final gate — course-chat cleanup assertion, 5 October 2026

`courseChatConsentOwnership.test.tsx` originally expected a one-argument mock
call. The existing attachment owner passes the unary `removeLearnerDraftFile`
directly to `Array.map`, which also supplies its index and source list. The
public file owner consumes only the first argument; account selection still
comes from the managed URI, and registry/durable references protect live files.

The same deferred-copy/lesson-departure case now compares the exact batch of
first arguments: one obsolete URI, no extra cleanup. It retains the empty
composer and no-question assertions. No file owner, picker, account queue or
application behavior was changed to satisfy mock callback arity.

The full consent suite passed 18 tests, with no skipped or filtered cases, in
`mobile/.cache/final-gate-20261005/mobile-chat-consent-final.log`. Independent
read-only review accepted this limited correction and its recorded result.
Native deletion, OS picking/cache eviction and the full release gate remain
unverified by this suite.

The separate `courseChatPickerOwnership.test.tsx` suite reproduced five failures
of the same mock-arity expectation (24 passed, 5 failed). Its four obsolete-copy
cases and partial-copy failure/retry case now assert the exact first-argument
cleanup batch. The original account, lesson, closed/unmounted visit, native
background/return, limit and retry scenarios remain intact. The full suite then
passed 29 tests in `mobile/.cache/final-gate-20261005/mobile-chat-picker-final.log`;
the baseline is `mobile-chat-picker-reproduction.log` in the same directory.
Independent read-only review accepted these two expectation changes without
claiming actual filesystem deletion or native picker acceptance.

## Final gate — portfolio picker navigation boundary, 5 October 2026

The full `portfolioPickerSubmissionOrdering.test.tsx` suite initially stopped
on import before executing any case: its partial navigation mock omitted
`createNavigationContainerRef`, now reached through the real upload-session /
delivery / profile API import graph. The installed `@react-navigation/native`
7.1.26 re-exports the MIT core 7.13.7 primitives. The fixture now exposes the
actual core `createNavigationContainerRef` and `CommonActions`, while preserving
its existing screen-navigation hook double. No root helper guard, upload-session
replacement, production import or dependency was added to conceal this error.

The real create flow and upload session remain under test. All 15 cases passed
in `mobile/.cache/final-gate-20261005/mobile-portfolio-picker-navigation-recheck.log`
after the import-only failure in `mobile-portfolio-picker-navigation-reproduction.log`.
Independent read-only review accepted this test-boundary correction, preserving
subscription gating, picker/copy submission exclusion, departed destinations,
failure/retry and cleanup ordering. This is not device navigation, native upload
or full portfolio acceptance.

### Current fixture-tree compiler and lint evidence

After these corrections, the complete mobile `tsc --noEmit` command with the
pinned Node 24.19.0 runtime exited zero. It emitted no diagnostics; no nonempty
compiler log is claimed. ESLint inspected the consent, picker, portfolio-picker,
keyboard, project-feedback lifecycle and wallet-task presentation test files:
all six have zero reported errors, warnings and messages in
`mobile/.cache/final-gate-20261005/mobile-current-fixtures-lint-result.json`.
The keyboard suite's existing deep-import suppression for native TextInput
test state remains unchanged and is listed separately as a suppressed message.
These checks do not replace the remaining failed mobile/backend suites or
native/integration acceptance and do not establish release readiness.

## Final gate — confirmed portfolio action boundaries, 5 October 2026

The real details controller, portfolio HTTP adapter, media delivery/outbox and
managed-file owner remain under test in `portfolioConfirmedMutationLifecycle`.
The native filesystem, storage and HTTP are controlled test boundaries; these
results do not prove an actual device upload or server acknowledgement.

The initial full suite reproduced five failures (6 passed / 11 total) in
`mobile/.cache/final-gate-20261005/mobile-portfolio-confirmed-reproduction.log`.
Corrections concern test contracts, not application behavior:

- Failed retirement and unacknowledged upload now permit only the exact
  account registry backup cleanup path. Every student media deletion is still
  forbidden at that point. Retained request identity/outbox assertions remain,
  and the successful retry additionally checks deletion of its exact media
  path after durable retirement. Independent read-only review accepted this
  limited unit; its recheck had 8 passed / 3 still failed.
- Staging returns and persists the normalized `paused: false` field for active
  entries. Exact shape expectations retain that field instead of treating the
  approved pause contract as a regression. Deferred durable staging still
  cannot settle or POST before storage acknowledges it. Independent read-only
  review accepted the normalization unit at 9 passed / 2 still failed; the
  queued-next expectation was not yet reached at that intermediate checkpoint.
- The old finalize fixture stalled pruning of a zero-byte pending file before
  any finalize request, but asserted a confirmed server result. The corrected
  confirmed-finalize case starts with no pending uploads and stalls registry
  maintenance only after its actual adapter dispatches the finalize request.
  Confirmed deletion still exercises stalled media cleanup. The raw-lock case
  now follows the actual destructive delete handler and its HTTP receipt; new
  staging waits for raw cleanup even after presentation is released, then
  preserves its exact entry, normalized pause and file references.
- Additional active/paused readable-pending cases forbid premature finalize,
  retained intent/file deletion and destructive requests. The original
  zero-byte pruning scenario is retained separately: no finalize dispatch or
  presentation release before pruning finishes, then explicit finalize succeeds.
  Its native stat result is explicit, not inherited from another test's spy.

The final complete suite passed all 14 cases, without skips or filtering, in
`mobile/.cache/final-gate-20261005/mobile-portfolio-confirmed-ack-boundary-final-accepted.log`.
The earlier new preflight case's timeout is retained in
`mobile-portfolio-confirmed-ack-boundary-final.log`; no timeout was increased.
The complete mobile `tsc --noEmit` command exited zero with no diagnostics.
ESLint reports zero errors, warnings and messages for this test file in
`mobile/.cache/final-gate-20261005/mobile-portfolio-confirmed-lint-result.json`.
Independent read-only review accepted the final boundary unit after inspecting
the real ownership path and recorded 14/14 result. It specifically accepted
the preflight/HTTP-receipt distinction, actual confirmed-delete raw-lock case,
retained original pruning scenario and active/paused pending guards. The
reviewer did not execute tests or claim native/server acceptance.

No mobile source, API schema, backend or dashboard behavior changed for these
fixture corrections. Existing account ownership, media idempotency, pause,
publication eligibility and raw queues remain intact. Native filesystem,
provider traffic, the full mobile/backend/dashboard gate and release acceptance
remain open. No build, commit, push, deployment or store mutation was made.

## Final gate — portfolio editor hydration navigation fixture

`portfolioDraftHydrationLifecycle.test.tsx` reproduced an import-only failure
before any case ran: the partial navigation mock omitted the real container
reference reached through create flow, upload session, delivery and profile API.
It now exposes the installed MIT core `CommonActions` and
`createNavigationContainerRef`, which native 7.1.26 re-exports from core 7.13.7.
The screen navigation hook double and existing picker/file/transport boundaries
remain unchanged; the real editor, create controller and form are not replaced.

The complete suite passed six cases in
`mobile/.cache/final-gate-20261005/mobile-portfolio-draft-navigation-final.log`;
its baseline is `mobile-portfolio-draft-navigation-reproduction.log` in that
directory. Independent read-only review accepted the import-boundary correction
and retained failed-read/no-autosave, explicit restore/retry, account/visit
retirement and restored create/stage/finalize input assertions. This proves
neither native storage/navigation/upload nor the full project release gate.

After the editor/avatar/foreground-checkout/login-navigation fixture corrections,
the complete pinned-runtime mobile `tsc --noEmit` command exited zero with no
diagnostics. Targeted ESLint reports zero errors, warnings and messages for
`jest.setup.js` and those four test files in
`mobile/.cache/final-gate-20261005/mobile-native-test-boundaries-lint-result.json`.
This compiler/lint evidence does not replace remaining failed functional suites
or native/backend/dashboard acceptance. No nonempty compiler log is claimed.
