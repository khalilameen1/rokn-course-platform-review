# Project report and discussion read recovery

## Reference and provenance

The reference is Bluesky's shipped native inline error/retry component
[`ErrorMessage`](https://github.com/bluesky-social/social-app/blob/main/src/view/com/util/error/ErrorMessage.tsx).
Its [official Android listing](https://play.google.com/store/apps/details?id=xyz.blueskyweb.app)
shows 10M+ downloads as inspected on 2026-10-04. That proves distribution of the
application, not the success rate of this particular recovery control.

We follow its observable same-surface retry pattern. We do not import or claim
to copy its ALF/Lingui-specific source. Rokn reuses its existing authenticated
thread GET, native Pressable/Text and shared typography and touch-target tokens.

## Defect and scope

An exhausted transcript GET previously asked the learner to close and reopen
the project. With no received transcript, the screen could label a network read
failure as failed report generation. A loaded report's pending reply also had
no in-place read recovery after bounded polling stopped.

`useProjectFeedbackThread` now owns initial hydration, access refresh, pending
polling and explicit retry through one GET lifecycle. Initial hydration retains
three automatic attempts and pending polling retains a bounded thirty-read
cycle. An explicit failed retry is acknowledged immediately, not hidden behind
another hydration backoff cycle. A successful explicit read of a still-pending
reply resumes the bounded polling cycle.

Read failures are separate from message-send errors. The shared native recovery
appears beside an empty report or its existing transcript. It receives only
`retryRead`, never report-generation retry or message-send retry. A received
report and an open discussion remain mounted during access refresh/retry;
continuing the passed course does not depend on a successful transcript read.

Each read failure has a distinct action receipt. Its callback is consumed
synchronously and invalidated on close, background, unmount, context change or
successful read. An old callback cannot restart a closed visit, a recovered
thread or a later failure that happens to have the same displayed text.

## Backend and dashboard contract

No new endpoint, migration, dashboard setting or billing path is required.
`projectRemote.loadProjectFeedbackThread` uses the existing authenticated GET
and account-session boundary. `ProjectController.feedbackThread` verifies thread
ownership. `ProjectFeedbackThreadService.payload` reads the transcript, retains
the initial report outside the recent-message window and exposes current
enrollment permissions/quota. This GET does not generate a report, send a paid
question, grant access or alter balances. Existing dashboard enrollment/reply
settings remain authoritative.

Draft consumption still belongs to the existing acknowledged-request logic
when a GET discovers a message already accepted by the server. Merely reloading
a report without that acknowledgement does not consume the learner's draft.

## Deferred verification

Authored regression coverage is in `projectFeedbackLifecycle.test.tsx`,
`projectPartialReport.test.tsx` and `projectJourneyState.test.ts`: bounded initial
read failure, double tap, same-text repeated failure, closed/background stale
callbacks, late cross-project results, exhausted pending polling, preserved
draft/report, paid-action separation, busy state and continuation. A failed
server report message with no text is accepted as a transcript read success so
the existing generation failure policy remains distinct.

These cases have not been executed. Native network interruption/recovery,
background/foreground, optional discussion, keyboard and screen-reader checks
remain part of the requested final combined verification before build/release.
Source review is not runtime or release acceptance.

Independent source review on 2026-10-04 accepted this unit after the stale
callback receipt gap was corrected. It found no remaining everyday blocker
within this read-recovery scope. The reviewer did not execute tests, builds or
native/backend integration checks.

## Final combined gate update — 2026-10-05

`projectPartialReport.test.tsx` has now run: all 19 presentation cases passed
(`mobile/.cache/final-gate-20261005/mobile-project-fixture-final.log`). Its test
fixture retains the actual typed mounted React element and clones that element
to re-render a changed mocked controller without remounting discussion state.
This removes the compiler's untyped TestRenderer-props error without weakening
recovery, hydration, draft, quota-upgrade or continuation assertions.
Independent review accepted this test-only correction. The installed React API
preserves element type, key and props; no application behavior was changed.

The final three-fixture rerun passed 36 tests; targeted ESLint and the complete
mobile typecheck also exited zero. This does not close the other failed mobile
suites, prove native keyboard/network behavior or accept the backend. The full
combined release gate remains incomplete; no build, push or deployment ran.

### Returned pending-reply polling fixture — 5 October 2026

The full lifecycle suite reproduced one failure (32 passed, 1 failed): the
same-thread foreground-return fixture advanced a fixed 2100ms before expecting
its next transcript. The existing post-hydration poll uses
`1800 * 1.35 * (0.82 + random * 0.3)`, approximately 1993–2722ms. That fixed
advance did not cover its normal jitter window; it was not evidence that the
server result or quota disappeared.

The same deferred-return case now runs lower, middle and upper jitter samples
with its random spy restored in `finally`. It advances 3000ms of fake time
asynchronously, keeps the report/quota/pending/hydration assertions, verifies
exactly two GETs for project 7 / thread-7, checks no more GET after completion,
and retains exactly one message POST. No production timer, backoff, request,
server or dashboard policy changed; the Jest timeout was not increased.

All 35 lifecycle cases passed in
`mobile/.cache/final-gate-20261005/mobile-project-report-poll-final.log`; the
baseline is `mobile-project-report-poll-reproduction.log` in the same directory.
Independent read-only review accepted the timing correction and strengthened
request assertions. This is fake-timer/hook evidence, not actual network,
native background recovery or whole-project release acceptance.
