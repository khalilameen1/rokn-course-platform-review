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

## September 8 — saved library as one operation (source only)

The saved-library follow-through covered the existing API, selection, pagination,
mutations and offline cache together. It adds no new storage or server feature:

- Selecting a folder now reads that folder's paginated endpoint, not a filtered
  global first page. One mapper and one read-ownership path handle both views;
  the folder response supplies its authenticated outer membership, while the
  global response retains every membership and its distinct-lesson total.
  Folder chips use server counts instead of the number of rows loaded so far.
- Folder navigation stays available during loading and failures. A definite
  folder 404 returns to All, but an older cached index cannot overrule a
  successful specific-folder response. An empty requested page beyond the
  current last page ends pagination after deletions rather than trapping retry.
- A real backend endpoint test exposed a missing integer conversion that the
  screen mocks could not: query `per_page` remained a string after validation
  and caused a strict service-argument TypeError. The folder controller now
  performs the same validated conversion as its global-list sibling. Other
  typed pagination boundaries were checked; no second counterpart was found.
- Global and folder reads retain the account and a confirmed-mutation revision.
  A read overtaken by an acknowledged deletion reads the same page again; it
  never repeats the deletion. Cache replacements and removal transforms share
  the existing cache's ordered write path, and offline fallback waits within
  the existing storage budget. Concurrent removals no longer restore each
  other's rows, and a late GET cannot restore a confirmed deletion to the
  displayed page or disk cache.
- The folder index cache stores normalized names for optional cover/count
  fields. Its reader now intentionally accepts those names as well as the old
  server-shaped cache, preserving covers and zero counts through offline reads
  and queued folder edits without changing the remote response contract.
- The focused UI regressions also covered a refresh arriving before a pending
  deletion's acknowledgement. Confirmed removal is idempotently reasserted
  locally; rollback only restores a row/count still absent. A response already
  reflecting the deletion is not decremented twice. Folder deletion likewise
  removes a restored stale chip and retains rollback on failure. No extra
  DELETE request or unconditional full refresh was introduced.

Final root gate for this and the preceding native-response batch: 28 mobile
Jest suites passed 240 tests; full TypeScript, scoped ESLint and diff checks
passed. The actual backend SavedFolderEndpoint suite passed 14 tests / 59
assertions, including the outer-folder contract and empty exhausted page. The
per-page 500, missing old folder, cache races, metadata round-trip and pending
deletion UI races were demonstrated before their corresponding fixes.

Read-only checks of social-login completion, exact-course entitlement, preview
vs ownership, purchase/project gates and payment acknowledgement recovery did
not establish another ordinary-flow defect. Catalogue and notification
pagination already handle exhausted results. These counterchecks and local
regressions are not a live Google, Kashier, iPhone or production acceptance test.
The confirmed lower-priority report-retry capability gap after temporary input
retention expires remains open for a later pass. No APK, push or deployment was
performed; this is a source checkpoint, not completion of the broader goal.

## September 8 — project report retry and delivery as one operation (source only)

The open retention/retry finding was followed through the learner capability,
locked server mutation, paid-result ledger, worker publication and mobile
acknowledgement. The same publication boundary was checked in report follow-up
messages and ordinary course chat. No project archive or retention extension
was added:

- A passed submission whose temporary inputs were deliberately purged no
  longer offers a new provider call that cannot succeed. A durable landed or
  accepted answer still replays its existing request without those inputs,
  another provider request, or another charge. Existing reasons and retry caps
  are retained; a queued retry continues to protect its inputs from the sweep.
- The presenter and retry mutation now use the same service decision. Expired,
  revoked and refunded enrolments previously advertised an actionable retry
  that the mutation rejected. Both sides now agree, including after input
  retirement. The decision rechecks the current locked submission for writes,
  uses its captured owner/enrolment for the paid event, and preserves duplicate
  request identities and the existing error states.
- The initial worker now saves the ready marker and the complete report
  thread/message in one transaction. A failed message save cannot leave a
  committed ready marker with an incomplete report. Publication also returns
  whether this execution still owns the write; an overtaken worker cannot mark
  an unpresented paid result as consumed or purge its input. Recovery reuses the
  stored answer instead of generating another one.
- The scheduled recovery includes older ready markers with an incomplete
  existing thread or initial message, not just missing threads. A completed
  initial report is not redispatched for a pending follow-up. The existing
  recovery delay is unchanged.
- Mobile report retry waits for the POST acknowledgement and applies the
  validated submission response before polling. A pre-acknowledgement read of
  the old failed state can no longer stop the new report's polling. A lost
  acknowledgement reconciles by reading, never an automatic second POST;
  account/project changes and unmount retain ownership guards. Passed-course
  continuation and report-only tiers remain independent of report delivery.
- Report follow-up completion now returns success, and all its completion
  callers retire the durable paid answer only on that success. Local fault
  injection reproduced temporary account deactivation after settlement but
  before publication: the answer now survives and publishes after reactivation
  without a new provider call or usage increment. This is a concurrency
  regression test, not a claimed production incident. Normal course chat
  already used the guarded completion pattern and was not rewritten.

Before/after regressions demonstrated retired-input retry, unavailable-plan
capability, incomplete legacy presentation, premature ready publication,
mobile retry ordering and lost follow-up presentation. Final root targeted
backend gate passed 69 tests / 572 assertions. A broader 98-case run first hit
five local PHP extension-loading errors (GD and ZIP), not application failures;
GD was enabled for the targeted gate, and all five relevant adjacent image and
text/DOCX checks passed with GD/ZIP enabled (41 assertions). Both extensions are
available in the current PHP 8.4.24 runtime; no source workaround was introduced
to hide these environment errors. All remaining cases in that broader run
passed. Mobile verification passed 17 affected Jest suites / 155 tests, full
TypeScript, scoped ESLint and diff checks.

The source changes are verified locally, not accepted against live Google,
Kashier, AI providers, a device or production. No APK, push, deployment or live
account change was performed. The broader operation-review goal remains open.

## September 8 — operation ownership across playback, credit and saved counts

This source checkpoint closes three reproduced daily-flow failures, without
replacing working provider, authoring or navigation implementations:

- Attachment prompts previously cancelled their pending storage read on every
  playback tick after recording the check as complete. A slow read or failure
  could therefore suppress the prompt for the rest of the course. One owned
  check now survives ordinary progress; course/account changes, unmount and
  leaving the trigger window retire it. A delayed manual open also belongs to
  its original scope. Automatic and manual presentation coalesce, the existing
  presentation grace remains, and only actual visibility records the prompt as
  seen. Three regressions failed before the fix; the expanded lifecycle cases
  cover retry, seeking, unmount and delayed session restoration.
- Confirmed direct-checkout credit now emits through the existing once-only
  operation event, rather than relying on the initiating Wallet remaining
  mounted. A replacement Wallet or CourseDetails receives the result even when
  foreground recovery joined that checkout. Account/session ownership is
  checked before notification. The mounted initiating Wallet retains its own
  single refresh; native billing retains its existing verified event. Actual
  shared checkout/coordinator and screen hooks reproduced three failures before
  the fix, with HTTP/browser/storage primitives mocked, not a live payment.
  The mounted course intentionally retains one full credit reload plus its
  exact purchase requote: one bounded additional wallet read is preferable to
  threading suppression state through unrelated hooks. No second debit or
  automatic course purchase was added.
