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

Deployment 182 (`d31ce37`) succeeded. The post-deploy provider probe used the
published `AiPromptPolicy::projectReport` and `OpenRouterService` streaming path
with an in-memory generated JPEG, without an empty text block. It returned nine
partial updates, first partial at 1.07 seconds and a 328-character report at
4.58 seconds; reported cost was USD 0.005092. It changed no student rows. The same
command verified the original submission is still passed with continuation
allowed, an unchanged review timestamp, a failed old report and no retained
input. Recovering that old report still requires the student's actual work.

## Guest access, chat window and confirmed outcomes — 1.0.46

The September 8 report covers private guest navigation, a hidden chat composer
and a project rejection visible only on a subsequent attempt. The changes are
limited to those boundaries and confirmed counterparts, not another rewrite of
the server review or payment state machines.

- Guest taps on MyCorner, Wallet and Profile now ask for sign-in without
  navigating or mounting private content. A shared navigator boundary also
  covers direct routes while waiting for session restoration. Return intent is
  preserved through login, including the selected Profile tab. Public course
  browsing, existing chat access rules, support and legal routes remain intact.
- The chat's native Modal measures its own safe area. Keyboard avoidance handles
  the remaining overlap instead of assuming Android always resizes the dialog.
  Navigation-bar space is no longer forced to zero. Regressions exercise the
  installed React Native KeyboardAvoidingView and Yoga layout with resized and
  unresized windows, landscape, tablets and large text. Windows Computer Use
  initialization still fails with `failed to write kernel assets`; this is not
  an authenticated emulator or physical-device acceptance result.
- A received project decision no longer depends on successfully deleting its
  local outbox. Cleanup failures preserve the original request identity, are
  recorded through existing safe telemetry, and cannot adopt an old account's
  result. The same proven defect was corrected for course purchase/upgrade
  completion and confirmed portfolio-video claims. Pre-request persistence and
  unknown server outcomes remain strict; they were not converted into success.

Production readback found submission 2 for project 8, submitted September 7 at
23:31:57 UTC and reviewed at 23:32:00 UTC. Its relevance review returned
`needs_changes` with a concrete missing-evidence explanation, zero review retries
and no failed queue jobs. No current client-error record identifies the first
attempt's local failure. The cleanup failure was reproduced by a regression;
it is a demonstrated possible cause, not falsely attributed as a logged fact.
No student submission, review decision or balance was altered during this audit.

Validation for this change set: all 180 mobile suites / 992 tests passed,
including the failed-cleanup reproductions, same-identity video replay and
account changes during cleanup. TypeScript, release ESLint and release version
configuration checks passed. Android is 1.0.46 / versionCode 47 and the matching
iOS source version is 1.0.46 / build 44. Native UI initialization was retried once
after resetting the tool and failed with the same missing kernel-assets path;
no native walkthrough is claimed.

The internal Android build completed successfully in 2m 53s from clean commit
`e4cdf26a64576827ba07930658f455852cbc6f40`, already pushed to production origin/main.
The preserved numbered artifact is `mobile/artifacts/123456789.apk` with its
build-provenance JSON alongside it. Older numbered APKs were not overwritten.
APK manifest inspection confirmed version 1.0.46 / 47, minimum Android API 24,
target API 36 and armeabi-v7a / arm64-v8a / x86_64 support. Its API base is the
deployed Laravel Cloud `/api/v1/` endpoint, not the old developers' rokn.app API.

- Bytes: 79,649,847
- SHA-256: `91d585d9d516aebf2d70c254c2d3b2787141040ad5e21e468cbe43c5fa44f8fe`
- Signer SHA-256: `af332099abab71759a75a51db654f4595b86d6b8ddd86e602385cbbc89e9fe85`
- Built at: 2026-09-08T00:08:13Z

This is the existing internal-test signing profile, not a public distribution
release. No new backend deployment, Drive upload or device installation is
claimed. CI run 34171959516 was still running without failures at the last
read-only snapshot; local validation and APK build completed independently.

## September 8 — saved project decision, playback gestures and Gemini chat

