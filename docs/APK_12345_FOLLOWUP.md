# APK 12345 follow-up — 2026-09-06

The learner confirmed that the reported failures occurred in APK `12345`.
That artifact remains unchanged. These are subsequent source fixes, not proof
that the installed artifact or production deployment contains them.

## Confirmed causes and changes

- Android window blur was treated as application background for data work.
  A native chat dialog could stop its own response polling and discard reads.
  Foreground data work now ignores window focus; playback still pauses on blur.
  The native video surface survives window blur and detaches on real background.
  The same distinction applies to course, portfolio, certificate, project and
  MyCorner reads. Reels refresh only on an actual foreground transition.
- Chat layout had two Android keyboard resize owners. Native resize now owns
  Android; iOS keeps padding. Plain message taps do not dismiss the composer.
  Completing a reply no longer forces the reader away from earlier messages.
- Resuming a staged social-login journal could commit the same session twice,
  invalidating authenticated reads started after the first commit. It now joins
  the existing completion owner.
- Session adoption could leave the navigation container on Login. The fallback
  explicitly leaves Login while preserving durable or route-based course return
  destinations. Session identity guards reject old completion callbacks.
- Wallet transactions now open from a button instead of occupying the wallet
  page. Social-task operational titles are presented as follow actions without
  changing authored campaign titles, rewards or verification behavior.
- The provider's existing system-prompt path now requests direct natural
  Egyptian Arabic, short coherent paragraphs and no unnecessary introductions.
  Code, mathematics and URL punctuation remain intact. The prompt does not
  authorize impersonating the instructor. Its version changed to isolate the
  old voice context.

## Verification and remaining acceptance

- Mobile: 170 suites / 907 tests pass; release ESLint and TypeScript pass.
- Backend: 12 targeted tests / 150 assertions pass across prompt policy,
  queued chat prompt and reward-task presentation.
- Regression tests reproduced the window-focus polling failure, duplicate
  session commit, retained Login route, decoder replacement and unwanted
  keyboard/scroll behavior before their respective fixes.
- Navigation tests retain the production router/container but replace the native
  screen host. Foreground tests simulate native lifecycle events. These are not
  authenticated device acceptance.
- Still required: a newly built exact artifact with the corresponding backend,
  real Google login, MyCorner, open-chat response/copy with keyboard and video,
  and actual model output. No claim that every reported visual shake is closed
  without that device check. No new APK was built during this source batch.

## Second source batch — 1.0.42 candidate

- Recovery of an existing chat turn now reads its status without automatically
  generating a new paid reply or uploading its attachments again. An explicit
  retry reads status first and uploads only when a fresh attempt is permitted.
- A confirmed server cancellation releases the matching send lock even when the
  old HTTP request has not returned. Unconfirmed cancellation retains recovery;
  late replies cannot overwrite the cancelled conversation.
- Certificate polling stops when its screen is not focused. Results from an
  older read cannot replace an error/success or accepted-issuance state owned by
  a newer read or another account.
- A wallet read started before confirmed top-up or course purchase cannot
  overwrite that newer balance. The next read remains authoritative, including
  a lower balance. No synthetic credit, maximum-balance shortcut or server-ledger
  change was introduced.
- Backend CI's reward-date assertion used UTC while rewards use Cairo's business
  day. The test now controls its clock and asserts against the business clock;
  the production reward service was not changed.

Local validation: 172 mobile suites / 920 tests pass with TypeScript and release
ESLint. Backend suite: 1174 tests, 11584 assertions, 5 skipped environment-specific
cases. Targeted tests reproduced the new failures before their source changes.
None of these results substitutes for signed-artifact authenticated acceptance.

## Report after 123456 — 1.0.43

- Production command 97 reproduced MyCorner's `LogicException` for the learner's
  account. The shared module-order map contained modules from several courses;
  comparing its total size with one course's module count incorrectly rejected
  valid data. The check now rejects a missing referenced module, not extra valid
  entries. HTTP regressions cover multiple courses, pagination and account scope.
- Retired the old `enforce_course_section_order` policy and its dashboard control.
  Lessons do not lock each other. Purchase access and unpassed projects still
  gate later content, including across modules. Playback manifests use the same
  access decision; completion tests cover advance, project and preview boundaries.
- Android resizes the native Modal for the keyboard, but the sheet then took a
  percentage of that already reduced space. The measured Modal viewport now caps
  the sheet directly. History can shrink; the composer remains outside it, with
  a bounded input height. Real Yoga layout tests reproduce the old clipping and
  cover six sizes, enlarged text, multiline input and repeated IME transitions.
  Yoga is a test-only dependency and does not enter the APK runtime.