- Saved row pagination no longer owns server folder totals. A refresh could
  replace the first page before DELETE acknowledgement, leaving an old row
  absent while its folder count remained present; success kept the old total,
  and failure could increment it twice. Count snapshots and row restoration
  now have separate ownership. Only a replaced count during a pending removal
  requests a fresh authoritative index; ordinary removals do not add a full
  refresh. A failed reconciliation leaves that count unknown, not a guessed
  zero or a restored deleted row. Both pending-deletion cases were red first.
- The same membership mutations previously left stale totals in the disk
  index. One acknowledged-mutation helper invalidates only affected counts
  through the existing ordered cache; global unsave invalidates all counts
  because its memberships are unknown. Names/covers and unrelated known totals
  remain. Four service regressions demonstrated the save, folder removal,
  watch-later and global-unsave paths. Fresh-only index reads cannot silently
  accept an offline cached total, and an offline read waits for queued repairs.
- Independent review caught an unbounded storage wait in that initial cache
  change before commit. Seven additional red regressions covered confirmed
  mutations, fresh network reads and offline reads with stalled native storage.
  Best-effort cache callers now use the existing bounded wait; the raw write
  queue stays intact until its actual writes settle. The whole offline ordered
  read is bounded, so timing out cannot expose the old count. A late-write
  regression proves old writes still precede the repair and newer server data.

Final root verification passed 17 affected mobile Jest suites / 177 tests,
full TypeScript, scoped ESLint and diff checks. Read-only counterchecks found
no additional matching premature-once-marker defect in Home engagement,
daily-reward attempts, reel reminders or notification permission handling.
Dashboard attachment optional-field clearing and staged publication/version
paths were inspected without a new demonstrated defect; they were not rewritten.

These are local source checks, not live provider, production or device acceptance.
No APK, push or deployment was performed. The broader goal remains open; the
next nonoverlapping operations are task rewards, profile identity propagation
and contact-message delivery. Their in-progress work is not part of this
checkpoint's verification claim.

## September 8 — retired task rewards and authoritative profile clearing

Two subsequent, separately owned operations reached a source checkpoint:

- A WhatsApp task could be opened while active, deleted from the real admin
  route, rejected by the regular claim endpoint, then still credited by its
  already-open inbound link. The HTTP regression reproduced 43 coins instead
  of zero. The shared `CoinEarningMethod::isAvailableNow` decision now excludes
  a soft-deleted campaign. Historical lookup remains necessary for token and
  earned-receipt replay and was not removed. The number can still be linked;
  a retired/disabled/expired/exhausted reward is not paid. Previously earned
  rewards remain recorded and idempotent after campaign retirement.
- Clearing a portfolio headline could resurrect the older portfolio text in
  the app because an explicit empty account value was treated as missing. The
  existing profile revision still chooses the newest owner; fallback now
  applies only to an absent value. Two actual-hook cases reproduced the empty
  session/fresh response failures. The profile response mapper had the same
  behavior for nullable headline and job title during updates; explicit server
  null/empty now wins over the submitted fallback, while genuine omission
  retains the fallback. Required name, avatar upload, issued certificate holder
  snapshots and public portfolio publication rules were inspected and retained.

Root verification passed the four profile suites / 20 tests and four backend
reward suites / 27 tests / 276 assertions. The agent also verified 50 adjacent
mobile wallet/commerce tests; scoped lint, type and syntax checks passed. Reward
fixtures used the actual task start, versioned admin deletion, inbound handling,
claim and wallet routes with outgoing jobs faked and stray HTTP prohibited.
This was not a real WhatsApp message or production wallet transaction.

No APK, push or deployment. Contact delivery, notification acknowledgement and
portfolio-work cache coherence remain in progress in separate owned files and
are not covered by this checkpoint's completion statement.

## September 8 — portfolio cache coherence and notification read acknowledgement

- Portfolio writes previously changed the server/current screen without
  invalidating the existing aggregate cache. A deleted published work could
  reappear with stale sharing readiness on an offline library remount. A GET
  started before deletion could also write the old work back to disk. Both
  cases failed using the actual portfolio service and library hook before the
  fix. The existing aggregate now has a confirmed-mutation revision and ordered
  writes. Create, edit, finalize, media append/removal and work removal
  invalidate it after acknowledgement, including idempotent not-found deletes;
  rejected writes do not discard the cache. An overtaken GET only repeats its
  read, never the mutation. A fresh authoritative GET makes the aggregate
  usable again after persistence; no publication state or counts are invented
  from local edits. Cache reads have the existing bounded storage budget, and
  native cache writes cannot hold a successful server mutation open. Delayed
  disk writes retain their actual order rather than releasing the queue early.
- Notification mark-all-read previously acknowledged whichever rows were on
  screen when its POST returned. A notification delivered after the server's
  update could therefore become falsely read after a late acknowledgement,
  including in the local cache and subsequent refreshed rows. The local
  acknowledgement now owns only the IDs captured for that request. New rows
  retain the server's read state; old pre-acknowledgement reads still cannot
  undo the acknowledged originals. Actual-hook regressions cover late delivery,
  failure/retry, duplicate requests, account replacement, relogin and unmount.
  The admin payload, scoped server read routes and existing push-tap ownership
  were retained. No notification-delete feature or new protocol was invented.

Root verification passed 11 affected mobile suites / 64 tests (ten suites / 57
tests plus the seven-case learning async isolation suite), full TypeScript,
scoped ESLint and diff checks. This is source/cache-state verification, not a
physical device reboot with failed storage, real push delivery or public upload.
No APK, push or deployment. Contact delivery, device-session actions and daily
reward settlement are the next separately owned in-progress operations.

## September 8 — confirmed delivery and cross-screen operation ownership

The three remaining operations reached a local source checkpoint without
replacing their existing server routes, account model or payment workflow:

- A successful feedback POST could appear failed or stay busy when receipt
  persistence or draft cleanup failed or stalled. Delivery now follows the
  validated server acknowledgement; ancillary persistence has a bounded wait
  while its raw per-owner queue keeps actual write order. An untracked guest
  retains the accepted receipt/token and original recovery draft, can open and
  reply to that case, and retries local tracking without another POST. The
  recovery draft also retains its original source screen so a cold replay does
  not change the server idempotency fingerprint. Guest migration waits for the
  relevant receipt tails, merges the latest account receipts and does not
  remove the recovery source prematurely. Signed-in cases remain recoverable
  from the existing account index. Four initial delivery cases were red before
  the change; the final delivery suite covers fourteen cases. A separate agent
  checked receipt access, cold replay and migration integration read-only.
- A device-list refresh could restore a revoked session after DELETE success,
  and an interrupted refresh could leave its spinner running. Only one
  requested refresh now follows an owned revocation; older reads cannot own
  its result. Device confirmation dialogs belong to the account and screen
  visit that opened them, including the interval awaiting session capture.
  The same demonstrated stale-dialog problem in watch-history clearing now
  captures its original boundary and rejects a replacement account, relogin,
  screen visit or duplicate confirmation. Existing offline history-clear
  recovery and backend device grouping were retained. The old device test had
  an optional no-op button lookup; it now invokes and asserts the actual
  action before checking the delayed result.