Production submission 3 had a completed, settled relevance-review response in
usage event 21. It returned a concrete `needs_changes` decision inside one JSON
code fence. The strict raw-JSON parser rejected that envelope and presented an
unavailable review. The parser now accepts a single whole fenced JSON object
without accepting prose, multiple objects, invalid decisions or blank reasons.
Recovery uses the existing accepted response and request identity, not a second
paid generation. This is distinct from a network or queue timeout.

Additional reproduced boundaries corrected in the same submission flow:

- Failure to save/clean the local acknowledgement cannot stop polling an
  already accepted server submission. Failure to read local storage cannot
  discard the pending request and generate a new submission identity.
- Mandatory relevance review is independent of the optional report allowance.
  Exhausted report budget does not prevent completing a valid project; the
  report job still enforces its own allowance without making a report call.
- Pending report refresh slows after thirty attempts instead of stopping.
  A terminal unavailable review also observes a later server recovery without
  a new upload, respecting screen activity and account ownership.

The reel scrubber now retains its gesture and calculates movement from the
grant position. Paging no longer unmounts the preloaded next player. The initial
loading label no longer falsely describes recovery. Live samples from the
previous APK showed first frames around 1.2–3 seconds, not a measured speedup
from these changes. No Bunny plan or decoder/data-saver policy was changed.

Course chat now defaults to `google/gemini-3.8-flash`, as explicitly requested.
Project review/report retain their separate model. Gemini request settings use
supported thinking levels and omit sampling temperature. The shared server
voice is v11; its concise Egyptian-Arabic response contract is placed after chat
history, immediately before the question. No destructive punctuation stripping
is applied to code, links or math. A real deployed Gemini response still needs
verification; prompt instructions alone are not evidence of the result.

Local verification: all 183 mobile suites / 1003 tests, TypeScript, release lint
and version configuration passed. The combined backend run covered 201 tests /
1368 assertions: 199 passed, one Word fixture could not run because Windows
blocks ZipArchive, and one MySQL-only case was skipped under SQLite. There were
no assertion failures. Linux CI remains the gate for that environmental gap.
Source version is 1.0.47 / Android 48 / iOS 45. Deployment, live recovery, live
Gemini probe and the new APK are not claimed by this source-change entry.

### Deployment and live readback for 1.0.47

Source commit `e89e6e69ae7c3bc8cfd28a13259817f5d8ed7f39` passed Backend CI
34208934672, including its Linux dependency/schema/full-test stages, and was
deployed successfully as Laravel Cloud deployment 183. Only the default chat
model and its allowlist changed in the environment; the project model, provider
keys, empty fallback list and actual production output ceiling of 420 remain
unchanged. The model's legacy `none` setting is mapped to supported `low` thinking
by the new request adapter.

Cloud command 123 recovered submission 3 through the existing evaluation service
from its settled response. It is now `needs_resubmission` / evaluation `ready`,
with the original concrete missing-evidence explanation. Readback in command 127
confirmed event 21 remains the sole event for that request, with one provider
attempt and cost USD 0.005274. No new review was generated and no false pass was
granted.

Command 126 made one independent, streaming Gemini request using the deployed
course/lesson context and final response contract, without creating a student
turn or spending a student's message allowance. Its question was different from
the examples embedded in the prompt. First visible callback was 1787.9 ms,
completion 2972.5 ms, with six partial callbacks. The response used two concise
Egyptian-Arabic paragraphs without an introductory compliment or ordinary prose
punctuation. Provider usage was 1078 input / 98 completion tokens, USD 0.001176.
The generation metadata initially was not ready; one later read confirmed
`google/gemini-3.8-flash-20260902` and the same cost. These are one server-side
sample's measurements, not a mobile end-to-end or universal latency guarantee.

Post-deployment readiness returned HTTP 200 with database/schema/identity/cache
checks ready. Public course 3 details returned HTTP 200. The readiness route is
`/api/health/ready`, outside the versioned learner endpoint prefix.

APK build succeeded in 2m 47s from the clean source commit above. Artifact:
`mobile/artifacts/12345678910.apk`, with matching `.apk.json` provenance.

- Version 1.0.47 / Android 48, minimum API 24, target API 36
- armeabi-v7a / arm64-v8a / x86_64; production API verified in the Hermes bundle
- Bytes: 79,651,187
- SHA-256: `63c61c9fa1974b67518f323f28683287042f16a1a3d57510797a459702105171`
- Signer SHA-256: `af332099abab71759a75a51db654f4595b86d6b8ddd86e602385cbbc89e9fe85`
- Existing internal-test signature, APK v2 verification successful