- Live production was using GPT-5 mini, not the repository's previous default.
  Sonnet 5 with the old voice prompt still produced punctuation and stock prose.
  A shorter voice with concrete examples produced direct Egyptian replies in
  three live samples (1.28–6.36 seconds). Voice v10 adopts that structure and keeps
  code/URL punctuation intact; it does not rewrite old replies or impersonate the
  instructor. Course and project defaults now share Sonnet 5 without an automatic
  lower-tier model fallback. Sonnet's optional thinking is explicitly disabled
  for `none`, and its unsupported temperature parameter is not sent.

Validation: 173 mobile suites / 930 tests, TypeScript and release ESLint passed.
The complete local backend run had 1181 tests and five environment-specific skips;
its only two failures were old prompt-text assertions, updated and rerun green
(2 tests / 34 assertions). This is not a new authenticated device acceptance:
Windows Computer Use initialization still fails with a missing kernel-asset path.

## Project presentation follow-up — 1.0.44

- The project page now has a top-aligned brief and editor before submission, then
  a clear review/result state with the brief available through an accessible
  disclosure. The continue action precedes the optional report, so a long
  conversation does not bury the student's route back to the course.
- Submission and feedback use the app's palette and readable body text. Removed
  the nested report card, desktop-like dashed upload target and inert reply input.
  Attachment removal and other actions have at least 48dp touch targets. The
  report-only reply action still explains the existing tier restriction.
- The report keeps its original paragraph breaks and full content width. Pending
  replies have an indicator even before an assistant message exists; partial
  replies and all existing retry/attachment/quota conditions remain intact.
- Android project entry uses KeyboardAvoidingView height behavior so the scroll
  viewport can shrink even while its parent retains the reel's paging height.
  This remains source/component verification, not native keyboard acceptance.
- Deployment 178 served the multi-course fix: command 100 read both enrolled
  courses for the previously failing account. The same command exposed an
  operator-edit error in the environment: the model assignment had joined the
  API-key line. The plain editor value changed without updating the site's editor
  state. Repair was applied through normal select-all/paste and saved, then the
  page was reloaded and its persisted values compared exactly before deployment
  179. No key or other credentials are recorded in this document.

The 1.0.43 artifact was built but not handed off; the new project UI is included
in the next artifact rather than asking the learner to install an interim build.

Validation: 175 mobile suites / 949 tests, TypeScript and release ESLint passed.
Production command 103 confirmed key authentication (200), Sonnet 5, no model
fallback and a real streaming response through OpenRouterService with web-search
availability. First partial arrived at 0.77 seconds and completion at 7.05 seconds
in that one sample. The stale authentication circuit was cleared only after the
key check succeeded. The sample still contained unwanted punctuation and excess
paragraphs, so voice consistency is not considered closed by this result.

## Project review follow-up — 1.0.45

Production command 105 found one committed submission for the reported project.
The server accepted it after 96 seconds through `graceful_fallback`, not through
a relevance evaluation. Command 106 confirmed the live controller returned HTTP
200 and `data.latest_submission.submission_status=passed` with continuation allowed.

- Mobile project reads unwrapped Axios but not the API body's `data` envelope.
  Resolution, report/thread hydration and attachment metadata now use the actual
  two-layer contract. Regression fixtures now represent the real HTTP response.
- Pending reviews no longer auto-pass after an artificial delay. A queued review
  examines the published project requirements and actual submission, accepts
  genuine relevant effort and asks for a new attempt for unrelated work. It does
  not assign a mastery score or generate a paid report for pass-only access.
  Review cost is recorded separately without debiting course message allowances.
- Provider failure is an explicit `review_unavailable` state, not a rejection or
  a perpetual spinner. Safe retry reuses the existing submission and request
  identity. Unknown paid outcomes are not blindly sent to the provider again.
- Temporary files stay available until a decision and any included report are
  finished. Cleanup preserves the decision metadata. Existing passed progress
  is not revoked. Retry and course-map refresh use the same server decision.
- Project upload storage work shares one request deadline. New uploads no longer
  issue unnecessary HEAD requests and failures return retryable JSON. This does
  not establish what caused the first uncommitted random-image upload to fail.

The production log also shows Nightwatch ingestion blocked by a quota response.
It is not evidence that the project review itself failed and is not resolved by
this code change. No billing plan or telemetry credentials were changed.