- Daily and explicit task reward claims could finish after their originating
  screen was gone, leaving a newer Wallet or CourseDetails at the old balance.
  A small owner-scoped settlement signal is emitted once inside each validated
  shared claim, including zero-award replays that confirm a lost receipt. It
  invalidates existing readers; it never initiates a purchase or re-awards
  coins. A wallet GET overtaken by an acknowledged claim is read again once,
  including imperative purchase requotes. A second intervening mutation fails
  that read rather than publishing a known stale snapshot. There is no wait
  for an unconfirmed daily claim. Task-button busy and catalogue refresh
  semantics stay intact; the still-mounted task screen can perform one bounded
  extra read, not another claim. Transport/contract failures and old account
  epochs emit no settlement. Two replacement-screen claim cases were red
  before the producer-only change; independent integration review found no
  further blocker in the affected signal path.

Final root verification passed all 21 explicitly named affected mobile suites
and 207 tests together, full TypeScript, scoped ESLint and diff checks. The
support agent also ran the existing backend support gates: 19 tests / 92
assertions and the anonymous-feedback contract case / 10 assertions, without
backend edits or live requests. These are source-level checks with controlled
transport/storage timing, not acceptance on the user's phone or production.

No APK, push or deployment was performed. This checkpoint does not establish
that every feature is complete or that the wider application is defect-free.

## September 9 — delayed actions across course publication

- The external-attachment fallback's later "open source" tap could bypass the
  descriptor refresh used by the original download. Five failing action tests
  reproduced opening the old URL after replacement, platform/source changes,
  hiding or revoked access. That delayed tap now resolves current eligibility
  and routing. External mobile sources open the current URL; computer files
  use the existing copy flow. A change to an internal mobile file offers an
  explicit download action, not an unexpected native write. Its next tap uses
  the existing fresh-descriptor operation. Independent review also reproduced
  an invalidated tap adopting a newer download generation while awaiting
  session capture. The entry point now captures its generation before waiting
  and checks it before joining an existing flight. A new later tap still works.
- A lost course-chat acknowledgement followed by a missing turn status could
  make an explicit Retry allocate a second request identity. The actual-hook
  regression was red before the change. Missing status now re-sends the same
  identity on explicit action only; a confirmed terminal retryable failure
  still starts a new attempt. Existing server uniqueness, fingerprint checks
  and paid-call settlement were retained, not replaced with question matching.
- A project review could poll an archived project ID indefinitely after staged
  publication, or discard a fresh course map because its old project ID was
  absent. The existing owned course-revision reload signal now handles that
  GET response. Current project membership retires old watchers, and the
  authoritative same-course map can replace archived IDs. Independent review
  found two further late-callback failures: an old response starting a map read
  after cleanup, and an old map result poisoning the pending-project marker so
  an evaluating replacement failed to resume after blur/refocus. Actual-journey
  red tests covered both; the same watcher ownership check now guards those
  writes after each await. No accepted submission is re-sent or evaluated again.
- An emptied home row could show zero courses but refuse deletion because an
  internal course revision still held its old classification snapshot. The
  real dashboard route reproduced the failure. Deletion now checks canonical
  courses, retaining the existing block for real hidden or visible courses.
  Revision snapshots are detached by the existing classification FK cascade,
  not by deleting course content. A publish-after-deletion regression preserves
  the staged merge behavior. To avoid reversing publication's lock order, the
  existing curation lock helper includes revision canonical owners before the
  classification lock; a newly arrived unowned membership requests a retry.
  Query ordering and controlled membership interleaving were verified in
  SQLite. This is not a live MySQL concurrency result.

Root verification passed 19 explicitly named affected mobile suites / 135 tests,
full TypeScript, scoped ESLint and diff checks. The three affected backend
curation/merge/authorization suites passed 21 tests / 1,088 assertions. Agents
also ran the existing attachment backend gates (20 tests / 237 assertions),
project completion/projection gates (15 / 43) and settled course-chat recovery
gates (3 / 20), with no live provider calls. No APK, push or deployment.

One distinct project path remains open: a not-yet-accepted draft submitted to
an archived project gets `course_revision_changed`, but its editor currently
labels any 409 as unmet prerequisites. Reloading alone would switch draft keys
and hide the prepared note/files. Repair must use actual surviving project
lineage, preserve editor drafts and existing destination drafts, show current
requirements and require explicit submission; it must not migrate or replay
an uncertain outbox as a new attempt. The existing public-ID/exact-key lookup
already recovers ordinary lost acknowledgements. The narrower sequence of a
lookup returning 404, an original acceptance arriving, and a retry receiving
409 has not yet been reproduced and must not be described as proven failure.

## September 9 — preserve project work across publication and failed saves

The previously open editor path now has one explicit transition, separate from
an accepted submission's existing receipt/review path. No project, course,
submission, public receipt or feature was deleted by this checkpoint.

- Project revision responses identify the original project and its surviving
  current project/section through real staged-publication mappings. Deleted or
  retyped projects have no fabricated destination. A short shared canonical
  course read keeps identity, ownership and published version coherent when a
  second publish arrives during the response. Metadata does not bypass current
  enrollment or progression checks. The same revision response is returned if
  publication wins while an upload is being staged, not a false access error.
- The formerly unproven lost-ACK sequence was reproduced: initial receipt
  lookup missing, original acceptance arriving, then a retry getting revision
  409. The transport now checks the exact original project/client key again.
  Found receipts remain original receipts; an unavailable lookup or an older
  server's unmarked missing response retains the uncertain outbox and files.
  Only a marked rejection plus confirmed missing receipt can expose draft
  recovery. No new submission UUID or automatic POST is created by recovery.
- That marker depends on a server guarantee, not optimistic client handling.
  Admission takes the learner lock followed by a shared canonical course lock,
  then validates fresh project-section ownership before inserting the receipt.
  Publication takes the exclusive course lock. Completion now follows the same
  learner-before-course order. Existing receipt replay and paid-work identities
  remain intact. The marker is emitted only by the rejected submit endpoint.
- The editor retains prepared text and readable files even when the newly
  published input requirements disallow them. They remain visible/removable;
  incompatible work blocks explicit submission instead of disappearing during
  hydration, background save or unmount. A changed project presents a separate
  review action. A deleted project explicitly lets the learner review/save the
  source work before opening the updated course, without inventing a draft
  archive UI or another project destination.
- On that action, the original project's current lineage is read again before
  choosing a destination. The existing ordered draft locks preserve the full
  source and copy to the destination only after its file references and storage
  are durable. A different existing destination requires explicit replacement;
  its displayed raw snapshot is compared again under the lock. Confirmation is
  bound to the original account, project, active screen visit and destination
  identity, so newer destination edits or another publish require a new choice.
  Network/storage failure does not navigate or resubmit. Account replacement,
  unmount and leaving/returning to the same mounted card cannot apply an old
  confirmation. Normal background draft saving is unchanged.
- Navigation anchors the replacement only while the original project is the
  active card. Reordered reels do not send the learner to an old numeric index;
  users viewing another item are not redirected to a background review. If
  another publish overtakes the prepared destination before the map arrives,
  the loader retains the source editor instead of committing a map in which
  the prepared draft cannot appear. This checks the full outline, not just the
  accessible feed, so an existing gated project remains a valid identity. The
  existing loss-of-entitlement redirect still runs first.