Older numbered APKs remain unchanged. This is not a public-store release, a
Drive upload or a device-installation result. Mobile CI 34208934653 was still
building its Android/iOS jobs at the last snapshot, with no failures; the local
mobile full test/lint/typecheck gates and internal APK build had completed.

## September 8 — submission throttling, physical seek direction and sign-in sheet

This report's project-send failure was HTTP 429, not a demonstrated connection
failure or a lost acknowledgement. Production access logs show three refused
POSTs to project 8 at 09:44:50, 09:44:56 and 09:45:00 UTC. The subsequent POST
at 09:47:32 returned 202, followed by successful status reads. Submission 4
finished its review at 09:47:37 with `needs_changes`. Its storage write took
about one second. Cloud commands 128–130 read these records without submitting
a project, regenerating a review or changing student data.

The numeric Laravel throttle middleware used one user counter across unrelated
controller operations. A local regression reproduced eight chat status reads
blocking the first project POST. Numeric API counters now include the request
method and controller action. Resource IDs and endpoint aliases do not create
new quotas; intentionally shared named limiters remain shared. Existing limits
were not raised. The targeted backend run passed 37 tests / 351 assertions,
including the original failure, project-ID and purchase-alias quota continuity,
and availability of the read-only lookup after the upload quota is exhausted.

The mobile outbox now distinguishes 429 from an unknown upload outcome and
persists the server's Retry-After deadline across taps, resume and restart.
Unknown acknowledgements have a separate bounded, read-only lookup by exact
account, project and client submission identity. A failed lookup does not
authorize another multipart upload. Editing a still-uncertain attempt preserves
the new draft while settling the previous identity. This is additional recovery
hardening, not a claim that the successful production attempt lost its response.

The video seek calculations already used physical touch coordinates, but RTL
layout mirrored the rendered thumb. The timeline now explicitly uses an LTR
coordinate space and logical start positions while Arabic text remains RTL.
Regression coverage checks that moving right increases both time and the
rendered thumb position.

Android sign-in now opens a partial Custom Tab above the current activity via
a small native bridge. The existing OAuth state, callback ownership and account
completion flow remain unchanged. A compatible browser can display the 85%
height sheet; unsupported browsers/orientations may use a full-size Custom Tab.
This is not an embedded WebView. iOS keeps its existing system auth session.
The existing AndroidX Browser 1.6.0 dependency is now also explicitly available
at compile time. Gradle lock generation and release Kotlin compilation passed.

Final mobile verification passed all 183 suites / 1029 tests, TypeScript,
release ESLint with zero warnings and the release configuration contract.
Two old mock/source expectations were updated to the new browser transport
and accepted-draft ownership; behavioral cancellation and draft preservation
coverage remain in place.

Source version: 1.0.48 / Android 49 / iOS 46. Deployment, APK build and physical
device results are not claimed by this source-change entry.

### Deployed submission fix and in-app provider sheet

Commit `c1932dc8ef17f336e1bd18525583736da64e595d` passed Backend CI
34214495137: 1266 tests passed, 4 skipped, 12413 assertions. The independent
MySQL contracts passed 10 tests / 54 assertions. Laravel Cloud deployment 184
completed successfully. Readiness and public course 3 details returned HTTP 200.

Cloud command 132 verified the deployed numeric signature separates chat reads
from project POSTs while project IDs still share their submission quota. The
controller's exact-identity lookup returned HTTP 200 and the existing
`needs_changes` result. This called only the signature method and read-only
controller; it did not consume rate counters or issue a paid review. Command
131 first failed because the diagnostic constructed a localhost request;
the corrected diagnostic used APP_URL without weakening trusted-host checks.

An intermediate 1.0.48 / 49 APK was built from the clean commit above and
preserved as `mobile/artifacts/1234567891011.apk` with provenance JSON. It was
not handed off after the learner supplied the provider-selection screenshot.
Its SHA-256 is
`6b876cc9566dbd0afdc7914755e4efc67f3c74fd11e6312b0e484be8884c9bcf`.