A live provider-only probe used two generated 400px images and the review
instructions: the matching blue circle returned `relevant_effort` in 2.27 seconds
and the unrelated red/green rectangles returned `needs_changes` in 2.26 seconds.
Both responses were valid JSON with a correct visual reason. Combined reported
provider cost was USD 0.004192. This verifies those two provider decisions, not
device upload latency or universal grading accuracy. It changed no learner rows.

Validation: all 177 mobile suites / 971 tests passed. TypeScript and release
ESLint passed. The complete backend run executed 1213 tests with three skips;
its two failures were expectations of the removed timed auto-pass. They now
assert that expiry cannot approve work and that a real decision precedes the
notification; both reran successfully (2 tests / 16 assertions). The new review
and upload tests include valid/irrelevant images, provider uncertainty, preserved
paid results, long document text, upload limits and shared-storage failures.

The pre-handoff production check caught a separate MySQL-only snapshot defect.
Command 109 proved that the persisted v3 project context had valid IDs and access
terms, but its hash depended on PHP object insertion order. MySQL JSON changed
that order: a newly captured in-memory snapshot validated before `CAST(? AS JSON)`
and failed after it. Restoring the historical writer order matched the existing
student row's ORIGINAL digest exactly. No row or learner progress was changed.

Snapshot v4 now canonicalizes object keys recursively, preserving list order.
Existing v3 rows are accepted only when their original digest matches a known
writer layout; no digest is rewritten and no mutable course data is substituted.
This central correction also covers report generation, report replies, displayed
project entitlements and project-to-portfolio eligibility. It cannot restore any
previously deleted payloads. The MySQL CI contract suite now includes the real
JSON round-trip regression, in addition to reordered/tampered snapshot cases.

### Production report follow-up — September 6

Deployment 181 (`8d63125`) and command 110 verified the old v3 submission and a
fresh v4 snapshot both survive the actual MySQL JSON round trip. The existing
submission remained passed, with the same review time and continuation allowed.
Backend CI 34002128280 passed, including the MySQL snapshot contract.

That check exposed a missing report-dispatch marker. Recovery now restores only
verified, report-eligible missing intents; it does not replay failed reports,
pass-only submissions or completed reports. Invalid candidates cannot consume
the batch ahead of valid reports. An already landed paid answer is recovered
before checking its original input, without another provider request. Missing
input without an existing answer is explicitly failed, not presented as a copy
of the progression note. Attachment read failures are handled within the job's
failure boundary before a new reservation or provider call, so a missing stored
file cannot leave the report indefinitely processing.

The actual report dispatch then failed after 45 seconds. OpenRouter's upstream
log for that request showed all six provider attempts rejected with HTTP 400 in
1.8 seconds. No report text arrived. The USD 0.025 local amount was reservation
fallback accounting, not verified provider billing. No blind second generation
was issued for that student submission.

Two independent defects were established:

- Image-only initial reports included an empty text block. A live comparison
  with the same generated JPEG reproduced HTTP 400 and the provider error
  `text content blocks must be non-empty`; omitting that block returned HTTP 200
  in 1.32 seconds, with reported cost USD 0.000642. No student files or records
  were used in this comparison. The report payload now omits only absent text.
- A known HTTP rejection with a still-open socket was treated as a transport
  timeout. The shared transport now stops at rejecting response headers and
  recognizes complete JSON error envelopes even under a misleading SSE content
  type. Real TCP regressions cover silence, fragmented JSON, SSE errors and an
  error after partial output. Partial output still prevents blind paid retries.

Transport diagnostics record only request identity, model, status, timing,
download count, visible-character count and generation identity. They exclude
keys, headers, prompts, uploaded bytes and raw exception messages.

The old failure path had already purged this student's temporary input when its
report failed. It cannot be regenerated from absent work. Provider failure now
retains report inputs for the existing bounded 30-day recovery window, without
extending that policy to successful reports or rejected project attempts.
Passed progress is preserved throughout; failure does not imply delivery.

Focused verification: 30 transport/stream tests (250 assertions), 15 streaming/
accounting tests (57 assertions), and 29 report recovery/presentation tests
(221 assertions) passed. Independent transport review found no blocking issue.
These are not authenticated device acceptance or a claim that the old student's
report was delivered. The Android artifact remains 1.0.45 / 12345678.apk; the
later changes are backend-only plus an iOS native version synchronization.