- Review of the copy exposed a pre-existing ordinary-save defect too: replacing
  file references before a failed draft write could allow another owner's
  cleanup to delete files still named by the previous durable draft. Two failing
  tests used the actual reference registry and cleanup implementation. Both
  ordinary save and confirmed replacement now retain previous plus new files
  until the new record commits. Reference trimming afterward is maintenance;
  its failure cannot be reported as a failed save. Empty-save deletion similarly
  releases references only after durable record removal.

Root mobile verification passed 20 explicitly named affected suites / 157 tests
together, full TypeScript, scoped ESLint and diff checks. The final seven-case
navigation suite was also rerun after the optional-projects type correction.
The final root backend gate passed all eight named project admission, lookup,
upload-failure, completion, presenter, evaluation and projection suites / 97
tests / 949 assertions together after all controller changes; PHP syntax also
passed on the four affected backend files.
These checks cover controlled transport/storage ordering and real application
hooks, not physical-device fault acceptance. Backend publication tests use real
HTTP handlers and staged graph swaps with a controlled SQLite lock-intent
scheduler; they are not evidence of a live multi-connection InnoDB stress run.

No APK, push or deployment. This remains a local source checkpoint, not proof
that every application operation is complete or defect-free. Deploy the paired
backend contract before relying on the new client's definitive missing-receipt
handoff; an older unmarked backend is deliberately treated as uncertain.

## September 9 — preserve unread drafts and unblock paid-course loading

This checkpoint preserves the existing features, submission receipts, request
identities, account isolation and accepted project results. No feature, screen,
course, project or stored learner record was deliberately removed. Moderator
attachment editing/replacement/publication was also inspected; no new defect
was established in that lane, so it was not rewritten.

- Native file inspection now distinguishes a confirmed missing file from an
  operational read failure. Missing-file codes were checked against the local
  Android/iOS RNFS sources. Unknown native errors propagate instead of marking
  an existing attachment absent. Real loader/registry tests cover all seven
  consumers: project submissions, project replies, portfolio drafts, portfolio
  media outbox, course chat, support drafts and support replies.
- Loader parsing no longer catches storage/repair/ownership failures as corrupt
  data and deletes valid work. Confirmed invalid or expired records still follow
  their existing cleanup policy. Missing-file repair commits the surviving
  record before releasing obsolete file ownership; failed repair writes retain
  the previous durable draft. The native helper changes alone were insufficient:
  all affected editable callers also had to distinguish failed restoration from
  a successful read that found no draft.
- Project, report-reply, portfolio, course-chat and support editors now expose
  local restore failure and an explicit retry. An unread empty editor cannot
  autosave over the original work, open a picker or submit as though hydration
  succeeded. Existing accepted project progress/report remains independent of
  local draft readiness. Presentation tests invoke the actual restore buttons
  without submission, review or paid-message calls.
- Retry accepts a fresh session epoch for the same owner, but ignores stale
  account/project/conversation callbacks and responses. The project hooks retain
  the failed attempt's owner and capture one fresh boundary for the retry itself.
  Support conflict restoration also invalidates an earlier autosave still
  awaiting the native account hash; checks after that await prevent an old body
  replacing the newly restored alternate. Both overwrite cases were reproduced
  using the actual conflict/draft services before the fix. Restore does not
  create a new logical request or replay an accepted submission automatically.
- MyCorner previously waited indefinitely on a local dashboard cache read or
  write even after the real paid-course HTTP response arrived. Existing bounded
  storage waits now allow fresh courses to appear. Native writes remain ordered
  after the caller stops waiting; request ordering and same-scope logout cleanup
  prevent a late old write replacing current data. Failed required HTTP reads
  still report failure rather than turning an unavailable cache into ownership.

The final root gate passed 32 explicitly named affected mobile suites / 282
tests together, full TypeScript, scoped ESLint and diff checks. The first root
invocation named one existing architecture test with the wrong .tsx extension;
that invocation error was corrected to its actual .ts path before the complete
green gate. The 53-case real-loader suite, 13-case project hydration suite,
11-case chat/support hydration suite, six portfolio hydration cases and seven
MyCorner storage cases are included in that total, not additional counts.
Independent agent reviews checked the source boundaries and surrounding valid
paths. These are local controlled native/storage/HTTP tests, not physical-device
or live provider acceptance and not proof that every application defect is gone.

No backend source change, APK, push or deployment in this checkpoint.

## September 9 — daily authoring, accepted access and received content

Four independent operations were inspected from their real callers and shared
implementations. Existing working features and layout were retained; this is
not a replacement application or a completion claim for the broader goal.

- After inserting/deleting a sibling, the inline section/module editor could
  submit its stale hidden `order` when only the title/content was edited. The
  server correctly treated that submitted order as an explicit move, silently
  undoing the moderator's layout. Four actual-browser cases reproduced this.
  These two content-only PATCH forms now omit hidden order; create/insert and
  explicit drag-reorder requests retain their positions. Real PHP HTTP tests
  verify the existing locked-current-order fallback, video identity, caption,
  preview checkbox, terminal gateway project and outline/learner sequence.
  Reload and failed-save retry are also covered. No production PHP service or
  outline representation was rewritten.
- A recovered course-chat status already contained partial reply text, but the
  poller treated that text only as the baseline for future growth. It never
  rendered the initial checkpoint, leaving an empty bubble until more text or
  completion arrived. Two real turn-hook/poller tests reproduced foreground and
  hydration recovery. The first partial checkpoint is now delivered once to
  the active owner; later monotonic progress, final completion and cancellation
  behavior remain. Project follow-up hydration/polling already applies its
  returned thread and was checked without changes. No second question, file
  upload, provider request, model setting or provider timeout was introduced.
- Confirmed course purchase/upgrade still awaited terminal intent cleanup on
  native storage. A stalled cleanup kept checkout busy after a valid server
  acknowledgement; its global local-storage tail also blocked another course.
  Four local service/hook regressions were red before the fix. Only terminal
  cleanup now has a bounded caller wait. Raw queues remain ordered per exact
  account/intent storage key, including after that wait expires, so late removal
  cannot delete a newly stored attempt. Initial durable identity is still
  required before POST. Thirteen cases cover success, same-key ordering,
  different courses, account replacement, invalid ACK, unavailable pre-send
  storage and reuse of the original key after an uncertain response.
- iOS successfully downloaded binary PDF/PNG/ZIP files but the following
  512-byte HTML sniff used RNFS's strict UTF-8 decoder and threw on normal binary
  bytes. Three real-action tests using the installed RNFS decoder reproduced
  the rejection. The bounded read now uses raw-byte `ascii` decoding; HTML with
  UTF-8/UTF-16 BOM remains rejected, without reading whole large files into JS.
  Independent native-source review then reproduced the real iOS Save to Files
  cancel rejection (`CANCELLED`), not merely a resolved `success:false` fixture.
  That exact handoff cancellation is quiet and still cleans temporary files;
  actual save/read errors retain recovery and no saved result is invented.
  Cancel/retry and a simulated large native transfer with only a 512-byte JS
  sniff are covered. The only other RNFS read consumer uses base64 chunks; this
  is the only react-native-share Save to Files caller in the current mobile app.