The reference shows the app's provider-selection sheet, distinct from the
provider's account/consent UI. Login now uses a transparent native-stack modal
with the existing SocialAuthView rendered as a scrollable bottom sheet, using
Rokn's palette, server-provided provider order/recommendation and existing
policy links. No second authentication flow, duplicate screen or UI library
was introduced. Google/Facebook/TikTok retain PKCE and provider authorization;
Apple retains its native system authentication.

Guest private-tab taps open this sheet directly without a preceding native
alert or mounting the private screen. A private deep link presents it above
Home. Closing cleans abandoned login state and pops only the sheet, keeping the
mounted page underneath. Duplicate close/backdrop taps are ignored, and a
concurrently restored secure session is adopted rather than logged out. The
canonical guest reset remains the fallback when there is no underlying page.

The final 1.0.49 / Android 50 / iOS 47 source passes all 185 mobile suites /
1041 tests, TypeScript, release lint with zero warnings and configuration
verification. Sheet tests cover provider/close/legal actions, busy states,
safe-area and viewport changes; they are render/behavior contracts, not a
physical device or native-layout walkthrough.

The 1.0.49 / 50 internal APK subsequently built from clean commit
`0787e4c0ab1e51cea8124e43a3e3f7a4300eecf7` and was preserved as
`mobile/artifacts/123456789101112.apk`, with matching provenance JSON.
SHA-256: `122b947656d37f34cb809a98e43bded33f613eb78fa4d314ecece24f7c9c8179`;
79,655,851 bytes; existing internal signer; minimum API 24, target API 36,
armeabi-v7a / arm64-v8a / x86_64 and the correct production API. It was not
installed or uploaded to Drive. A later live auth-methods read advertised
Google and TikTok; this sheet change does not claim to activate Facebook.

## September 8 — account UID in settings and dashboard lookup

Rokn already returns its stable `users.id` in the profile and sign-in contracts,
and the dashboard already displays that ID. Settings now shows the account name
and `UID: <id>` for an authenticated learner, with a copy button that copies
only the original digits and reports success/failure inline. The UID text is
LTR inside the Arabic interface. Guest sessions, invalid IDs and provider-only
identities do not invent or display an account number. No migration, random
number, new API or identity registry was added.

The existing dashboard student search now accepts exact `UID: 123` and `#123`.
A raw number adds exact ID matching while retaining existing phone/name/email
search. It does not reinterpret numbers inside arbitrary phrases or match an
ID prefix. Existing student-role and active-account filters remain in force.
The targeted backend run passed 13 tests / 1011 assertions, including the
authorization matrix and existing student workspace contracts.

Mobile UID tests cover the existing session identity, guest/stale data,
unchanged IDs after name changes, clipboard contents, failure/retry and
feedback reset on account changes. Source version is 1.0.50 / Android 51 /
iOS 48; the final build and deployment are recorded after completion below.

Final local mobile gates passed 187 suites / 1049 tests, TypeScript,
release ESLint with zero warnings and the release configuration contract.

### Final UID deployment and APK handoff

Source commit `2908888f94f8f425df719c0c76d8c7ad255c857e` passed Backend CI
34217304386: 1269 tests passed, 4 skipped, 12428 assertions; the independent
MySQL suite passed 10 tests / 54 assertions. Laravel Cloud deployment 185
completed successfully in 1m51s, and readiness checks returned ready.

Live command 134 searched an existing student by `UID: 1` through the deployed
student read service and returned exactly `[1]`. The initial probe for account
6 returned no students; the follow-up confirmed account 6 is not in the student
role scope. The existing student/staff separation was preserved, not removed
to make the diagnostic pass. No account data was changed by either probe.

The final internal APK is `mobile/artifacts/12345678910111213.apk`, with its
matching `.apk.json` provenance, built from the clean source commit above.

- Version 1.0.50 / Android 51, minimum API 24, target API 36
- armeabi-v7a / arm64-v8a / x86_64; correct Laravel Cloud production API
- Bytes: 79,657,855
- SHA-256: `813da9c07ea6e984fc96ba20cdda10318f78d213610a400afad59fb5ff15d7f8`
- Signer SHA-256: `af332099abab71759a75a51db654f4595b86d6b8ddd86e602385cbbc89e9fe85`
- Existing internal-test signer, valid APK v2 signature, not public distribution

