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