Final root verification: 19 affected mobile suites / 139 tests passed together;
full TypeScript, scoped ESLint, JS syntax and diff checks passed. The three local
headless-browser scripts passed (content ordering, authoring receipt recovery,
Bunny allocation/transport/claim/cancellation recovery). Five affected PHP
suites passed 16 tests / 97 assertions with no outgoing provider calls. These
checks use local fixtures plus actual application services/hooks/browser code
and installed native-wrapper source; they are not a physical iPhone test,
live Bunny transfer, real payment or production acceptance.

No APK, push or deployment. All changes are a local source checkpoint.

## September 9 — daily catalogue, saved-reel acknowledgement and portfolio choice

The next separately owned review covered public discovery, ordinary playback,
saved collections and portfolio interaction. It did not reopen working features
for cosmetic cleanup or replace the application.

- Opening a portfolio work, or renewing its media URLs, could replace the image
  the student had just selected with the first/previous image when a delayed
  response arrived. Both paths now use the existing remote-item commit, which
  preserves the current thumbnail by ID while renewing its URL. Independent
  review also reproduced the same failure when selection and response were
  batched before the next render; the selection ref is updated at the tap.
  Four failing cases became green. Nine new hook/render cases retain valid
  fallback for removed/unavailable media, failed-refresh retry and rejection of
  the previous work's response. No gallery controls or media capabilities were
  removed; duplicate response-application code was reduced.
- Saving a reel already open before a course publication correctly stored its
  surviving published replacement on the backend. However, the save endpoint
  acknowledged the replacement ID instead of the requested visible ID. The
  mobile save contract then rejected that successful mutation and rolled back
  its bookmark. The response now echoes the validated requested ID, consistent
  with saved-state lookups. Membership still targets the current published
  lesson and access checks remain authoritative. A real HTTP test asserts the
  committed row and exact shared response fixture; actual mobile hook/service
  tests consume that fixture for selected-folder and watch-later saves. The
  HTTP case and both mobile cases failed before the change. Current-ID saves,
  historical retries, one membership, state/list/removal, nonexistent lessons
  and current access rejection are covered. No mobile production change or new
  response field was needed.
- A definitive unavailable-course response removed an account-scoped catalogue
  key even though public discovery writes account-neutral cache keys. Offline
  reopening could resurrect that course, as could a catalogue GET begun before
  the unavailability response. Invalidation now uses the existing public-cache
  write queue and a generation fence for in-flight reads/writes. Its entire
  native queued read has the bounded storage wait; the raw queue is not released
  early. Failed deletion keeps old cache unusable until deletion or a fresh
  page-one write succeeds. Existing single revision-recovery read preserves
  search and page-two reset behavior. Eleven cases include three original red
  regressions, native failures/stalls, current reappearance, capped retry and
  account-specific 403 leaving public discovery intact. No permanent course
  tombstone, entitlement change or home-screen rewrite was introduced.

The playback lane inspected current source and existing coverage for source
ownership, hidden preload events, background/foreground, seeking, renewal and
resume. It found no new proven defect in that bounded review and changed no
playback source. This was not a physical-device or network-speed measurement.

Root verification passed 27 mobile suites / 193 tests across two exact-path
runs, and two backend suites / 18 tests / 91 assertions. TypeScript, scoped
ESLint, PHP syntax and diff checks passed. No live provider calls, APK, push or
deployment. These are local behavioral proofs, not a claim that every remaining
journey or physical-device condition has been accepted.

## September 9 — attachment lifecycle evidence gaps closed without production churn

This pass returned to the goal's explicit attachment operations and identified
missing evidence rather than assuming that every inspection must change source.
No new production defect was established in these bounded paths, and no runtime
code, feature, layout, dependency or environment value was changed.

- The previously skipped course Office/ZIP case now runs with PHP ZIP enabled.
  Its first Windows failure was fixture-only: Laravel's fake retained an open
  temporary-file handle and prevented ZipArchive from replacing the file on
  close. A closed temporary pathname fixes the fixture. The same policy now has
  executable proof for DOCX/XLSX/PPTX, distinct ZIP naming, matching MIME/hash,
  and rejection of a mismatched Office extension. The existing PDF/image/text,
  internal size limit, renamed HTML and unsupported-type cases remain.
- Actual moderator HTTP tests exercise create/receipt replay on an isolated
  draft and on a canonical course resolved to its draft. The complete route
  sequence proves required replacement validation without mutation, external
  to upload, byte replacement, computer to mobile, upload to external, preview,
  hide, reorder, stale-version delete rejection, deletion and publication. The
  canonical course retains its old attachments until publish; the resulting
  source/device/visibility/order/file and lineage are asserted afterwards.
  Upload fixtures use real UploadedFile wrappers and the repository's existing
  no-outer-transaction migrate:fresh pattern, so tracked file admission commits
  its cleanup receipt normally. Only unrelated publishing readiness and MFA
  are bypassed in the fixture; route permissions and authoring work are real.
- A learner's original metadata ID and signed download URL were exercised over
  two real staged publications: upload/mobile to external/computer and back.
  Unpublished changes remain invisible, old IDs reach the current attachment,
  and HTTP returns the current redirect or exact replacement bytes. Hidden,
  deleted, unpublished-course and revoked-enrollment cases reject the old link.
  Existing signature tampering, access and storage tests remain in the gate.
  These tests use SQLite and a fake private disk, with no outgoing HTTP.
- Ten actual mobile action tests cover the Android permission handoff, with
  API 24 and 28 explicitly exercised and API 29/36 skipping the legacy prompt.
  Denied/never-ask-again results do not claim a transfer; a later granted retry
  starts one system download. Computer copy bypasses permission and transfer,
  external phone files use native downloads, and an account change during the
  permission prompt discards the stale action. Existing source renewal, native
  cancellation, binary-file, HTML fallback and per-row busy tests were retained.

Final root gates: 40 backend tests / 461 assertions across two exact-path runs,
72 mobile tests / seven suites, both current attachment/authoring browser
scripts, full TypeScript, scoped lint, PHP syntax and diff checks passed.
The existing Android Release Kotlin target also compiled successfully offline
for the direct/test profile (minSdk 24), without assembling an APK. Existing
toolchain/deprecation warnings were not treated as new functional defects.

This closes these explicit local evidence gaps, not physical-device acceptance.
iOS compilation, OEM behavior, live external-host transfers and production
acceptance are not established by these checks. No APK, push or deployment;
the broader requested review remains active.

## September 9 — published completion acknowledgements and confirmed profile saves

Two further daily-journey defects were established rather than replacing
working features or changing the application's design.

- The durable section-completion command on a phone retains the section ID it
  watched. After staged publication, this explicit fallback returned 404 for
  that old ID even when surviving watch evidence authorized its current ID.
  Mobile treats 404 as terminal and removes the pending command. Completion
  now resolves the existing published learner-state lineage inside its existing
  User-to-Course lock, then applies current course membership, access, evidence
  and project-gate checks. There is no second mapping implementation or mobile
  response change. Eight real HTTP cases cover two publications, replay of an
  already completed historical section, one progress row, missing evidence,
  deletion with a valid remaining graph, revoked access, foreign/unknown
  sections and unavailability. The old-ID/current-ID countercase failed before
  the source change. Existing project lineage and completion projection cases
  remain green. SQLite does not establish live database concurrency behavior.