Older artifacts are intact. No physical-device installation/walkthrough or
Drive upload is claimed. Mobile CI 34217304214 was still running at the last
snapshot; the full local mobile gates and internal APK build had completed.

## Copy actions follow-up — source only

UID, course conversations and project reports now share an icon-only copy
control with a fixed touch target and a two-second checkmark. Clipboard errors
remain recoverable; success no longer adds a paragraph, changes the sheet's
layout or emits an extra Android toast. Native selection remains disabled over
the video surface. User text stays copyable after a failed send, and settled
partial chat text stays copyable without being mislabelled as a completed answer.

Project reports previously had no copy action. Computer-only course files now
show a copy icon and accurate accessible label instead of claiming to download
on the phone; native copy failures no longer blame the network. Actual mobile
downloads and the intentionally locked report-reply control are unchanged.

Verification: six targeted suites / 60 tests, TypeScript and changed-file ESLint.
No APK rebuild, device visual acceptance or backend deployment is claimed for
this follow-up. The APK and hash above still describe the preceding source.

## Course attachments — source/device lifecycle (source only)

The existing course attachment record/editor now separates `source_type`
(`upload` or `external`) from `platform` (`mobile` or `computer`). Historical
`course_pdfs` table and route names stay compatible; there is no second file
subsystem. Small internal documents, images and ZIP assets keep their validated
MIME, filename and extension and remain capped at 50 MiB. Large external files
download from their host, not through a PHP worker or into the APK.

The moderator can create, edit, replace, switch source, reorder, hide and delete
an attachment. Student actions renew the current attachment contract before
using it, including old identifiers after a staged course revision is published.
Phone actions use the existing native download flow; computer actions copy the
usable link. External metadata is inspected without buffering a file or sending
account credentials to its host. Share/login/confirmation HTML is not treated
as a downloaded file. Hosts requiring an interactive step receive an explicit
source-opening fallback, not a promise that every web page is a direct file.

The complete-operation review also fixed these proven omissions:

- A visibility toggle or drag order could be reversed by saving an already-open
  attachment editor
- Canonical-course to draft resolution changed the create receipt's parent
  identity, so a successful save could remain `processing` and fail on replay
- Clearing free-preview/final-project checkboxes omitted the field and preserved
  the previous true value; clearing all project submission choices silently
  preserved them
- File-type assumptions in previews, signed download responses, shared-storage
  migration and production checks did not describe external/non-PDF attachments

Backend integration gate: 60 tests, 534 assertions, one Office/ZIP case skipped
because this Windows PHP invocation lacks ZIP. After the final response-header
and file-policy changes, the affected 17-test subset passed 199 assertions with
that same one skip. ZIP is now explicit in Linux backend CI extensions. Both
headless Chrome authoring suites passed, including recovery, source switching,
replacement, visibility/order preservation and the unchecked-field regressions.

Mobile checks: 43 attachment/mapping/lifecycle tests plus four per-row busy
feedback tests passed, along with TypeScript and scoped ESLint. Android Kotlin
compilation (not an APK build) passed, and the merged manifest retains the
permission-protected DownloadManager completion receiver. The receiver was
checked against Android 7 and current AOSP contracts, not an OEM device matrix.
iOS source registration and response-header cancellation are implemented, but
iOS native compilation/device verification is unavailable on this Windows host.

The broader requested review remains active. This is evidence for these paths,
not a claim that every application journey is defect-free. No new APK, live
course-content mutation, production deployment or physical-device acceptance
is claimed by this source-only entry.

## September 8 — daily journey stale-response follow-through (source only)

The next bounded pass followed operations through navigation, remote writes,
read coalescing and local cache updates. It found these related gaps rather
than treating each visible symptom as an isolated screen problem:

- Course checkout kept an applied coupon after expiration, quota exhaustion or
  changed pricing while wallet credit was pending. Definitive quote rejection
  now refreshes the price and preserves confirmed wallet credit independently.
  Only a definitively rejected coupon is removed. A changed total always needs
  another explicit purchase confirmation; recovery never starts a debit or
  another payment. The waiting label describes repricing, not opening payment.
