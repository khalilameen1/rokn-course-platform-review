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