- A confirmed account-profile POST could remain busy forever while its local
  session mirror or uploaded-image cleanup waited on native storage. Both
  stalled-stage UI cases failed before the fix. Only the confirmed mirror's
  caller wait is bounded; the actual secure-session queue retains ownership
  until persistence finishes. Unconfirmed writes still retain their request ID
  and selected image for explicit retry. A mirror failure or deadline uses the
  existing saved notice and authoritative profile reload, never a second POST
  or a speculative Redux identity. A late mirror can invalidate that reload's
  epoch; the existing error/retry state now handles this instead of leaving the
  form permanently loading. Obsolete/accepted avatar cleanup is nonblocking
  with rejection handling, including replacing an earlier picker selection;
  the account-scoped file lock and reference checks remain unchanged.
- The profile updater checks the original boundary when it actually reaches
  the session queue, so a queued same-user re-login with a new token cannot
  accept stale metadata. After persistence, the UI checks the current owner
  and bearer synchronously and dispatches the current committed snapshot. It
  does not reject its own legitimate epoch advance or overwrite newer metadata
  using its old return value. Three legacy envelope branches were unreachable
  behind the service's canonical `user` owner validation and were removed;
  the single merge preserves other session and user fields. The Settings
  reauthentication callers still wait for durable credentials as before.

Independent mobile cases use the actual session service, helpers, epoch and
raw mutation queue with mocked native storage. They cover ordinary durable
success and cold restore, deadlines at the profile and final binding writes,
queued newer writes, logout/re-login to another or the same user, delayed
recovery GET rejection followed by explicit retry, and preservation of a newer
visible edit after the old mirror finishes. UI wrappers and API responses are
fixtures; these are not device-storage or live-provider acceptance tests.

The separate chat and checkout publication reviews found no additional proven
defect in the inspected paths. Existing historical-context mapping, original
chat request recovery, purchase receipt replay and enrollment snapshots were
left unchanged. No new model/provider calls, features or architecture layers.

Final root gates: 11 mobile suites / 78 tests and 53 backend tests / 491
assertions across two exact-path runs passed. TypeScript, scoped ESLint,
formatting, PHP syntax and diff checks passed. All changes remain local source
work: no APK, push or deployment, and no claim of complete app acceptance.

## September 9 — rating ordering and confirmed wallet/portfolio mutations

The next local changes preserve the current features and their existing
contracts rather than replacing the screens or adding alternate state owners.

- A foreground course read could overwrite an acknowledged rating creation,
  edit or deletion. Rating acknowledgements could also overwrite a newer read.
  The screen now uses the backend's existing personal rating version and a
  single rating value from the course snapshot. Older personal versions cannot
  overwrite newer ones. Equal-version reads still accept aggregate ratings
  changed by other students; equal-version acknowledgements do not replace a
  newer read's aggregates. This is not a global ordering of all students' votes.
- Coin-task requests and confirmed claims could wait indefinitely for their
  optional remembered-link cache. The caller now has a bounded wait while the
  actual account-scoped storage queue remains ordered. The server still owns
  the task attempt, current destination, eligibility and awarded balance.
- Confirmed portfolio uploads, publication and deletion could remain busy
  behind native outbox/file cleanup. Only the terminal result's caller wait is
  bounded. New staging still requires durable storage and queues behind the
  real cleanup operation. A failed pending-intent removal retains its original
  UUID and file; it does not fabricate a new upload identity.

Checking that retained identity exposed a separate backend defect: replaying
an accepted image after the student deleted it recreated the image because the
deleted row had been its only receipt. This was reproduced through actual
append, finalize, delete and replay HTTP endpoints before source changes.
Deleted upload identities now require a minimal item-scoped receipt, not
retaining the media itself. The new database migration is part of this fix and
must be deployed with its backend source before any release using the fix.
Terminal `422 media_deleted` is deliberately file-scoped: the mobile delivery
policy removes that old attempt and continues its siblings, unlike `404`,
which means the entire remote project is unavailable.

The same lifecycle check exposed video issue/renew responses claiming that an
already-deleted video was still attached, followed by an expired-upload response
on claim. Those old attempts are now terminal across issue, renew and claim.
The existing attached-video receipt also detects historical deletions without
a new tombstone. A surviving attached video still replays after its lease
expires, while genuinely pending expired uploads retain their expiry behavior.
An intentionally new UUID can upload the same file again. Checks also prevent
reusing a deleted image's UUID to allocate a video.

Receipts contain only the item ID and upload UUID and cascade with item
deletion. A failed cleanup reservation rolls back both media deletion and its
receipt. Image requests check deletion before provider upload and again inside
the existing User-to-Item transaction after remote IO. Tests inject that
interleaving explicitly; they do not establish concurrent MySQL behavior.
Images hard-deleted before this migration have no surviving request receipt
to reconstruct; this change does not invent a historical backfill for them.

Final root gates passed: 19 mobile suites / 151 tests and 65 backend tests /
534 assertions across exact-path runs, plus full mobile TypeScript and scoped
ESLint. Backend HTTP fixtures exercise real controllers, services, database
state and the migration; Bunny responses are mocked. The mobile sibling-upload
case uses the actual replay/delivery/outbox stack and a terminal HTTP fixture,
separately from the backend HTTP proof. None of these are live-provider or
physical-device acceptance claims.

The inspected home-row curation, coming-soon navigation and portfolio/QR share
paths supplied no additional proven defect in this pass and were not changed.
In particular, no coming-soon reminder subscription feature was added or
claimed. All verification here is local; no APK, push or deployment occurred.

## September 9 — daily delivery, report recovery and unsaved authoring

The preceding checkpoint was `22f34e7`. This pass continued with separate
workflow owners, preserving the features and presentation already in place.

- Notification delivery waited for the local inbox before even starting its
  HTTP read, and a successful response could wait indefinitely for cached
  course artwork. The authoritative read now starts first; optional cache
  waits use the existing bound. The raw cache write queue and acknowledged
  read-state overlay are unchanged. Actual-hook/cache tests reproduce stalled
  storage and artwork, failed cache reads, late stale results, network fallback,
  account changes and unmount cancellation.
- The Home campaign's course action waited for mark-read acknowledgement and
  then local seen-state persistence. It now opens the selected course under
  the presented account boundary immediately. The receipt still runs in its
  original server-then-local order with rejection handling, without a new
  queue or timer. Dismiss-only, failed receipts, late account changes, repeated
  taps and a rejected course navigation retain their separate semantics.
- A cached earlier certificate erased the pending state of a newly accepted
  certificate when reconciliation failed. The cache now preserves the existing
  accepted-course set. A later authoritative completed certificate clears the
  pending state; recovery uses that course rather than issuing another request.
- Rebuilding a course project summary could cancel a live report retry and
  ignore its acknowledgement, or overwrite its completed result afterwards.
  A full review thread becoming a summary was another instance of this fault.
  The existing project-to-runtime normalizer now feeds one seed synchronization
  path: report decision changes are distinguished from transcript/quota and
  continuation-only updates. Genuine status/permission changes still supersede
  an older retry; equivalent summaries and media unlocking do not. No polling
  loop, provider change or parallel project-state subsystem was added.