- Home search could accept B after the learner returned to already loaded A,
  leave its loading state stuck after cancelling a debounce, or confuse an
  unrequested/failed empty query with a successfully loaded Home. Result data
  and pagination now remain associated with the loaded query and late results
  cannot replace the active query. Failed Home followed by successful search
  is covered as well as normal catalogue entry.
- Portfolio background publication could restore an older title or removed
  media after a later successful edit. Per-project mutation ownership now
  guards finalize and reconciliation responses. Existing bounded retry waits
  through active editing and can still publish the newer item afterwards.
- Returning to Wallet could join a read whose balance/tasks snapshot predated
  an external reward. Real foreground and screen-return transitions use the
  existing single queued refresh. Initial focus stays coalesced and account
  changes/unmount discard old queued work.
- Saved-folder refresh after a successful write could join a pre-write list
  request. Mutations invalidate that read generation; stale readers join the
  current read, and updates to the existing local folder index remain ordered.

Read-only counterchecks did not establish the same missing transition in the
project submission/review return path, MyCorner, certificates or notification
read-state handling. Their existing attempt identities, generation guards and
mutation overlays were retained. These are scoped source findings, not claims
of a complete device, provider or production acceptance test.

Final combined gate for this follow-through: 25 affected Jest suites passed
143 tests. Full mobile TypeScript, scoped ESLint and `git diff --check` passed.
The Home cases and saved-folder stale read/cache-order cases were reproduced
as failures before their fixes. No new APK, Git push, live payment or production
deployment was performed for this entry; the larger review goal stays open.

## September 8 — interrupted chat, playback and logout (source only)

This pass followed three existing operations through their interrupted states:

- A project follow-up message could reach the backend while its POST
  acknowledgement was lost. The screen now reads the existing thread once and
  recognizes that exact `client_request_id` before resuming its existing reply
  polling. It never sends another paid message automatically. An unconfirmed
  result keeps the draft, uploaded attachment identities and request ID for an
  explicit retry. Definitive client errors do not enter this recovery path.
- A course refresh contains a project-thread summary, not its transcript or
  quota. Applying that summary to an already open thread erased the report and
  pending reply. The mapper now identifies this known summary source explicitly.
  The same thread retains its full data while applying the summary's current
  reply permission; a different project or thread still hydrates independently.
- Background, quality and source remounts reused the fresh-open near-end replay
  rule. A live position such as 17.5 seconds of a 20-second reel could restart
  from zero. Live restoration now keeps its bounded position, including zero,
  without changing the existing replay policy for a genuinely fresh opening.
- Local logout could announce success after a native credential deletion
  failed, and its retry then had no cached owner to delete. Durable failure now
  rejects and keeps the in-process owner for retry; `false` means only that a
  replacement session owns the device. All sibling native deletions settle
  before the session mutation queue accepts another login. Shared Settings
  cleanup checks session ownership between stages, so an older logout cannot
  reset a replacement account. Server account deletion and device logout have
  separate truthful outcomes when only local cleanup fails.

The project summary producer was checked in `ProjectSubmissionPresenter` and
`CourseResource`, and all production callers of token-guarded session deletion
were reviewed. The existing API 401 retry and replacement-owner behavior were
retained. The adjacent partial-report test needed only its missing native
Clipboard mock before its actual assertions could run.

Final root verification: 22 affected Jest suites passed 204 tests, full mobile
TypeScript passed, scoped ESLint reported no warnings and `git diff --check`
passed. Regressions cover lost acknowledgements, summaries arriving during a
reply, revoked reply permission, real player-controller remounts, partial and
delayed native deletion, logout retry and same/different-account replacement.
These are local source checks, not a live provider, device or production
acceptance result. No APK, push or deployment was performed for this entry.

## September 8 — authoring retry and catalogue continuation (source only)

The next pass followed moderator uploads through student discovery and profile
editing. It changed only three demonstrated operation gaps:

- The direct-video upload editor treated an allocated claim as a completed
  transfer. After cancelling or interrupting an upload, ordinary Save submitted
  that incomplete claim rather than resuming bytes. Allocation identity and
  locally completed transfer identity are now separate. Save resumes the same
  TUS upload, including after reload and file reselection; a completed upload
  or unchanged lesson still saves without uploading again. The backend's
  incomplete-upload rejection remains in place.