- When checkout rejected changed package terms, cache invalidation could hold
  the replacement read indefinitely. A failed cache write or an older GET
  could also restore the package already known to be obsolete. Invalidation
  now retires older refresh/manual-refresh ownership, clears the visible
  packages and queues its cache write without waiting for it. Cache fallback
  cannot restore those packages until an authoritative replacement GET succeeds,
  including a genuinely empty catalogue. The existing raw cache queue remains
  ordered. Provider-only unavailability does not invalidate valid packages,
  and no second checkout is started automatically.
- Switching between inline section/module editors silently reset unsaved
  titles, captions, project requirements and selected files. Publishing could
  discard the same work. The existing editor coordinator now compares editable
  form values with the opened baseline and uses a discard confirmation only
  for actual changes. Declining keeps the form; failed saves retain dirty state;
  unchanged/reverted/successfully saved forms require no confirmation. Ordinary
  course-detail saves neither save nor discard another editor implicitly.
  Hidden identity/version changes are not user edits. No draft store, autosave
  system or backend endpoint was added.
- The portfolio create editor could remain saving after its item, media and
  publication were acknowledged because editor-draft cleanup was still waiting
  for native writes or file removal. Draft retirement is now queued immediately
  under the existing draft lock after retiring older not-yet-entered autosave
  callbacks. The redundant second writer-flight reference was removed. Only
  the accepted create caller bounds its wait; the cleanup promise and service
  lock remain raw. New edits and remount reads stay behind retirement, and
  failed durable removal retains the original UUID/file. Unacknowledged create
  and failed pre-durable media staging still preserve the work. A changed
  account during finalization is no longer swallowed as an ordinary publication
  warning before clearing the editor. No API, outbox service or new queue.

Root checks passed for 26 mobile suites / 193 tests across exact-path runs and
26 backend tests / 104 assertions for notification API, admin delivery parity
and certificate notifications. The browser checks exercise production studio
JavaScript against a local HTTP/DOM fixture: 12 new dirty-transition cases plus
the adjacent authoring-receipt, content-order, summary and Bunny upload recovery
scripts. Full mobile TypeScript, scoped ESLint and diff checks passed. These do
not represent writes through the live dashboard or physical phone acceptance.
All changes remain local source work; no APK, push or deployment.

## September 9 — completed login, saved-library actions and editor recovery

This pass preserves existing features and presentation. It fixes demonstrated
daily-flow failures rather than replacing the implementation for style.

- Social login could durably commit the credential while the actual login shell
  remained loading: it still awaited the welcome-popup receipt and completed
  journal retirement. Only that post-commit wait is now bounded. Credential and
  pre-commit recovery-journal persistence are still mandatory. Retrying a retained
  completed journal neither commits the same bearer again nor recreates an
  already-consumed welcome popup. Receipt writing owns an explicit account
  boundary, and completion rechecks the committed bearer before UI adoption.
  Seven new cases exercise the real shell, completion and secure-session code
  with native-storage/HTTP seams. They include blocked receipt/journal cleanup,
  mandatory-write failures and replacement accounts during receipt work.
- Accepted saved-lesson/folder deletion still awaited lesson/player cache repair
  despite earlier folder-index wait limits. A shared existing repair boundary
  now bounds only the caller's wait; independent repairs enter their own raw
  queues together. The first two regressions reproduced stuck real-library busy
  state after a successful DELETE. Root review then exposed a second race:
  delayed deletion of the old watch-later hint could erase its replacement.
  Two additional RED cases reproduced delayed native reads/removals clearing the
  newly created folder ID. Reads, conditional deletion and writes to this one
  optional hint now share a scope-owned raw queue. Network requests never enter
  that queue, and the caller may use the server while a stale hint is blocked.
  Twelve new cases cover both families, late writes, old GETs, offline fallback,
  account replacement and rejection before server acknowledgement.
- Rapid setting changes rolled back to the previous optimistic tap instead of
  the last saved value. Four RED cases demonstrated two rejected changes leaving
  an unsaved quality, reminder time, watch-history or marketing preference visible.
  One last-saved reference is updated at hydration and accepted writes while the
  existing scope-write queue and revision checks remain in place. Error paths
  now check the account boundary before any rollback, dirty-key deletion or
  alert. The 21 new cases also cover either successful write, hydrated baselines,
  replacement accounts, and authenticated notification enable rejection after
  a failed disable. No settings rows, permissions or remote preference contracts
  were added or removed.
- Editing and publishing an existing course draft could save its new fields and
  version, then return a plain readiness 422. The editor retained its old version
  and the next correction hit a false 409. The authoring service now returns its
  existing saved/not-ready contract for this post-save readiness rejection.
  Actual version conflicts and pre-save validation errors remain unchanged.
  Two real HTTP RED cases preceded the fix. Five focused cases verify the saved
  draft/version, unchanged public course, successful correction/publication,
  genuine conflicts, invalid input and HTML recovery. No studio JavaScript or
  publication-engine rewrite was needed.

Root verification passed 29 mobile suites / 227 tests and 21 backend tests /
236 assertions. Full mobile TypeScript, scoped ESLint and diff checks passed.
The authoring agent also ran the existing production-JavaScript draft-transition
browser fixture: 12 cases passed. Independent source reviews found no introduced
blocker in preference rollback or completed-login delivery. These are local
contract/lifecycle checks, not a live provider login or physical-device acceptance.
No APK was built and no source was pushed or deployed in this pass.

## September 9 — retry learning, revise rejected projects and replay ended reels

Four disjoint daily journeys were reviewed from clean `a341672`. Existing
course authoring, attachment rules, project access, checkout confirmation and
visual identity were preserved. No backend or native source changed.

- MyCorner had no in-place recovery: an empty failed read offered Home, a cached
  failed read offered no retry, and a foreground server update required leaving
  the screen. Three rendered-screen RED cases demonstrated these gaps. The
  existing focus/foreground loader now also owns explicit retry and pull refresh,
  with one in-flight read per active screen. Existing courses remain visible
  during recovery, but direct resume stays unavailable until fresh ownership
  arrives. A session-lookup error renders recovery instead of an endless skeleton.
  Nine screen/hook cases cover empty and cached errors, repeated taps, successful
  emptiness, guest entry, blur/unmount and replacement-account responses. The
  screen, CourseShelf and Content/ScrollView wiring run together; API/native seams
  are controlled by the fixture. Independent review found no introduced blocker.
- Project submission could acknowledge `evaluating`, later receive `needs_changes`,
  then leave “edit submission” stuck on details/loading. Hydration intentionally
  does not repeat merely because status changes, but acceptance marked its known
  empty replacement draft unread. That replacement now stays ready; server
  status and `canSubmit` still own whether editing/submission is available.
  Four RED cases exercise text, PNG, DOCX and PDF through the actual submission
  hook, then a later rejection and second submission. Counters keep submission
  disabled when the contract disallows it. No new status effect, rehydration,
  project storage or evaluation endpoint was added. Backend inspection confirms
  that accepted queued evaluation and its later rejection are normal responses.
- An ended reel left on screen could not restart from its play button. The
  installed native player needs a seek, not just a paused-property change.
  One real VideoComponent/VideoChrome-to-native-ref RED case showed no seek after
  completion; the mid-reel pause/resume counter already passed. Completion now
  uses the existing pause state, and explicit play rewinds only at the end.
  Seeking backward after completion preserves the learner's chosen position.
  Five new cases include background return and reel replacement; autoplay,
  preview limits and project navigation remain owned by their existing flows.
  This proves the native-ref contract, not physical decoder playback.
- Confirmed coin top-ups still waited for raw attempt-ledger and return-receipt
  cleanup before reaching the course's explicit confirmation step. These are
  distinct from the previously repaired enrollment-attempt store. Four actual
  course-checkout RED cases used accepted payment responses followed by stalled
  native reads/removals. Terminal callers now have a bounded cleanup wait, while
  the raw storage queues retain their order and mandatory pre-send writes remain
  mandatory. Eleven new cases also cover cancellation, foreground settlement,
  account replacement, late removal before a new intent/return receipt and no
  dispatch when pre-send persistence fails. No automatic course purchase, new
  provider request, financial amount or server entitlement behavior was added.

Root verification passed 30 unique mobile suites / 280 tests across exact-path
runs, plus full TypeScript, scoped ESLint and diff checks. The initial combined
command used a nonexistent `.ts` spelling for `playbackRecovery.test.tsx`; the
correct existing suite was subsequently run and passed. Checks include focused
runtime fixtures and adjacent source-contract guards; no live payment, provider
evaluation, physical playback, APK, push or deployment is claimed.

## September 9 — attachment sends, portfolio selection and dashboard recipients

Four disjoint daily journeys were repaired from clean `48b4b2f`. No feature,
screen, course rule, payment path or visual identity was removed or rebuilt.

- Course chat could finish uploading an image and durably save its server ID,
  yet never submit the question while obsolete local-file cleanup was stalled.
  Three actual hook/persistence/file-registry RED cases preceded the fix.
  Only post-durability reference release and obsolete-file removal stop owning
  the caller's wait. Required history/reference writes, account checks and the
  raw file-operation queue remain mandatory and ordered. Ten new cases include
  failed initial/uploaded-ID persistence, account replacement, later file
  retention, account cleanup and lost-answer recovery. The latter reads the
  durable transcript and recovers the same completed request without a second
  question POST or image upload. No model, prompt or provider contract changed.
- Portfolio creation could submit its previous photos while replacements were
  still copying, or adopt those replacements after switching source projects.
  The existing picker flight now also owns submission/source-change admission;
  the view shows file preparation until selection is settled. Terminal cleanup
  cannot hold that admission lock. Independent review found a related gap before
  the native picker itself: a delayed account capture could open it after the
  editor was closed. A boundary/mount/generation check now precedes native launch.
  Seven RED cases across these boundaries became green. Fourteen new cases
  preserve cancellation, old selection on failure, close/reopen, ownership and
  unmount behavior. The raw file service and publication/outbox contracts are
  unchanged. One obsolete source-shape assertion now checks the draft-clear
  delegation already introduced by the earlier completion repair.
- Notification authoring used one draft identity for every student and broadcast.
  The real rendered form restored student A's hidden recipient inside student B's
  visibly labelled form. Four browser RED cases exposed cross-student and
  individual/broadcast contamination. The form and existing draft include now
  share one destination-specific ID. Five production-Blade/JavaScript cases
  preserve per-recipient content, schedule, request UUID and course-search reload.
  Old ambiguous drafts are neither deleted nor guessed into a new recipient.
  The shared draft engine and notification delivery policy are unchanged.
  A bounded source review of all 18 draft-include sites found no other form
  whose creation destination changes through query parameters under one key;
  other destination-specific forms carry their resource ID in the route path.
- Support CSV export ignored inbox filters for app version, overdue replies and
  course, and did not search learner/guest email as the inbox did. Five real
  route-to-CSV RED cases preceded extraction of the existing inbox filter query
  into one private controller method used by both endpoints. Ten new cases also
  cover combined filters, Cairo day boundaries, invalid input, moderator denial
  and 501 matching rows across export chunks while viewing inbox page two.
  Existing pagination, CSV fields/sanitation, chunk ordering and limit remain.

Root verification passed 15 mobile suites / 110 tests, 34 backend tests /
164 assertions and five local browser cases. Full mobile TypeScript, scoped
ESLint, PHP controller syntax and diff checks passed. Cross-agent source review
covered each repair; these are local contract/lifecycle checks with native and
provider seams controlled, not physical-device or live-provider acceptance.
No APK, push, deployment, real notification or paid provider request was made.

## September 9 — complete support history and readable owned-course details

Continued from clean `4145357`. The preceding goal turn made concrete progress;
this pass retained its source and reviewed different daily journeys. Two further
defects were proved and repaired without changing product features or design.

- The support API already paginated account history by 20, but mobile consumed
  only page one. A newly installed device could neither list nor select an older
  requested case. Two RED cases using the real feedback hook/service showed
  the missing twenty-first case and an incorrectly accepted partial history.
  The existing service now follows validated `current_page`, `last_page` and
  `has_more` metadata before replacing the visible list. A failed/malformed or
  overlapping page rejects the refresh, preserving the hook's preceding list
  and explicit retry. Guest receipts and direct delivery receipts are unchanged.
  Eleven new mobile cases cover requested selection, later-page failure and
  recovery, repeated/invalid pages, replacement accounts, terminal empty pages
  and a remembered receipt already loaded on page two. Two older fixtures now
  include the pagination metadata actually sent by the server. Three new real
  API tests independently verify 21 owned cases across two pages, ordering,
  direct retrieval, other-account/guest isolation and Laravel's valid empty page
  after the list shrinks. No backend production source changed. This is complete
  page consumption, not a claim of an atomic database snapshot across requests.
- Owned CourseDetails waited indefinitely for the optional local completion
  overlay even after receiving the authoritative course/learning graph. Two
  actual hook/mapper/persistence RED cases demonstrated an endless initial
  skeleton and the same failure after guest-to-account transition on that course.
  Only the local overlay wait is bounded; its fallback is the already-mapped
  server graph. Nine new cases preserve project gates, empty locked media,
  ordinary local completion hints, revoked access and invalid-graph rejection.
  They also cover late completion, course/account changes and retired epochs.
  The overlay builds new objects, so a late read cannot mutate the fallback or
  publish new state after it. Shared persistence and server access rules remain
  unchanged. Independent source review found no introduced blocker in either fix.

Root verification passed 12 mobile suites / 141 tests and seven backend tests /
38 assertions, plus full mobile TypeScript, scoped ESLint and diff checks.
Disjoint counter-checks found no new defect in the reviewed rewards or reel
continuation journeys: 49 reward mobile cases, 28 backend reward tests /
192 assertions, and 16 continuation mobile cases passed. Sharing/QR review found
the current works-only portfolio and practical/theoretical certificate routing
consistent; no sharing source changed and those inspected tests were not rerun.

A separate source-to-test audit revisited the original attachment requirements:
internal/external source, mobile/computer destination, authoring/replacement/
ordering/visibility/deletion/publication, access, on-demand download and link
copy. Existing HTTP, production-JavaScript and native-seam fixtures cover those
contracts; no missing feature or new production defect was established. Remaining
acceptance evidence is explicitly limited: Windows does not prove an iOS build,
the Android download/probe receiver has not been exercised end-to-end on a device
in this pass, and live Drive/Bunny/redirect/save UI behavior has not been tested.
No APK, push, deployment, external task claim or paid provider call was made.