- Search shows one horizontal course row, but its next-page request was wired
  only to vertical page scrolling. On a short page, learners could not reach
  results beyond the first twenty. The search row now connects its native end
  callback to the existing guarded pagination. Both search and Home expose an
  explicit More action while the current query has another page. Curated Home
  rows do not individually trigger global pagination on mount, avoiding an
  eager fetch of every course merely because some rows are short. Existing
  deduplication, revision ownership and explicit failure retry are retained.
- Profile name and headline remained editable while their previous values
  were being saved, then the successful response closed the screen and lost
  the newer text. Those two inputs now follow the same saving lock as the
  existing avatar and Save controls. Failure retains the draft and unlocks the
  editor; retry submits the learner's corrected values.

The bounded progression/certificate review found existing coverage rather than
another demonstrated defect: authored projects remain required regardless of
their graduation flag; review outcomes own completion; courses without projects
can finish through their lesson evidence while empty courses cannot qualify.
Issuance recovery retains an accepted pending request and guards account/screen
changes. Profile/public-share checks likewise retained the current identity
revision, unique avatar URLs and works-only public payload. These source
counterchecks are not a new live-service acceptance result.

Final root mobile gate: 11 suites / 51 tests passed, full TypeScript and scoped
ESLint passed. The Bunny recovery browser regression failed before its fix and
passed afterwards, including interrupted/reloaded Save and completed-claim
counterexamples. The adjacent course-studio authoring browser suite passed.
An obsolete portfolio test assertion was updated to the current synchronous
publication invalidation wiring; the actual stale-publication behavior suite
also passed. Native horizontal-end wiring is covered, but a physical-device
gesture test is not claimed. Diff checks passed. No APK, push, production
deployment or live course-content mutation was performed for this entry.

## September 8 — public bootstrap, download cancellation and notification intent (source only)

This pass closed four demonstrated operation gaps:

- Public settings, managed information pages and installation-scoped feature
  discovery inherited the personal session wait and response-owner rejection.
  They now explicitly use the existing session-neutral request option. A slow
  native account restore does not delay these reads, and completing login does
  not discard their valid public responses. Personal balances, owned courses,
  messages and optional-auth course details remain session-owned; no global
  HTTP default changed.
- The installed iOS RNFS implementation can retain its native promise when it
  produces resumable data during interruption or cancellation. The existing
  private attachment download now settles its JS operation on those events and
  releases the row for retry. Each attempt owns its staging path; a late native
  completion cannot share or remove the retry's file. Reuse is limited to the
  same account/attachment/version and a completed expected-size file, excluding
  cancelled targets still owned by a late callback. Legacy staging recovery and
  Android DownloadManager behavior are retained.
- A slow first notification could override the second notification the learner
  had chosen. The latest tap now owns navigation and pending-marker changes,
  while duplicate delivery of the same intent remains coalesced. Durable and
  native cold-start reads also retain account/generation ownership across
  logout. A failed cold-start navigation keeps its exact pending identity for
  foreground retry; serialized marker writes cannot overwrite a newer choice.
- Starting or resuming an expired reward task left its stale card on screen.
  The explicit `task_unavailable` response now requests the existing queued
  wallet reconciliation. Transient errors retain normal retry, and an old
  account cannot refresh or open a replacement account's task. No reward claim,
  provider configuration or verification rule changed.

Final root checks: 19 affected Jest suites passed 168 tests before the final
native cold-start ownership sibling was added; the final notification suite
then passed all 23 cases. Full TypeScript, scoped ESLint and diff checks passed.
Regressions reproduced the public-session mismatch, never-settling native
download, stale notification navigation and unavailable-task refresh gaps.
These are source-level simulations, not iPhone, live-push or provider acceptance.

Read-only counterchecks retained the works-only share payload, readiness-gated
portfolio publication, stable public media redirects and shared practical vs
theoretical certificate QR destination. The adjacent saved-folder review found
a separate ordinary-flow defect: selecting an older folder filtered only the
global first page and could falsely display an empty folder. That repair is
tracked next, not counted as complete here. A lower-priority confirmed report
retry capability gap after temporary input retention expires is also still
open; no archive or retention-policy expansion has been made.

No APK, Git push, deployment, live notification or provider mutation was made.
The wider end-to-end review goal remains open.
