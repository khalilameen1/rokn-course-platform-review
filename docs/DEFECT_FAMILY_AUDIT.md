# Rokn defect-family audit

This is a living engineering audit, not a claim that every row has already
passed production acceptance. A row is complete only when its stated evidence
exists against the deployed backend and the signed mobile artifact.

## Recover confirmed and still-pending checkout outcomes — 2026-09-05

Two distinct mobile boundaries were dropping valid server/payment outcomes:

- Foreground recovery joined an in-flight checkout but converted both pending
  results and failures to null. The runtime therefore stopped scheduling its
  next reconciliation, leaving a later settlement unnoticed until another user
  action. The coordinator now propagates pending and rejected results while
  keeping terminal screen-owned results from being emitted twice.
- Payment initiation can legitimately return `checkout_state=paid` without a
  payable URL or a new idempotency key. The backend does this for a settled replay
  or an older capture recovered before opening another package. Mobile required
  a new checkout URL for every initiation and rejected that successful response.
  Initiation is now an explicit paid/payable union. Paid requires an approved,
  settled order, a valid reference and positive integer coins from that order;
  it clears the local attempt without opening the gateway or using the newly
  selected package's coin count. Account boundaries remain enforced.

The existing course consumer reloads wallet and quote, then asks the learner to
confirm the course purchase; a recovered top-up does not itself buy a course.
The existing top-up-to-confirm effect also handles a later balance refresh.
Backend wallet credit and course enrollment remain separate transactions; the
course debit, order, bill and enrollment are atomic within course authorization.
No new backend financial defect was established by this bounded review.

Before/after regressions reproduce both dropped outcomes. The foreground test
uses the real runtime, coordinator, reconciliation entry and wallet credit
subscriber, asserting one refresh request after settlement and no second provider
dispatch. It does not claim a real wallet HTTP response or native UI acceptance.
The paid-initiation tests use the backend's actual response shape, including an
older, differently sized package and financially ineffective negative cases.
Nine combined mobile suites pass 78 tests; TypeScript and scoped ESLint pass.
The existing backend paid-replay endpoint regression passes 1 test / 8 assertions.
No real charge, credit, enrollment or new APK was created for this verification.

Deployment 176 published exact `99f3eec89caab3a9f91ef7328481b7c357d1cb8b` after
backend CI 33986546280 succeeded. The post-deploy readiness endpoint returned
HTTP 200 with database, schema, identity and cache checks true. That deployment
carries the previous authored-notification fix, not these newer mobile changes.
Mobile CI 33986546218 for that same SHA is still running; its staging smoke is
skipped, not passed. APK `1234.apk` remains unchanged.

Native acceptance was resumed on emulator-5560 against the installed
`1.0.41 / versionCode 42` (the older 1234 candidate). Its existing preview ended
at the free-preview gate; course details, home and the guest profile loaded.
The login page advertised Google and TikTok. Choosing Google reached Chrome's
first-run account setup, not an authenticated Rokn session. The user was asked
to complete Google login on this emulator with the account already topped up.
No paid course purchase, provider reply or acceptance of the newer source is
proved by this sample. Do not build a new candidate merely to rediscover this
authentication prerequisite; do not manually credit or enroll around it.

## Deliver authored notifications without mistaking them for failures — 2026-09-05

A valid notification mentioning `Grease Pencil` or `SQLSTATE` was passed through
the mobile error-message filter. It became empty, violated the inbox contract and
rejected the entire fetched page. Engagement copy used the same filter. Both
screens then also rewrote authored words/numbers through interface localization.
The local learning-reminder composer similarly changed course/lesson names after
embedding them into its body.

Notification and engagement fields now use bounded authored-text normalization,
not error-message classification. Arabic field priority, required text/actions,
duplicate-ID checks and destinations remain unchanged. Authored English content
is no longer itself classified as diagnostic output. Screens preserve the same
content and local reminder composition isolates names from localized streak copy.

The current backend writer graph was checked independently: notification fields
come from authored templates, admin campaign copy and business variables. Delivery
exceptions are stored/reported separately as failure codes, not notification
titles or messages. Inbox and push now share one Unicode/length normalization
method; the keyword blacklist, tag stripping and destructive punctuation/line
rewriting were removed from that content path. Actual error logging and failure
handling were not changed. Read paths render escaped Blade, native Text or FCM
text fields. Legacy production rows were not independently inspected.

Before/after evidence includes inbox-page contract rejection, engagement loss,
rendered screen text, local OS scheduling payloads, API resources and intercepted
push payloads. The final FCM display boundary also preserves source digits and
whole Latin phrases instead of relocalizing them. Tests compare sent copy with
the source after removing direction isolates, not with the production formatter.
The replaced backend formatter had no remaining production consumers; it and its
orphan unit test were removed. Seven backend suites pass 54 tests / 265 assertions.
The same separation now covers authored social-login recommendations and app
release messages/notes. Notes retain their 600-character limit instead of being
silently reduced to the error filter's 240 characters and three lines. Version
gates, trusted download links and actual login error handling remain unchanged.
The home classification row's shared heading was also rewriting authored titles;
a rendered CoursesSection regression reproduced and closes that last discovered
heading path. All other current section titles are fixed interface copy. Push
receivers and non-error status views were inspected without finding another
authored-text rewrite in those paths; this is not device acceptance.
Eleven combined mobile suites pass 86 tests, including unchanged error-payload and
social-auth error tests; TypeScript and targeted ESLint pass. This is source
evidence only; no production notification, payment or new APK was generated by
these tests.

Mobile CI 33984546553 has completed successfully for exact `78ed120` across
JavaScript, Android and iOS. Its staging-smoke job was skipped, not passed. This
evidence does not cover the newer authored-content changes above.

## Keep authored content separate from localized interface copy — 2026-09-05

The display formatter was rewriting instructor/student words and numbers, not
just their direction: a title `ريلز 2026` became `مقاطع ٢٠٢٦`, and numeric code
examples changed too. Course chat already avoided that rewriting, but project
reports, lesson captions, course/module titles, portfolio text, saved-folder
names and search history did not.

Those authored fields now use a direction-only formatter. It shares the existing
isolation helper with interface-copy formatting, preserving the earlier fix for
contiguous Latin phrases inside Arabic text. Interface counters, prices and
fixed labels retain their existing localization; no layout styles were changed.
Rendered-component regressions reproduced the content changes before correction.
The combined seven relevant suites pass 47 tests, with TypeScript and targeted
ESLint also passing. This is source-level evidence, not a new APK or native proof.

Deployment 175 successfully published exact `78ed120ca60b3dcde3dac8a4381c81094dc3238d`
after backend CI 33984546554 passed. Post-deployment readiness returned success
with database, schema, identity storage and cache checks true. That deploy carries
the preceding project-partial service fix, not this newer mobile text batch.
The current launch gate still reports recovery and mobile_release incomplete;
Facebook, Apple login and store billing are not currently advertised as ready.
These operational gaps are not reclassified as completed by public API health.

## Preserve interrupted project reports across API and screen — 2026-09-05

Actual streamed text was discarded by initial-report failure handling and hidden
by the project message presenter for failed follow-up replies. Initial reports
also disappeared at the mobile panel boundary, and reopening a failed report
from a transcript-free course summary never hydrated its messages.

The existing body/text field now survives with status `failed`. The screen shows
received text with an incomplete-response label, while an empty failed report
does not create an empty conversation panel. Failed report summaries can hydrate
through the existing owned, bounded read path. Reply admission on the client also
requires report status `ready`; a retained fragment grants no new capability.
No new report status, success marker, provider retry or evaluation decision was
introduced. Completed-only AI history remains unchanged.

Two backend job/API regressions failed before the service correction, then the
project presenter suite passed 14 tests / 101 assertions. Mobile screen tests
reproduced the hidden panel and lifecycle tests reproduced the skipped failed
report read; the five related suites now pass 27 tests. TypeScript and targeted
ESLint pass. These are intercepted-provider and rendered-component checks, not
native acceptance or paid production calls. This batch is not in APK 1234.

## Production handoff of bounded chat transport — 2026-09-05

Backend CI 33983731096 succeeded for exact commit
`88333ed1e7bbfecb21020f7a4c327eaad330d348`. Laravel Cloud deployment 174 then
deployed that commit successfully, including runtime preflight before and after
the migration check (nothing to migrate) and completed traffic routing. This
supersedes the no-deployment status of the earlier source-only sections below.

After deployment, public readiness, course list, course 3 details, auth methods
and packages all returned HTTP 200 with success=true. Course 3 remained revision
30 with three modules and guest access_type=none / learning_started=false.
Auth methods currently advertise Google and TikTok, not Facebook. These reads do
not prove completion of either OAuth flow, a purchase or a real paid chat. APK
1234 is unchanged and still represents its earlier native source revision.

## Bound one provider request and deliver small fragments promptly — 2026-09-05

Replaced the blocking streamed-body read with an explicit cURL-backed request and
incremental SSE decoder. The same decoder handles live fragments and buffered
test responses. One network timeout covers connection, headers and response body.
The first nonempty fragment is emitted immediately; subsequent updates retain
throttling and unchanged heartbeat text no longer causes repeated checkpoints.
DONE closes delivery without waiting for the server to close its socket. Failed
or malformed streams flush visible recovery text without landing a final answer.

JSON answers, structured provider errors, token/cost usage and annotations retain
their existing final-result path. Both JSON and streaming generation requests
disable redirects. A small factory using Guzzle's public create/release interface
prevents its internal rewind recovery from creating a second transport attempt;
no private retry counter, second paid request or vendor patch is used.

cURL is now an explicit root Composer/runtime preflight requirement. The obsolete
per-read timeout setting is removed. Composer strict validation passed with an
unchanged dependency graph; the capability check passed both with and without
cURL loaded, and the complete preflight test file passed.

Verification: 125 tests / 744 assertions passed across admission, cancellation,
project feedback, provider accounting, streaming, real loopback transport and
preflight. Actual local socket cases prove early partial delivery and a 5-second
network deadline within the 4.5-6.5-second test tolerance, including heartbeat,
slow-header and silent-body scenarios. Redirect/rewind replay, JSON fallback and
known versus unknown provider outcomes are covered. Independent read-only review
found no concrete blocker. The deadline does not preempt arbitrary blocking PHP
work inside the progress callback; production callback/database latency and the
installed mobile experience remain separate acceptance work.

No production deployment or APK rebuild was performed. During validation C: had
zero free bytes; PHP temporary work was directed to E: without deleting user data.

## Keep authored course context identical to the student's plain text — 2026-09-05

The studio's course description, lesson caption and project requirements are
plain textareas, normalized with UnicodeText and persisted directly. Their AI
readers nevertheless stripped HTML examples. Those readers now preserve the same
text shown to the student, within existing context limits, including immutable
project requirements snapshots and admission token estimates. The course-brief
cache namespace moves from v5 to v6 so old stripped summaries are not reused.

Four expanded HTTP/job/provider-payload cases reproduced the loss before the
change. The combined backend/project suites passed 78 tests / 451 assertions;
the final project suite with authored-requirements budgeting then passed 12 tests
/ 75 assertions. These are locally intercepted provider payloads, not paid calls
or a deployment. Existing OOXML document extraction and diagnostic-only filtering
are separate mechanisms and were not blindly removed.

## Preserve paid chat delivery and technical lesson text — 2026-09-05

Cancellation could discard a course-chat answer after provider landing or after
ledger settlement but before the turn's presentation write. Those two states now
refuse cancellation and remain recoverable through normal polling. Regressions
exercise cancellation, repeated polling and entitlement accounting: one delivered
answer, one consumed request, no repeated provider call. Already-completed turn
cancellation was correct before this change and is not counted as a new fix.

Valid technical answers containing SQLSTATE, stack trace, tool calls or literal
HTML were mistaken for provider failures. The same content blacklist suppressed
stream checkpoints, removed completed course-chat history and rejected project
reports/follow-up questions. Validation now distinguishes the HTTP/error envelope
from learner-visible text. Empty, malformed and tool-only provider replies remain
failures; ordinary technical vocabulary is not a failure signal.

Course-chat admission also stripped HTML out of learner questions, sometimes
turning code-only questions into empty messages. It now preserves literal code
with the existing Unicode cleanup and length limit. Behavioral cases reproduced
both failures before the change and pass afterward, including same-request replay
and refusal to reuse that identity for different markup. Text is rendered as
native text or escaped Blade output, not executable HTML.

The project follow-through now preserves learner markup at submission admission,
in both report jobs, in recent exchanges and in older retained context. The
admission estimate counts those characters instead of treating markup as free.
Failed messages remain excluded and token/length bounds remain in place. Mobile
response/history/copy paths were inspected; their technical-error copy filtering
does not run on ordinary learner messages or generated answers.

Final four-file verification passed 92 tests / 500 assertions. Provider HTTP was
faked or prohibited in these regressions; no paid provider request, production
update or APK rebuild was performed. The
previous f1a64cb CI run 33979568767 completed successfully for Android, iOS and
JavaScript; staging smoke was skipped, not passed.

Known remaining transport gap was reproduced through the actual chat service and
a loopback SSE server, with PHP 8.4.24, curl loaded and Guzzle 7.15.5. With a total
timeout and read timeout both set to 5 seconds, a heartbeat response completed and
landed at 8.278 seconds; delayed headers plus heartbeat data completed at 8.074
seconds. In both cases the initial partial text was also delivered only at the
end, despite having been sent earlier. Guzzle's stream handler/read(8192) path
therefore needs a total deadline and genuinely progressive delivery. These are
real local Windows observations, not a diagnosis of a particular production Linux
incident. Reproduction fixtures are ignored artifacts under
`mobile/artifacts/deadline-probe`; both local processes exited. No transport
implementation has been changed in this batch.

## Keep terminal read-timeout diagnostics on the common transport path — 2026-09-05

The installed cf7b7b0 / Android 42 candidate was revisited from sign-in through
the free course preview. This time the preview loaded, a screenshot showed the
first reel playing, and a later native hierarchy showed reel two at 75/75 seconds
with the preview-complete purchase gate. No retry, account authentication or
purchase was performed during this attempt. These sampled observations do not
prove uninterrupted playback or explain the earlier intermittent failure.

Inspection found that both budget-exhaustion exits in the common API interceptor
returned before terminal-availability diagnostics. A read that used all its time
could fail visibly without reporting through `api_transport`. Both exits now
reach the existing non-blocking diagnostic path and reject the original failure.
No request budget, retry count or mutation policy changed. Recovered reads and
intentional cancellation remain unreported as incidents.

Jest's CommonJS environment also left dynamic imports unsupported inside the
fire-and-forget reporting path. A test-only Babel transform now executes these
imports; production transformation is unchanged. With that harness corrected,
temporarily restoring the two old early exits reproduced both missing-event
failures; removing them passes both cases. The plugin is resolved from the
already-declared Babel preset dependency, without adding a runtime package.

Verification: all 158 Jest suites / 840 tests passed, then the final 24 transport
tests passed again after adding explicit no-event assertions for successful
recovery and cancellation. TypeScript and scoped ESLint passed. This demonstrates
client-side diagnostic dispatch, not confirmed Sentry/Nightwatch receipt. The
source fix and prior recovery changes are still absent from unchanged `1234.apk`.

## Share fresh course-read recovery between details and playback — 2026-09-05

The backend details action can return `409 course_revision_changed` when a
publication changes during the read. The commercial details reader already
handled this with one re-read, but the learning reader (also used to refresh
attachment access) failed immediately. These were the only two direct details
GET call sites in mobile source.

Both now use `requestCourseDetails`, with the original signal and one shared
transport deadline across at most two publication attempts. The details page
keeps its existing optional-auth and guest-cache presentation policy; playback
still requires a fresh response and never falls back to cached entitlements or
media. Cancellation prevents the publication re-read. Other 409s, denials,
missing courses and server errors are not treated as publication races.

The previously failing learning-read case now succeeds after one revision
conflict; persistent revision conflicts still surface. Fifty tests across five
course reading/cache/progression/contract suites pass, including both readers,
account changes, cancellation and the existing progression rules. TypeScript
and scoped ESLint pass. The new helper replaces the duplicate read loop rather
than layering another retry around it.

This is a proven inconsistent recovery policy, not a confirmed diagnosis of the
emulator's initial load failure. Native reproduction and correlated production
evidence for that failure remain outstanding. `1234.apk` is still unchanged.

## Retire obsolete playback recovery after real native progress — 2026-09-05

Code inspection after the candidate observation exposed a separate, reproducible
recovery defect: `markPlaybackHealthy` only reset retry counters. It neither
cleared a terminal error nor cancelled a queued remount, pending source-refresh
completion or late diagnostic. An already-playing decoder could therefore be
restarted and a failure message could remain above working video.

Five behavioral cases failed before the change. Recovery now retires those
obsolete operations when the current native owner advances the timeline, clears
the stale failure/loading presentation and retains the actual viewing position.
An obsolete manual-refresh flight cannot block a later manual attempt or clear
that later attempt's ownership. No retry limits, access gates or quality policy
were loosened. Existing lifecycle/reel guards still reject old-owner callbacks.

Verification: 31 tests across playback recovery, native-component lifecycle and
video event policy pass. The component-level cases prove advancing progress
cancels the queued remount while a stationary progress event does not. Persistent
failure still remounts with the saved position. TypeScript and scoped ESLint pass.
The previous dependency revision's CI 33977104125 also finished successfully in
JavaScript, Android and iOS; its staging smoke was skipped, not passed.

This source fix is not in the unchanged `1234.apk` and has not yet been exercised
in a newly installed native build. It closes the reproduced stale-recovery
behavior, not the still-unproven cause of the initial course-load/buffering
failures. Authentication, real purchase and chat acceptance remain open.

## Observe preview recovery on the delivered candidate — 2026-09-05

On the installed cf7b7b0 / Android 42 candidate, opening the free preview first
showed the course-load failure screen. Its retry entered playback. Playback then
showed buffering failures around 3 and 6 seconds before recovering and reaching
the end of the second free reel (75 seconds). The app displayed the two-reel
preview-complete gate, not a project-submission gate. Choosing a course category
then reached sign-in with Google and TikTok.

Contemporaneous read-only probes returned HTTP 200 for course details and the
Bunny master playlist. The first two segments of both the 240p and 720p variants
returned HTTP 200 in approximately 0.44–0.60 seconds from the Windows host.
Those host measurements do not establish Android transport or decoder health
and do not explain away the observed buffering. No playback settings, retry
limits, course permissions or source code were changed to manufacture a pass.

Google entry opened Chrome's first-run setup on the emulator; no account was
authenticated and no provider callback completed. Back returned to Rokn's
usable sign-in screen without a stuck loading state. Browser setup and real
provider authentication still separate this observation from a completed
purchase or chat journey. The exact cause of the initial load/buffering failures
remains unproven; successful recovery is not evidence of uninterrupted playback.

## Resolve the remaining dependency gate without changing learner flows — 2026-09-05

Mobile CI 33975028743 on cf7b7b0 completed both native builds successfully,
including the corrected Swift RTL selector. All 156 JavaScript suites / 818
tests and the accessibility source scan passed. The final dependency audit alone
failed on new advisories for `@xmldom/xmldom` and `qs`; this was not a failed
checkout, login or chat test.

The exact lockfile now updates both installed xmldom branches (0.8.13 to 0.8.15
and 0.9.10 to 0.9.12) and qs (6.15.3 to 6.16.0), within their existing parent
dependency ranges. No overrides, audit exceptions or learner-flow changes were
added. The backend npm lock contains neither package. The affected mobile paths
are Expo/Xcode plist tooling and the React Native CLI server, not direct imports
from learner screens. `npm run audit:release` passes after the update; the
previously reviewed navigation advisory remains explicit, not silently removed.

The updated JavaScript legal inventory and unchanged native legal inventory
both pass (734 npm packages, 241 Maven coordinates, 127 Pod roots). The 18
targeted legal/toolchain tests pass and release configuration remains consistent.
Direct probes confirm both xmldom branches reject invalid entity names while
retaining valid references, both plist callers round-trip their values, and qs
preserves ordinary queries while rejecting the documented array-limit bypass.

References: [xmldom advisory](https://github.com/advisories/GHSA-6gmq-8vp8-gcm6),
[qs array limit advisory](https://github.com/advisories/GHSA-x5fp-wj9c-mxmx),
[qs isBuffer advisory](https://github.com/advisories/GHSA-4mjr-xmp4-gh2g).

The existing `1234.apk` is an internal-test artifact built from cf7b7b0
(1.0.41 / Android 42), SHA-256
`0263502515fb4ef5db59fbb3c658cb5e1fec9d3bd7196f2e9d1fcd72ae2723b3`.
It was installed as an update on emulator-5560; guest home and the production
course detail page loaded. That does not establish successful authentication,
paid course purchase, project submission or AI replies. This dependency update
does not alter that already-built file and must not be attributed to it.

## Make deployment-sensitive fixtures test the configured deployment — 2026-09-05

Mobile run 33973207771 completed Android successfully but failed five JavaScript
assertions in three suites. The catalogue cache-key assertion, the social-auth
cache fixture and the public-link tests assumed the developer's Cloud API while
CI deliberately configures `https://ci.invalid/api/v1/`. The same five failures
were reproduced locally with that API and the release runner's test-mode Babel
environment. Fixtures now use the configured API identity; checks still reject
an unrelated deployment's cache and unconfigured public hosts. Runtime code and
CI's non-production API were not relaxed. All 156 suites / 818 tests then passed
under the CI API; the focused four-suite scope also passed against the current
Cloud API (29 tests).

iOS in the same run compiled past the optional binding but rejected the renamed
Swift selector `swapLeftAndRightInRTL`. It now uses the compiler-specified
`swapLeftAndRight(inRTL:)` with its value still true. Another native compile is
required; JavaScript success does not establish that result. Version 1.0.41
(Android 42 / iOS 39) identifies the next candidate and is not yet a delivered
artifact.

## Close the existing payment path before changing it again — 2026-09-05

Re-read Kashier settlement, wallet credit, course purchase/replay, the mobile
checkout return and the entitlement reload on source 47029dac. No further
defect was reproduced in that scope, so no payment implementation was changed.
The targeted backend scope passed 75 tests / 449 assertions and the mobile
scope passed seven suites / 37 tests. This does not replace a native paid
purchase with the deployed service.

A fresh read-only production query confirmed the reported package checkout is
settled, its 900-coin credit exists once, the wallet remains 945 (900 paid and
45 reward), and no enrollment or new debit exists for the trial course. No
manual credit, enrollment, replayed charge or other financial repair was made.
The learner has not yet demonstrated a successful course-purchase retry after
the already-deployed schema repairs. Do not label that live journey complete.

Backend CI 33973207780 passed against exact commit 47029dac0072c3327d36c22679c21449e727f1f9
before manual Cloud deployment 173. Cloud then reported Deployed. Post-deploy
GET /api/health/live and /api/v1/courses/3/details both returned HTTP 200; the
course retained revision 30, three modules and its 400/650/900-coin plans.
Its public share URL points at the current Cloud host, not the old domain.

On the existing installed Android 123 artifact, choosing a plan after completing
the free preview reached sign-in and displayed Google and TikTok. That observes
the native entry/return boundary only: no provider authentication or purchase
was performed. Source changes after 123 remain absent from that installed APK.

The integrated September 5 batch below passed 107 targeted backend tests / 669
assertions and five mobile suites / 17 tests, plus TypeScript and scoped ESLint.
The combined local PHP run needed 144 MB (its default 128 MB was insufficient);
it passed with a 512 MB test-process limit. No production memory setting changed.
Native CI and deployed acceptance remain separate evidence.

## Keep authoring responses aligned with the committed graph — 2026-09-05

Staged publication prepared its notification campaign in an after-commit
callback. A preparation exception therefore reported failure after the new
course graph had already committed and prevented the following catalogue-cache
callback. Campaign preparation now belongs to the graph transaction. Its
existing durable dispatch still runs after commit and contains broker failures.
A preparation failure rolls back the graph and leaves the draft available.

Photo replacement had the same boundary error: it created the old-file cleanup
ledger after commit, so a ledger exception could make a successful save appear
failed. Cleanup reservation now participates in the owning transaction. Only
the existing durable job dispatch is deferred, and its worker rechecks file
references before deleting bytes. No synchronous remote deletion was added.

The three new regressions use actual commits without an enclosing test
transaction. They cover publication rollback, failed cleanup reservation during
the real moderator update route, and successful replacement with deferred
cleanup. All three passed with 27 assertions. This is local database/storage
proof, not a browser upload or a production publishing acceptance result.

## Use the same certificate QR destination in the artifact and app — 2026-09-05

The generated certificate already used the selected template to direct practical
certificates to the portfolio and theoretical certificates to verification.
The mobile screen instead always rendered verification. The API now exposes
`qr_destination` from the existing destination service, and the app uses its URL,
title and hint rather than reimplementing template rules. Opening or sharing an
individual certificate remains a verification link. Portfolio sharing still
exposes works, not the owner's certificates or saved library.

Legacy offline cache entries retain the credential with QR hidden until refresh;
they do not guess a destination. Malformed current responses remain explicit
contract errors. Deploy the additive backend field before shipping this mobile
consumer. Server-resource, API-mapper, cache-migration and rendered-screen cases
passed independently; they do not prove a QR scan on the installed native app.

## Separate native-build evidence by platform — 2026-09-05

Mobile CI run 33970773053 built Android successfully. iOS passed installed legal
provenance but failed actual Swift compilation because `RCTI18nUtil.sharedInstance()`
is optional. Its three existing RTL settings now use one optional binding before
React startup, preserving their values. Compilation awaits the next CI run.

The JavaScript job in that run exhausted its 20-minute job budget while checking
native provenance, not on a test assertion. Its budget is now 35 minutes; no
release gate was removed. A successful Android build is not evidence of iOS
compilation, full JavaScript completion, or a new APK delivered to the learner.

## Judge submitted image content independently of PNG encoding — 2026-09-05

The project effort guard rejected genuine indexed PNG artwork because it read
the palette index as packed RGB. It also mistook differently coloured artwork
for a solid image when the colours had identical mean brightness, and ignored
transparency masks used by one-colour logos. These are file interpretation
errors, not a new quality threshold for student work.

The guard now resolves the actual RGBA channels for both image encodings using
the [documented GD colour lookup](https://www.php.net/manual/en/function.imagecolorsforindex.php),
compares visible channel variation after alpha composition on both black and
white surfaces rather than average brightness alone, and recognizes genuine
transparency-mask detail without accepting colours hidden at near-total
transparency. Entirely transparent images and solid dark, white or coloured
canvases remain invalid. Existing decode byte/pixel limits remain intact. The
uniformity setting now names its per-channel meaning and accepts the previous
environment name as a fallback.

Twelve real GD-generated PNGs were submitted through ProjectSubmissionService,
including indexed/truecolor artwork, equal-brightness hues, black/white transparent
logos, near-transparent hidden colour/mask cases, visibly translucent artwork,
and invisible/solid images. Two near-transparent cases were accepted before the
alpha-composition repair; all twelve now receive the intended persisted
effort/review state. The focused image and document effort scope passed 13 tests
/ 54 assertions. This uses a local test database and storage; it is not a
production submission or proof that the heuristic can semantically grade every
possible project.

## Keep saved-library reads and writes recoverable across navigation — 2026-09-05

Leaving the saved library during pagination invalidated the old generation
without resetting its visible `loadingMore` state. The stale request's guarded
`finally` could no longer clear it, so returning left pagination permanently
disabled. Focus now resets pagination state together with its request guard.

The same focus/settlement mismatch could leave folder creation or deletion and
saved-item removal visibly busy after returning. Mutation ownership is now an
account-bound flight object rather than an unowned boolean/set. Navigation does
not unlock a still-running write. Only its own completion releases the flight;
the focused view derives busy state from those flights. Settlement across a
different focus generation refreshes server data instead of applying an old
snapshot, including a lost response after a successful server write. No new
backend mutation or optimistic success contract was introduced.

Six mounted-hook scenarios cover interrupted pagination, settlement before and
after refocus, lost create/delete responses after simulated server commit, and
failed removal. The focused five-suite scope passed 23 tests, including existing
mapper/default-folder/recovery tests; TypeScript and scoped ESLint passed.
This is source-level navigation coverage, not a claim of native-device acceptance
or an update to the user's installed APK.

## Resume interrupted daily flows without treating an attempt as completion — 2026-09-05

Three new defects were reproduced through mounted React hooks before their
source changes, not inferred from a search result or a source-string assertion:

- Cold-start OAuth kept a recoverable callback after a transient failure but
  silently returned to guest mode. Startup, foreground and unclaimed deep links
  now use one resumption owner. A visible retry consumes the saved attempt
  without reopening the provider. Session epoch, pending-attempt identity and
  mounted-state checks prevent an old alert or delayed read from resuming or
  reporting a newer account's attempt. Concurrent observers join one flight.
  This is explicit retry while foregrounded, not automatic network monitoring.
- Resubmitting a rejected project could receive `needs_changes` again without
  changing the parent status. The hook closed its editor awaiting a hydration
  effect that would never rerun. An accepted retry result now prepares the
  empty next draft immediately; pending/passed outcomes still close editing.
  Runtime coverage submits twice with the same rejected status and preserves
  typed drafts when an unchanged API contract is remapped.
- Closing a project report, or backgrounding the app while its report was
  loading, cancelled the consumer but left a ref marking it already hydrated.
  Returning never restarted the request. The ref now records successful
  hydration only; ownership cleanup still rejects the interrupted response.
  Both close/reopen and background/foreground cases failed before the fix and
  passed afterward, including a late old response arriving before the new one.

The combined focused scope passed 14 suites / 62 tests, including existing
source-contract checks as well as the new runtime cases. An outdated profile
source assertion was aligned with the already-shipped portfolio-tab-only QR
visibility rule; no profile UI behavior was changed for that assertion.
These source fixes are not present in the user's `123.apk` and do not prove a
live social-provider login or a full native project journey. Backend payment
settlement and enrollment remain separate acceptance steps.

Two chat suspicions were deliberately not converted into changes. The queue's
60-second admission deadline is an explicit SLA and already releases unused
reservations with retry enabled. Account changes key/unmount the navigation
tree, so a proposed same-mounted-chat cross-account render was not reachable
through the actual composition. Source tracing also confirmed the existing
background/restart recovery uses the same client request ID and merges terminal
server replies authoritatively; it is not a new live-provider test.

## Stop production deployment from racing its checks — 2026-09-05

Cloud production had **Push to deploy** enabled and its deploy hook disabled.
The repository had independent backend/mobile checks and no CI-to-Cloud
promotion. Deployment 172 published `d06502d` before Backend CI finished;
run `33968098743` subsequently passed its full suite and migrated-MySQL gates.
Passing afterward does not make that release ordering sound.

Push-to-deploy was disabled in the actual production environment and saved;
the switch was verified unchecked with no unsaved-change prompt. The running
release was not stopped. Future pushes are not authorization to deploy.
The runbooks now require a successful Backend CI result for the exact commit,
followed by verification of Cloud's deployed commit. Automatic promotion is
not configured yet; it requires an authorized deploy hook stored in GitHub
and invoked only after CI success with an explicit `commit_hash`, supported
by the [official Cloud deployment contract](https://laravel.com/cloud/docs/deployments#deploy-hooks).

## Correlate actual failures without mixing request and payment identities — 2026-09-05

Backend Sentry kept request headers but discarded every user identifier.
The existing before-send scrubber now derives searchable request tags from
the current request and keeps only the authenticated internal user ID. It
removes inherited correlation before handling a guest; names, email, IP,
request bodies and secrets remain excluded. A real Laravel HTTP-kernel test
throws from authenticated and guest routes through Handler, Integration and
the configured before-send callback. Both events are intercepted locally
before transport. The focused regression scope passed 3 tests / 26 assertions.
An earlier proposed middleware-scope implementation was rejected because this
real exception path did not retain its context; direct scope-only assertions
were not accepted as proof.

Two mobile checkout failure paths previously labelled the payment order
reference as `request_id`. They now carry `order_ref` separately, retain the
actual HTTP response/config correlation ID, and do not invent an HTTP ID for
an aggregate polling timeout. The mobile telemetry/checkout scope passed
27 tests. No production event was injected and delivery to Sentry has not
been verified by this source change. Handled business responses without an
exception are not automatically new Sentry events. Nightwatch's previously
observed free-quota exhaustion is not fixed by these changes.

## Regenerate native notices without rebuilding unchanged locks — 2026-09-05

Native refresh run `33965347270` completed dependency resolution and confirmed
the locks were already current, but failed notice generation on the abbreviated
`Apache` license in `GTMAppAuth@5.0.0`. The exact official version's
[podspec](https://github.com/google/GTMAppAuth/blob/5.0.0/GTMAppAuth.podspec)
and [LICENSE](https://github.com/google/GTMAppAuth/blob/5.0.0/LICENSE)
establish Apache-2.0. The existing reviewed-coordinate map now recognizes only
that version; unknown abbreviated licenses remain rejected.

The existing workflow gains an optional `reuse_native_locks` path. It installs
actual Pods with `--deployment`, verifies the lock stayed unchanged, and runs
the full native source/license generator and checks. It does not repeat Android
lint/tests/bundle to repair notice metadata. The original full refresh remains
the default. YAML parsing and 23 focused workflow/native-notice tests passed.
Generated notice artifacts are still pending the next macOS run.

Run `33968136485` proved the reuse-lock path and reached legal generation in
about 11 minutes, then found the same abbreviated-license family in
`GoogleSignIn@9.2.0`. Before another run, all 127 locked roots were inventoried:
all 12 trunk specs matched their lock checksums, and all 115 external/local
records passed classification. The existing exact Apache/BSD exceptions were
retained; only GoogleSignIn 9.2.0 needed another exact Apache-2.0 mapping, proven
by its [tagged license](https://github.com/google/GoogleSignIn-iOS/blob/9.2.0/LICENSE).
No unreviewed ambiguous classification remained in that complete metadata
inventory. The 23 focused tests passed; installed-source provenance generation
still requires the subsequent macOS workflow to succeed.

Run `33968910951` restored the exact locks successfully, then failed generation
on a stale `EXApplication@55.0.17` upstream-source review. This was not another
ambiguous license: 11 old Expo coordinates had been retained beside their
current replacements. Removed only those absent from the current lock. The
remaining 17 upstream-source reviews, five exact-license exceptions and two
first-party generated Pods all belong to the 127-root inventory; the absence
allowlist is empty. The regression test now checks the entire review map
against the lock instead of filtering away stale entries. The 23 focused
native/workflow tests pass. No generated output or lock checksum was fabricated;
the full installed-source generator still must complete on macOS.

The subsequent exact run `33970159396` succeeded in 10m7s and published bot
commit `f7f2672`. Installed-source checks covered 241 Android Maven coordinates
and 127 CocoaPods roots, including `RNSentry@7.11.0` and `Sentry@8.58.0` with
their MIT documents and lock-matched podspec checksums. Generated iOS provenance
has 125 installed-source entries plus two generated first-party roots and
manifest SHA-256 `1b12d3b8395d485bdb90ef2aabb1ef3b874ea068ecdc6e4fafc7c7adf10bd685`.
The generated artifacts were fetched and integrated without replacing the
concurrent source fixes. This closes notice regeneration, not native-app or
production-journey acceptance.

## Refresh purchased course capabilities together — 2026-09-05

The chat upgrade previously unlocked only its local chat flag. The loaded
course could retain the old plan's attachment and project-report/reply
capabilities until a reload. Confirmed upgrades, including a lost reply
recovered through the quote endpoint, now refresh the existing authoritative
course aggregate. The same account-bound loader is used rather than copying
individual capability flags from the small purchase response.

Runtime hook tests cover confirmed purchase refresh, late settlement after an
account switch, and quote recovery after a lost reply. The real course-loader
hook is also exercised against an API mock: it adopts all refreshed attachment
and project feedback capabilities without clearing current content or moving
the requested reel index. Refresh failure retains the content with a connection
note, and late responses from another account are ignored. The loader issues a
fresh details GET rather than reading the guest details cache. TypeScript,
scoped ESLint and the combined 5-suite / 40-test scope passed. These are not
native overlay/rendering or paid-provider acceptance tests. These mobile
changes are not in the previously delivered `123.apk`.

## Purchase receipt acceptance and readable error messages — 2026-09-05

The migrated-MySQL gate now also exercises the real course-authorization
controller, wallet, receipt and paid-lot allocation services. Its isolated
learner starts with 900 paid coins and 45 reward coins, buys the mentor tier,
then repeats the same request key. Assertions require one order, bill,
enrollment, debit and allocation; a 45-coin remaining paid balance; no financial
hold; and an idempotent second response with no new debit. The product-feature
switch middleware is excluded from this financial contract test, so it is not
evidence of the full installed application's checkout. Backend CI run
`33966557356` passed on `27b2b3a`, including clean MySQL migration, the schema
preflight, all three MySQL financial tests and the full backend suite.
Cloud deployment 171 confirmed `27b2b3a` deployed successfully.

A separate production read confirmed that the real learner's 900 paid coins
are still backed by active lot 2 from settled Kashier order 7 / credit 10.
The earlier 4,200-coin lot has no remaining balance. No financial record was
changed by this inspection.

82 Arabic PHP messages in 42 application files used single-quoted `\n` and
therefore exposed literal backslashes instead of the intended line breaks.
Only quoting was corrected; message wording and business decisions were not
changed. A PHP-token regression catches this exact Arabic string family while
leaving regular-expression and parser escapes alone. Source verification:
42-file syntax checks passed, and the combined copy/CI/OpenRouter scope passed
14 tests / 50 assertions.

## Daily checkout, reply and project state closure — 2026-09-05

Backend CI run `33964361469` for `fcd30f2` passed the migrated MySQL product
schema preflight and the full backend suite. Cloud deployment 168 deployed
that commit. This only verified schema shape, not that its CHECK constraints
accepted the current payload. A later production-log read found new purchase
attempts at 11:52 UTC failing MySQL 3819: both order and enrollment snapshot
constraints still accepted v1–v3 while the application wrote v5. The previous
claim that the schema gap was closed was too broad. The forward repair must
update both constraints, and CI must exercise actual inserts against MySQL
rather than accepting SQLite application validation as equivalent evidence.
The read-only production check after these failures still found 945 coins
(900 paid / 45 reward) and no course 3 enrollment. No repeat debit occurred.
Native checkout acceptance and a successful paid-provider reply remain
separate evidence requirements.

The next read-only production probe generated all three course 3 plans through
the real snapshot writer and evaluated each against both installed JSON
schemas: all six were rejected. The new forward migration replaces each
constraint in one ALTER, retains historical versions and preserves plan/order
identity requirements. The new MySQL CI insert test uses the actual current
writer for all three tiers, tests historical snapshots and rejects incomplete
or mismatched receipts. It fails rather than silently skipping when the CI
job requires MySQL. Deployment 169 (`32453aa`) applied the new constraints.
The identical production probe then accepted all six real tier/schema pairs
and still rejected version 999. It did not debit coins or create enrollments.
CI run `33965327061` migrated successfully but correctly failed closed because
the default PHPUnit XML selected SQLite. The dedicated MySQL configuration
fixes that test-runner mismatch. Run `33965770077` then passed both actual
MySQL tests (15 assertions), including current/historical inserts and negative
constraint checks. The Artisan wrapper still returned failure for a duplicated
configuration option, so this focused gate now invokes PHPUnit directly.

The live OpenRouter provider catalog exposed another request-contract gap:
`max_completion_tokens` with `require_parameters=true` restricted the configured
GPT/Claude route to Azure endpoints. The shared `max_tokens` ceiling keeps two
OpenAI endpoints eligible for GPT-5 Mini and all nine listed Claude Sonnet 5
endpoints eligible across its providers. The primary no longer uses Azure's
two legacy-parameter endpoints. No model, output budget or production setting
was changed. Course replies and project feedback use the same service.

The request regression verifies the actual primary/fallback pair, strict
parameter support, the shared output ceiling and minimal reasoning together.
Focused OpenRouter tests passed 12 tests / 25 assertions and course-chat
hardening passed 12 / 68. Catalog eligibility is not proof of a successful
production reply or the cause of every earlier failed turn.

Primary evidence checked on 2026-09-05:
[GPT-5 Mini endpoints](https://openrouter.ai/api/v1/models/openai/gpt-5-mini/endpoints),
[Claude Sonnet 5 endpoints](https://openrouter.ai/api/v1/models/anthropic/claude-sonnet-5/endpoints)
and [provider parameter routing](https://openrouter.ai/docs/guides/routing/provider-selection).

The source changes following the live purchase hotfix close three state-loss
paths. They are not included in the previously delivered `123.apk`.

- Course purchase return now waits for the same readiness decision as the
  visible CTA. Previously it consumed the login-return intent while the wallet
  was still loading. Mixed free/paid tiers now use one wallet requirement in
  both loading and presentation instead of treating the lowest zero price as
  proof that commerce is unnecessary. Entirely free courses remain independent
  of the wallet. Coupon quoting blocks competing top-up and plan-change taps.
- Wallet and embedded course top-up use the same error classification. A
  provider configuration/opening failure is not described as an uncertain
  payment. A genuinely uncertain result refreshes the course state and is not
  reported as an unopened checkout. These paths do not authorize the course
  or invent a successful charge.
- An interrupted provider stream checkpoints its last visible text before
  preserving the failed/unknown outcome. It does not silently issue another
  paid provider request. Project submission feedback survives the immediate
  response and remount, but a prior rejection is cleared when a new submission
  starts or passes. Server report/reply entitlement remains authoritative.

The migration verification gap is closed at the actual driver boundary:
GitHub CI runs the existing schema preflight immediately after MySQL replay,
before the SQLite suite. The same contract now covers all daily financial write
fields in orders, bills, enrollments and wallet entries. Successful migration
execution alone is not accepted as proof of a usable product schema.

Focused source verification: 91 mobile tests across 10 suites, TypeScript and
targeted ESLint passed. The combined backend scope passed 27 tests / 189
assertions, including schema upgrade, preflight, notification relations and
streaming. These results do not replace native or paid-provider journey
acceptance against the resulting release. The prior hotfix `b87d252` also
completed its GitHub CI run successfully.

## Live top-up succeeded but course purchase failed — 2026-09-05

Production deployment 166 (`3c30789`) accepted Kashier order 7 for EGP 10.
The verified webhook credited 900 coins exactly once. Read-only production
inspection confirmed a 945-coin balance (900 paid / 45 reward), no course 3
enrollment and no course debit from the failed attempts. No compensating
credit, enrollment or repeat payment was performed.

The failing order insert reported MySQL error 1054: `orders.coupon_code` was
missing. A frozen legacy cleanup migration removed that column on MySQL but
not SQLite; a later nullable migration skipped the absent column. The API
test fixture also created it manually. This made the tested schema differ
from the deployed schema. The forward-only repair restores the nullable
column without rewriting existing orders. An upgrade regression starts with
the actual missing-column shape, and the release preflight now rejects it.

The same production log exposed notification inbox failures: the presenter
requested `courseSection` on Course although that relation belongs to Lesson.
Both invalid eager loads are removed, with a regression for course and lesson
notifications. Commit `b87d252` was deployed as release 167. The deployment log
confirmed the forward migration ran successfully and both preflight checks
passed. A subsequent production read confirmed no missing fillable columns
across orders, bills, course enrollments and wallet transactions. The same
900-coin package credit still exists exactly once; the balance remains 945
and course 3 has not been purchased yet. The learner's post-fix purchase
attempt remains the final end-to-end confirmation; no purchase was made on
their behalf to substitute for that evidence.

The administrator completed MFA. The global mentor chat allowance was saved
successfully through dashboard settings and read back as 50 messages (guided
also remains 50). Existing purchase snapshots are not rewritten by this change.

## Internal APK 123 native acceptance — 2026-09-05

Internal direct APK 1.0.40 / Android 41 was built from clean commit `a2b6861`.
The canonical artifact and delivery copy `123.apk` are 79,628,283 bytes with
SHA-256 `4ede999c73198c151e7d8818ba92fd6a21d622a19403cda9dffc30487a173281`.
It uses the explicit production API environment and the existing internal
debug signer; it is not a store-release signing approval.

The exact artifact was installed over the previous candidate without clearing
emulator data. Native screenshots confirm right-aligned Arabic and the authored
order of Grease Pencil, Blender Studio and CC BY. After dismissing the optional
notification primer, two screenshots seven seconds apart showed different reel
frames, advancing playback and a decreasing remaining time. The initial
restoration indicator was transient. Evidence is under
`mobile/.cache/rtl-acceptance-1.0.40/` (`course3.png`, `reel1.png`,
`reel-play-t0.png`, `reel-play-t7.png`). This proves this guest course/reel scope,
not authenticated checkout, project processing or all-device acceptance.

Drive file `1Iq_ndZeiylQtDk51XpndXd_N7LH8xyEW` contains the final `123.apk`
as its current version, with anyone-with-link Viewer access. The private
1.0.39 candidate remains a recoverable older revision, and delivered `12.apk`
was left unchanged. The EGP 10 package is live. The global 50-message allowance
was subsequently saved and verified as recorded in the live incident above.

Separate release work remains: the iOS CI job for `dda9598` reached native
license checking but found a generated CocoaPods-notice snapshot that no
longer matches Podfile.lock. This was not fixed by hand or hidden by relaxing
the check. It does not change the Android artifact verified above.

## Native mixed-direction text checkpoint — 2026-09-05

The rebuilt internal candidate 1.0.39 / 40 at `dda9598` was installed without
clearing emulator data. Native screenshots confirmed right-aligned Arabic on
the course, reel and guest chat. Inspection also exposed a separate shared
formatter defect: Latin phrases were isolated word by word, reversing names
such as Grease Pencil and Blender Studio within the RTL paragraph. That
candidate was uploaded privately but withheld from delivery.

The formatter now isolates each contiguous Latin phrase as one directional
unit. URLs, email addresses, phone numbers and identifiers remain intact; the
regression checks also cover repeated formatting. The source fix passed ten
locale tests, targeted lint and TypeScript. The next rebuilt artifact must
still demonstrate correct phrase order in native screenshots before delivery.

## Reported failures in 12.apk — 2026-09-05

The exact delivered APK (1.0.38 / 39, hash below) was installed on an isolated
Android emulator. Guest Home and published course 3 loaded successfully. Its
Arabic paragraphs were visibly left-aligned. Both native text renderers mirror
literal left/right alignment under RTL; the shared paragraph style now uses
natural alignment with explicit RTL layout and writing direction. Centered
labels and explicit LTR URLs are unchanged. Post-fix native rendering is not
yet verified in a rebuilt artifact.

The reported payment message matched a production checkout veto before Kashier
initiation: missing backup/restore-drill evidence was being checked on every
purchase. Runtime checkout now follows its feature flag and the explicit
disaster-recovery pause. Backup evidence remains an independent launch-readiness
requirement, not a per-payment dependency. Wallet copy distinguishes service
unavailability from uncertain payment status. No paid transaction has been
performed to claim end-to-end payment acceptance.

The top-up packages inside the course dialog now give their name, amount and
price separate full-width space. Portfolio sharing and its QR are visible only
in the works tab. The save picker always offers Watch Later through the existing
idempotent default-folder API, followed by custom lists. Course chat is labelled
"استفسارات الكورس" without changing its privacy policy or impersonating the
instructor. The authoring form preserves already-linked administrator instructors
without exposing unrelated administrator accounts.

Device rows now represent installation IDs rather than individual bearers.
Historical releases could omit the installation ID after a short storage timeout.
The next successful identified login retires these unowned legacy bearers;
separate identified devices remain intact. A legacy device may consequently
need to sign in again. No devices are merged by their model name.

The existing production test package 4 was saved through the dashboard and its
public API verified: 900 coins, base price EGP 11.11, direct checkout EGP 10.00
after the existing 10% discount. This covers course 3's highest 900-coin tier.
Changing that tier's global message allowance from 150 to 50 reached the
dashboard's two-factor challenge and is not yet confirmed saved. An unchanged
editing draft (course 7) was opened from published course 3; it has not replaced
the published course. Nightwatch also reported an exceeded free quota in Cloud
logs; automatic monitoring coverage must not be assumed complete.

## Built internal artifact and dashboard-role clarification — 2026-09-05

The internal direct APK 1.0.38 / Android 39 was built successfully from mobile
source at `6f9e456`. Its canonical artifact and the delivery copy `12.apk` both
have SHA-256 `7eaf0f3603db321a8f649b5dd143ce67fe8f2c2ea25e78fdd43b944edce0dd69`.
It uses the explicit production public environment and the prior internal
signer. The metadata correctly records a dirty repository because an unrelated
backend dependency-isolation edit was in progress when the build finished.
There were no uncommitted mobile-source edits. This is an internal test artifact,
not a public-release signing or native-acceptance approval.

The reported revenue card was seen using the Rokn administrator account, as the
user clarified. It was not evidence of a moderator disclosure. The existing
header names the role, the permission matrix restricts financial routes, and
Home already returns the separate moderator workspace. A small dependency
isolation improvement additionally resolves financial reports only inside the
administrator branch. Focused moderator and administrator checks passed three
tests / 17 assertions. The sample course was uploaded using the administrator;
that does not prove the production moderator authoring journey.

Native acceptance remains pending: the disposable emulator booted but the ADB
server and emulator used different host keys. No successful app installation,
guest journey, OAuth completion, payment or project acceptance is claimed here.

## Internal release candidate 1.0.38 — 2026-09-05

This checkpoint supersedes the course-upload status in earlier checkpoints.
Production course 3 is published: 15 reels, three modules, three project gates,
two free previews and three purchase plans. Its dashboard was reloaded to verify
the saved state. Upload completion does not prove native playback, purchase,
project processing or certificate acceptance.

The first full mobile test run exhausted C: while writing Jest transforms.
The workspace now owns its Jest cache and Android build temporary directory on
the SSD. After moving only the identified Rokn transform cache, the complete
rerun passed 146 suites / 762 tests; configuration, TypeScript and release ESLint
also passed. Reapplying the existing RNFirebase postinstall patch restored all
60 release-script tests without changing application source.

The Android entry harness now follows the actual direct-to-Home guest journey.
It never clears app data by default. Reset requires an explicitly disposable
device and Android's QEMU marker. Its OAuth checks prove Google/TikTok browser
handoff and cancellation only, not authentication or durable session creation.

Version 1.0.38 (Android 39, iOS 36) is prepared for an internal APK using the
previous test signing identity and the explicit production public environment.
The previous APK and its metadata/symbols are retained before building. At this
checkpoint the new artifact has not yet been built or installed. Public release
signing is not configured locally. Sentry configuration is present, but matching
Hermes/R8 symbol upload and readable external stack traces remain unverified.

## Current acceptance boundary — 2026-09-05

The latest user-installed APK failed Arabic alignment and course assistant
entry-point acceptance. Earlier checkpoints below are historical evidence,
not approval of the current app. No replacement APK has been built for this pass.

Current source repairs cover partial authoring updates, one course-details/map
snapshot, visible versus usable chat actions, Arabic direction, durable social
login identity, payment review recovery, cancelled/deferred store purchases,
bounded assistant/media recovery and the shared dashboard runtime.

Project recovery now reuses the durable submission and treats recovered outcomes
as refresh signals, never as authority to overwrite the current course map.
Initial course data, thread hydration, project polling and message responses use
one feedback mapper so retry permissions and server-owned attachments survive
refresh. Runtime tests cover a stale recovery result against a newer passed
project, failed map refresh, and `can_retry=true/false` across the read paths.
Concurrent map reads are also ordered within the course owner: a slower old
response cannot replace a newer pass decision. A database-backed presenter test
checks report-only and paid-upgrade reply permissions in both summary and full
transcript responses.

Portfolio image/video recovery ignores callbacks for replaced URLs and allows
one automatic recovery until the media actually displays. Successful display
allows a later expiry to recover; issuing another signed URL alone does not reset
the failure budget. This prevents both permanently exhausted previews and an
endless refresh loop for a file that cannot be displayed.

The dashboard deployment at `988d9ea` was observed as deployed in Laravel Cloud.
On production, saving course 3 refreshed its course description and readiness
preview without replacing unsaved form fields. Its second reel previously failed
at 0 percent with `Failed to fetch`. After this deployment, the same second video
and thumbnail were uploaded through the studio UI, saved inside the first module,
and the next inline editor opened automatically. This proves one real upload and
save, not decoder/playback acceptance or completion of the 15-reel sample course.
The first module has five saved reels and its image-submission project,
all entered one by one through the studio. Course 3 remains an incomplete draft;
readiness still requests its cover, teacher, classification and video readiness.
The first reel's edit form confirms its free-preview flag is already enabled.
Its missing initial outline badge was a separate display inconsistency: Blade
omitted the preview flag while JavaScript rebuilt it independently. Both now use
the presenter's shared row label, including duration and project placement.
The repair passes 13 PHPUnit tests / 203 assertions and the isolated studio
browser fixture; the stored preview value was not changed.

The user reported broken coin-rule dashboard dimensions. Live inspection at
1024px confirmed roughly 70px name fields, delete actions overlapping the next
row, and final delete actions clipped by the enclosing panel. The update form
used all of its column height while the delete form was its following sibling.
The repair places both actions in the card's own layout, gives fields practical
width, and separates method titles from wrapping status badges. The same
form-height/sibling pattern was not found elsewhere in the admin templates.
An isolated CSS layout fixture covers 360/768/1024/1440px. Production screenshots
confirm useful-width inputs and save/delete actions inside their cards without
overlap or clipping at the observed browser size. A later live viewport override
did not actually resize the browser, so the fixture's four breakpoints must not
be reported as four independently verified production viewport sizes.
No reward value, campaign or financial setting was changed during this check.

Profile editing now shares one revisioned name/image/professional-headline
contract, while public portfolio sharing excludes certificates and saved items.
New practical certificate QR codes point to the portfolio; theoretical codes
point to that certificate's verification page. Generated samples were visually
inspected and both QR destinations decoded. Previously issued artifacts remain
immutable. These checks do not establish native portfolio acceptance.

Automated checks cover the repaired contracts and isolated browser behavior.
They do not establish signed-APK acceptance, a completed provider callback,
actual store purchase, real-device RTL or decoder behavior. Portfolio/certificate
acceptance and remaining dashboard pages still need their own evidence. No
whole-project completion or zero-defect claim follows from these repairs.

Local integration check before the follow-up home-curation and layout repairs:
all 138 mobile Jest suites passed (722 tests), TypeScript and changed-file ESLint
passed, and the selected backend integration group passed 238 tests / 2898
assertions. Studio first-lesson and Bunny recovery browser fixtures passed using
the existing bundled Playwright runtime. These are source/isolated checks, not
native Android, external-provider or production-payment acceptance.

Changes through `fe3cb38` are pushed to the production repository and observed
as deployed in Laravel Cloud. The home-curation integration now preserves hidden
canonical courses and revision/archive membership when saving a visible row.
Publishing merges course-draft and live-row edits against the recorded base;
explicitly clearing the hero remains cleared across partial saves. The focused
post-integration group passed 54 tests / 1569 assertions. These tests cover
transaction ordering and sequential conflicts, not real simultaneous database
load. The live home-row index and create form render, with no production row
mutation used for verification. Both existing rows are currently hidden; manual
row configuration is still required before a new APK should use this feed.
No new APK is built and no whole-project acceptance is claimed.

### Live follow-up at `e0c7c52`

Laravel Cloud displayed this commit as deployed. The draft now contains two
modules, ten reels and two image-submission projects entered through the studio.
A fresh GET confirms those counts. Reel nine encountered an unknown save outcome;
the upload-resume action recovered it once without duplicating the outline row.
The third-module attempt did not appear in a fresh GET. Its title disappeared
after the automatic reload; module-create recovery is a separate repair, not
evidence that the third module was saved. Five remaining reels, course metadata,
manual home placement and publication remain unfinished.

The deployment also preserves rejected form input for course classifications,
teachers and individual reward cards without bleeding one card's input into
another. Failed chat streams retain their saved partial answer in status/history
with an incomplete-response marker; they remain failed and excluded from the
next model context. The combined backend check passed 85 tests / 467 assertions.
No live AI response or native-chat acceptance was established by those tests.

Media readiness was inspected in production rather than inferred from the
aggregate `attention` label. After manual checks, lessons 33 and 40 were ready
with readable HLS and their only remaining issue was the draft's missing cover.
Lesson 40's earlier `quality_ladder_missing` and `manifest_http_error` cleared
without changing provider configuration. The initial probe had stopped at the
provider's ready state before the CDN was ready; bounded draft-media recovery
needs to cover that transition. Queue heartbeats were current and no failed jobs
were listed. These observations do not prove playback on the Android device.

The latest coin-rule layout fixture still passes 360/768/1024/1440px. A fresh
production reload at 1024px again showed approximately 301px fields and contained
actions. No additional instance of the original form-height/sibling defect was
proved in the checked reward/package templates, so those templates were not
rewritten speculatively.

### Follow-up source repairs after `e0c7c52`

The lost-save-result family is handled using the existing authoring intent
ledger, not a second draft store. Module creation preserves the title and
original request identity across automatic reconciliation. Section creation
queries a read-only receipt before repeating a multipart request, retaining the
selected files when the outcome is not yet known. Receipt reads are actor- and
course-scoped and resolve the current resource; deleted or moved resources are
not recreated from stale responses. Browser fixtures cover committed saves with
lost replies for modules, lesson thumbnails and projects. This is source-level
evidence, not a claim that a production network interruption was reproduced.

Media probes now reconcile both provider readiness and the HLS document in one
attempt. Temporary CDN/rendition failures keep the bounded retry alive. Recovery
waits at least ten minutes, does not duplicate a still-queued job, and can
redispatch after a processing worker crashes. Its ninety-minute eligibility
window is anchored to the lesson generation update, not extended by every
probe. Older media still needs an explicit recheck; automatic recovery of that
old production sample is not claimed.

The operations dashboard now separates verified video playback from incomplete
course metadata, excludes invalid HLS and obsolete media generations from the
ready count, and uses bounded database chunks without loading manifest bodies.
The shared alert component also renders warning flashes previously dropped by
media, notification and playback actions. These repairs do not certify the
remaining operational alerts or the full student journey.

The final integrated backend check passed 58 tests / 1350 assertions. The
authoring browser fixture additionally covers a truncated success response and
a newer concurrent course version: a confirmed create reloads the complete
canonical outline rather than promoting a partially stale local graph. Public
asset inventory verification and the changed JavaScript syntax checks passed.

### Course authoring and learner-entry follow-up

A fresh production studio GET now confirms three modules, fifteen reels and
three project gates in course 3. The third project accepts images, PDF, Word or
presentation evidence and is the final project. Every reel was entered in its
module with its own title, caption and thumbnail. This is saved draft content,
not a published course or a native playback result. Cover, teacher and
classification still need completion before publication.

Reel fourteen exposed another allocation recovery gap: the video request could
be committed while its reply was lost, leaving preparation stopped at zero.
The existing continuation action recovered that live attempt without a second
lesson. The source repair now retries the same persisted allocation key within
a bounded backoff and distinguishes allocation-in-progress from a stale course
version. Exhausted recovery retains the selected files and offers continuation;
it does not reload the page or replace the upload attempt.

The live administrator teacher form also required email, phone and password for
a content profile, although the moderator form did not. Both roles can now save
the public profile without login credentials. Administrator updates with blank
credential fields preserve existing values. Moderator credential boundaries are
unchanged; no invented contact data or migration is needed.

Course enquiries remain visible independently of entitlement. A sample leads to
the course's available plans; a wholly free course without enquiries does not
invent an upgrade. Message history and send operations remain entitlement-gated.
The portfolio grid uses a local cover while renewing an expired image URL, with
bounded recovery rather than a permanent broken image or an endless loop.

The project effort check no longer rejects valid PDF page dictionaries solely
because their legal whitespace differs from a literal `/Type /Page` string.
The regression document is generated by the existing mPDF dependency, changed
without moving xref offsets, and reopened to verify that it contains one page.
This is an evidence-file acceptance repair, not proof that every PDF is readable
by a downstream model. No new parsing service or AI request was introduced.

The same responsive review found two further concrete layout gaps. The studio
included its editor stylesheet but omitted the editor's scope class from the
metadata panel, so its intended form grid and control styles never applied.
The student search action used a one-column Bootstrap slot at desktop widths,
leaving its text wider than the button after the sidebar consumed its share.
The panel now activates its existing editor styles; student filters use a scoped
grid with readable actions. The filter CSS fixture passed 360/768/992/1024/1440px.
Teacher forms and student detail did not show the same sizing cause in this
bounded review and were not redesigned.

Source verification passed 118 backend tests / 847 assertions before the final
HTTP allocation regression. That real-route regression plus section-state tests
passed 14 / 184 and proves the 409 machine code with one unchanged allocation.
The course editor scope/view checks passed 43 / 398. Mobile chat and portfolio
checks passed 13 suites / 65 tests before the additional stale-cover-mutation
regression; the portfolio follow-up passed 19 tests and full TypeScript checks.
These checks do not establish native-device acceptance or live AI replies.

Laravel Cloud showed `6dcdc0c` as deployed. The live studio now saves the cover,
two classifications, project certificate template and three test prices for
course 3. Its readiness list now contains only the missing teacher. The live
editor uses the intended grid and the student search actions no longer clip at
their actual desktop widths. The current browser viewport override did not
change these existing tabs' rendered width, so that attempt is not evidence of
five distinct live breakpoints; the named-size evidence remains the CSS fixture.

The first live teacher-profile submission after that deployment still failed
on duplicate email and password confirmation, although credentials were left
untouched and appeared blank after the validation response. The submitted
payload was not captured, so browser autofill is a hypothesis, not established
attribution. The follow-up repair explicitly separates profile editing from
login editing: credential controls are disabled unless the administrator opts
in, and the server ignores unsolicited administrator credential values in
profile mode. Moderator prohibition remains unchanged. A single rule builder
serves creation and update. Feature tests reproduce duplicate-email/mismatched-
password payloads in profile mode and verify intentional opt-in separately.

After `c1c31d7` was deployed, the real profile submission succeeded with all
credential inputs disabled and no login-edit opt-in. Teacher 7 was then linked
to course 3 through the studio; the server-backed readiness changed to ready
for publication. The same unintended credential-write family was found in
moderator editing and repaired there without changing intentional account
creation. Student editing has no password field and prohibits that input, so
it was not treated as the same defect.

The final course layout fixture exposed a 360px min-content overflow from long
selected labels. Adding `min-width: 0` to the existing editor field group kept
the row and Select2 controls within x48..312 of the x12..348 panel. Controls and
actions stay inside their actual ancestors at 360/768/992/1024/1440px; no extra
Select2 override was necessary. This checks element bounds, not merely the
document's clipped horizontal scrollbar.

The complete Blender course was published through the dashboard as canonical
course 3. Its staged visibility update was published back to that same course,
and the Design home row was enabled with this course selected. Fresh guest API
reads confirm catalogue membership, three modules, three plans, a populated
teacher image, `is_main_course=true`, `is_coming_soon=false`, and
`learning_started=false` at published revision 30. This is dashboard-to-public-
API evidence, not signed-APK playback, checkout or project acceptance.

The live visibility controls exposed duplicate IDs shared by a false-value
hidden input and its checkbox. All five such pairs in dashboard templates now
give the hidden fallback a distinct ID without changing submitted names or
values. The rendered studio/settings DOM regression checks uniqueness and label
targets. It also exposed a settings-page 500: the local Form compatibility
package lacked the actively used `url()` method. The method and its contract are
added; all 92 production call sites use 14 methods now implemented by the
package. Only its Composer lock reference changed, and an install dry-run
confirms that a cached mirrored vendor copy will be updated on deployment.

The combined account-boundary, daily-page, Form and dashboard-theme checks pass
64 tests / 1692 assertions. A separate settings layout fixture with real CSS and
long Arabic URLs passes field/button bounds at 360/768/1024/1440px without any
further CSS changes. These are bounded checks, not approval of every dashboard
page or a substitute for the deployed-page check.

The saved instructor portrait was present in `Photo` and already reached the
course API, but dashboard views read only `users.profile_image`. The canonical
profile-image accessor now uses the studio-owned featured photo first for
teachers and retains the existing profile-image path for other roles. Teacher
list/detail/edit, studio and course API consume it with targeted eager loading;
no duplicate image write or global student-photo query was introduced. A real
UploadedFile create/replace regression verifies the stored object and the
rendered/API reads, including legacy-image fallback and empty profiles.

Deployment `d90a7ea` was observed as Deployed in Laravel Cloud. Fresh production
reads open the settings page successfully, with exactly one checkbox and one
distinct hidden fallback for each of its three repaired controls. The teacher-list screenshot
shows the uploaded portrait, and the course studio reports the instructor image
fully loaded at its actual 160px source width. A fresh coin-rule screenshot also
confirms the repaired field/action layout remains intact. No production reward
values or account credentials were changed during these acceptance reads.

That live teacher list exposed a separate count error: the published course and
its staged authoring copy were counted as two courses. Both the count and the
teacher's course list now exclude revision-course IDs from the existing
CourseAuthoringRevision ledger. The global relationship remains unchanged so
draft integrity and deletion checks still see their internal associations.
The HTTP regression checks one canonical course plus its draft as one logical
course and verifies the list excludes the copy (profile-boundary suite: 6 tests,
68 assertions). No production course or draft was deleted to correct the count.

### Daily learning boundaries after the dashboard repair — 2026-09-05

The earlier incomplete-course checkpoint above is superseded: course 3 was
published through the studio with 3 modules, 15 reels, 3 crossing projects,
teacher 7, artwork, three plans and its practical certificate template. Fresh
guest list/details reads showed it in the catalogue and as the hero course,
with 1195 seconds of content and revision 30. Deployment `9a18d2d` was observed
as Deployed, and the teacher list showed one logical course rather than counting
the staged revision as a second course. These are dashboard/API observations,
not signed-APK playback, checkout, provider callback or certificate acceptance.

Four concrete daily boundary failures were repaired in the next source batch:

- Disabling sequential lesson viewing also disabled crossing projects. The
  ordered course graph now carries the first unpassed project gate through all
  later sections, including a second lesson and an entire later module without
  a project. Project review results, not lagging derived progress rows, determine
  passage. The actual playback access service and course serializer consume
  this one state. Regression covers both directions of progress/review mismatch
  and access before/after passage (15 backend tests / 118 assertions).
- Purchase after the free sample reused the last preview reel as its resume
  destination. It now expresses continuation after that reel in the accessible
  course feed. The intent is consumed only after a successful load, so refresh
  cannot drag the learner backwards and a required project cannot be skipped.
- Android document providers may return missing, generic or inaccurate MIME
  metadata for a valid selected file. Selection, submission and draft hydration
  now share the same project-specific file admission rule. Actual byte/OOXML
  validation stays on the server; this is not a filename-based permission grant.
- The chat UI truncated a healthy accepted answer at 45 seconds or 36 probes,
  although the server advertises up to 110 seconds including queue settlement.
  It also polled earlier than a five-second server retry interval. Two failing
  runtime tests reproduced those cases before repair. Polling now obeys the
  bounded server window and retry minimum, adopts the window after a lost send
  response, and counts send time inside the same attempt. Origin outages stay
  bounded from the last reachable response. Recovery keeps the same request ID
  and never resends an accepted question or creates another charge.

Combined verification passed 16 targeted mobile suites / 125 tests, TypeScript
and scoped ESLint, plus 24 backend tests / 223 assertions spanning content
access, playback sessions, project presentation and OOXML validation.
These mobile changes are source-level repairs with targeted runtime verification.
They are not yet in a replacement signed APK and have not been accepted on a
phone against the live providers. No AI model, reward amount or credential was
changed during this batch.

### Project completion, replay and certificate recovery — 2026-09-05

A required project's latest canonical submission now supplies its progress
projection for both single-student and batch readers. Previously, a passed
submission without a progress row could unlock the next module but fail course
completion and certificate eligibility; a stale completed progress row could do
the reverse. Ten behavioral cases reproduced both failures before the repair.
Fourteen cases now cover learning summaries, scalar/batch certificate eligibility,
earned completion, student progress and dashboard learning health. Project rows
are projected in memory without a backfill or per-user query. Ordinary lesson
progress and existing earned-revision behavior are preserved. The projection
iterates actual submissions rather than every student/project combination.

A repeated project POST with the same owner, logical project, idempotency key and
body fingerprint now resolves its committed submission before mutable report
budget admission. A changed body remains a conflict. After entitlement removal,
finalizing a committed pass cannot spend on AI; an unavailable captured report is
terminal instead of remaining queued forever, and its inputs follow retention.
The initially suspected supported-plan report downgrade was ruled out and was
not changed.

Mobile project draft hydration now compares the allowed MIME contract by content,
so a course refresh cannot erase text typed before the debounce saves it. A
certificate request accepted with HTTP 202 remains pending while its read endpoint
has not observed the row; recovery retries the accepted course, and account changes
clear that local ownership. These are source changes, not an updated installed APK.

Verification: the relevant broad backend suite passed 266 tests / 2141 assertions.
The final progress-projection/dashboard check passed 37 tests / 482 assertions.
Nine targeted mobile suites passed 31 tests; TypeScript and changed-file ESLint
passed. No paid-provider request or native end-to-end acceptance was performed.

### Dashboard coin authoring and compact layouts — 2026-09-05

The deployed coin-card repair was inspected again on the real page. In this
session the viewport override did work: actual `innerWidth` was measured at
360, 768 and 1024 pixels. At 360px the page had no out-of-viewport form controls.
This is new evidence and does not retroactively turn the earlier isolated fixture
checks into live viewport checks.

Remaining defects found during that review:

- The create-rule form remained visible with no available event. It now appears
  only when an event can actually be added.
- Interval and daily-limit fields appeared for events that ignored them. Only
  effective fields are submitted; streak days, study minutes and the first-project
  cap have explicit labels. Rule names identify admin rules, not historical wallet
  transaction descriptions.
- Active positive rewards could be saved above their usable event or wallet cap.
  Authoring now rejects impossible full payouts for both event rules and earning
  tasks; intentional zero limits and inactive drafts remain available. Settings
  errors identify the offending rule/task on the relevant settings field. Shared
  settings/rule/method writes use the same lock order. Streak intervals below two
  days are rejected instead of being silently coerced by reward execution.
- Coin-use text had no length limit despite MySQL TEXT storage. The 12,000-character
  server and form limit fits even four-byte UTF-8 and produces validation feedback
  rather than a database error.
- Success, error and validation summaries were duplicated by page templates and
  the shared shell. Their shared owner is now the shell. Field-specific and
  interactive studio feedback remain in place.
- Coupon/category lists used Bootstrap 3 `col-xs-*` classes absent from Bootstrap 4.
  Their actual grid classes and wrapping actions are corrected, and the unrelated
  car-image fallback for a category is replaced by the existing category icon.

The coin fixture uses the real field-toggle script and checks disabled-field
submission isolation as well as 360/768/1024/1440px layout. A separate compact-list
fixture includes a 280px content area. Both passed; they remain isolated layout
fixtures, not claims of populated live coupon/category acceptance.

The final admin/reward/coin/wallet suite passed 285 tests / 5649 assertions.
Flash checks comprise ten static page-ownership cases, one shared-partial check
and four rendered shell cases; the coin page also has a rendered regression for
one success message, unique label targets and event-specific form fields. These
checks do not change live reward values or prove every dashboard page accepted.

Deployment attempt `824fe63` failed before traffic switched because the new
first-party reward form script was not entered in the public-asset inventory.
That omission was corrected explicitly, keeping the deployment gate intact.
Running the complete frontend production build on Windows also reproduced a
separate publisher/validator disagreement over CRLF source hashes. The source
publisher now uses the existing canonical asset-hash helper, matching the
validator and Linux output. The complete production build and all six asset-policy
tests then passed; generated distributed files have no content changes.

## Reward configuration to learner settlement — 2026-09-05

The next vertical review found and reproduced these daily failures:

- Login discovery kept a separate 60-second offer cache after the dashboard
  changed the actual registration grant. Removing that cache makes the displayed
  base, recommended-provider bonus and provider selection follow the same reader
  as registration credit. Warm-cache regressions compare each promise to the
  actual new-user ledger grant after model saves.
- A started study day lost its frozen reward contract when its rule was disabled.
  Existing daily activity now completes its contract; a disabled rule still
  cannot start another day.
- Achievement reward signals were marked handled when a temporarily full balance
  or rolling cap prevented credit. Only these reward effects now defer, without
  delaying their badge/notification siblings. Balance retries wait 12 hours;
  rolling retries wait until sufficient credits actually expire, using Cairo
  calendar arithmetic across DST. Existing receipts, zero/excluded grants and
  amounts that cannot fit their caps remain terminal, not infinite retries.
- Mobile remembered a failed daily request as the day's completed attempt and
  keyed that day in UTC. It now retries on the next foreground activation after
  failure and uses the shared Cairo day.
- Task reads confused opening WhatsApp with completed verification, while mobile
  collapsed `ready_to_claim` into `started`. Verified rewards deferred by a full
  wallet can now be claimed after spending without another linking message.
  Social destinations still open before claiming, and the coin guide still
  opens after an immediate-ready start. Failed openings retain their retry action.

New clients advertise `supports_ready_claim` on task start. The server retains
the existing token/message path for older APKs that cannot render the ready claim
action. Both paths are covered through deferred credit and one final receipt.

Verification: 122 focused backend tests / 909 assertions; five mobile suites /
43 tests; full mobile typecheck and changed-file ESLint passed. New regressions
were first observed failing before their fixes. No live prices, balances or
settings were changed, and no already-handled historical reward signals were
replayed. These checks do not substitute for an installed-APK acceptance pass.

## Checkout intent and current course access — 2026-09-05

The next daily-journey pass closed these demonstrated failures:

- Mobile checkout single-flight was keyed only by account. A second course or
  package could receive the first checkout's result without owning its return
  destination. Only identical package/provider/return intents now share a flight;
  a different intent receives a distinct checkout-in-progress result before any
  return destination is saved. Foreground recovery still follows the real flight.
- Course purchase treated any existing active enrollment as successful purchase
  of the requested tier. A different captured tier now returns
  `course_access_changed` without an order or debit. Exact completed idempotency
  receipts still win before live terms checks. Mobile closes confirmation before
  reloading ownership, so it cannot turn the conflict into a success screen.
  Late replies from an old account remain unable to mutate the current screen.
- Course details and catalogue used separate entitlement implementations, with
  repeated financial reads and ranking among enrollment candidates that cannot
  coexist under the database's unique student/course constraint. They now resolve
  the same current enrollment and captured plan in one batched path. Playback's
  learning-only check does not load AI budgets. Captured project work and earned
  certificate checks remain independent of temporary curriculum draft state.
- Dashboard paid badges/filters included approved-but-refunded or reversed
  orders. They now use the existing financially-effective predicate, and those
  orders no longer offer settlement documentation. Pending cash counts share the
  operations checkout filter: local expiry excludes abandoned attempts without
  hiding provider-authorized/processing payments that may still settle. Direct
  payment-status fields precede generic response status in filters as in the row
  reader. Provider expiry, financial closure and cancellation retain distinct
  daily behavior rather than contradictory badges and filter membership.

The last item is not a claim that every historic provider payload is normalized.
SQL filters still do not interpret transaction-only reversal arrays or malformed
status strings exactly like the gateway parser. Normal settlement/reversal writes
make financial/order state authoritative; unusual still-pending payloads and
store-only evidence require a separate demonstrated case before further changes.
Optional revision fields for older purchase clients were not made mandatory in
this pass; current mobile already sends the revision.

Verification: three non-overlapping backend groups passed 274 tests / 2190
assertions. Nine checkout/mobile suites passed 41 tests; full TypeScript and
changed-file ESLint passed. The new entitlement matrix uses production migrations
and paid-credit/debit provenance, including grant upgrades, holds, invalid receipts,
drafted courses and non-contiguous course IDs. The previously reproduced detail
query duplication is guarded by a scalar/batch query comparison. No live balance,
price, provider credentials or payment was changed and no APK was built.

The deployed coin-rules page was also reloaded and inspected at actual widths
360 and 1024px. All 58 rendered form controls stayed inside the viewport; rule
name fields measured about 249px and 301px respectively. Screenshots confirmed
contained save/delete actions. Temporary viewport overrides were reset afterward.
This confirms that page's responsive repair, not every dashboard page.

## Attribution boundary

- Original mobile developer baseline: `70d869d` in the team mobile repository.
- Original backend/dashboard baseline: `3cc0089` in the team Laravel repository.
- Product expansion and reconciliation after those baselines is treated as
  AI-assisted work, regardless of the Git author name used for publication.
- Attribution is `original`, `AI-assisted`, or `shared`. It describes where the
  defect entered, not who is personally at fault.

The original mobile baseline stored the complete session in AsyncStorage,
hard-coded provider/application values inside screens, forced first-launch
onboarding, and called several overlapping API shapes directly. The original
Laravel baseline exposed duplicate course-detail routes, shared the entire
dashboard middleware between administrators and moderators, and assembled
response contracts independently in many Resources and controllers.

## Verification snapshot

- `implemented + automated`: the current source and its regression gates pass.
- `signed-artifact verified`: the exact signed Android release passed the stated
  clean-install journey against production.
- `open`: a real production dependency or end-to-end credential journey is not
  yet green. It must not be described as complete.

At the 1.0.30 checkpoint, the signed APK passed clean guest launch, production
catalogue, course duration/map, and browser handoff to Google and TikTok. Each
handoff first completed an Android Keystore write/read/delete probe. Production
telemetry recorded no 1.0.30 session-storage failure after that run. A complete
provider callback with a real account and Facebook availability remain open.

At that checkpoint, the deployed global launch gate was red: Kashier, AI, Bunny,
mail, push, Android app links, Google and TikTok are ready, while store billing,
Apple app links/providers and Facebook are not. This accurately prevents a
test APK checkpoint from being mistaken for store-launch readiness. Those
integration states are historical, not a fresh verification on 2026-09-05.

## 1. Backend defects

| Family | Representative defects observed | Attribution | Current prevention/evidence |
|---|---|---|---|
| Response shape drift | Missing lesson duration in saved folders; missing course title; path levels/progress inconsistent between endpoints | Original | Shared Resources/services, contract feature tests, mobile/backend route-parity gate |
| Route drift | Singular/plural course routes and legacy/versioned URLs behaved as separate contracts | Original, then preserved by AI compatibility work | One route registrar serves both namespaces; parity tests prevent silent divergence |
| Guest/public boundary | Guest catalogue or course details returned auth-shaped failures | Shared | Public catalogue requests are separate from authenticated learning requests; signed-APK guest smoke |
| External media | Bunny host/key/domain state could return unusable lesson URLs | Original integration plus deployment state | Media health service, reconciliation command, launch preflight, playback-manifest contract |
| Social authentication | Provider availability, callback completion, PKCE and mobile return were not one tested transaction | Shared | Server-owned OAuth start/callback/complete flow, provider allowlist, expiry/PKCE tests, signed-APK handoff gate |
| Notifications | Title/body/global delivery and actual queue delivery were not one contract | Original | Presentation service, queued delivery, dashboard parity tests, push registration health checks |
| Commerce | Package listing, course plans, wallet contribution, Kashier callback and reconciliation were separate happy paths | Shared | Idempotent order lifecycle, signed webhooks, financial ledger/provenance, reconciliation dashboard/tests |
| Learning state | Resume course, completion, project gates, certificates and rewards could disagree | Original | Evidence/completion services, immutable wallet transactions, certificate workflow and feature tests |
| Migration/schema drift | Duplicate historical migrations and partial production schemas caused deploy-only failures | Original; AI initially added more migrations before reconciling | Frozen migration baseline, upgrade/fresh-migration tests, schema-aware transitions |
| Hidden operational failure | Integrations could fail with a generic API response and no actionable event | Shared | Privacy-safe client events, queue heartbeats, operational health and production preflight |

The family scan found one incomplete repair after this table was first written:
`SavedLessonResource` exposed `duration_minutes`, while `LessonResource` and
`ShortLessonResource` did not. Both remaining shapes now expose the field, and
one feature test proves parity for the open/full and locked/preview responses.
The live contract verifier now targets `/api/v1`, the exact namespace compiled
into the app, instead of receiving a false green from the legacy `/api` alias.

## 2. Mobile application defects

| Family | Representative defects observed | Attribution | Current prevention/evidence |
|---|---|---|---|
| Prototype boot path | Promotional onboarding blocked the first useful screen; guest mode was not the real default | Original | Clean launch enters guest home; production-entry smoke asserts it |
| Critical journey fragmentation | Home, course details and login worked in isolation but failed when chained on the signed APK | AI-assisted | `e2e:android:production-entry` installs clean and runs guest → home → course → provider handoff |
| Session persistence | Original plaintext session was unsafe; the first AI secure-store replacement failed only in Android Release | Shared, latest failure AI-assisted | Android session token now uses Rokn-owned AES-GCM Android Keystore bridge; iOS remains Keychain; native round-trip runs before OAuth |
| Error flattening | Several different native/provider failures displayed and reported one generic code | AI-assisted | Stage-specific allowlisted telemetry codes; user copy stays short while operations receives the failing stage |
| API source duplication | Screens mixed legacy actions, direct Axios calls, cached demo content and newer service façades | Shared | One normalized API base and service layer; release rejects demo/local fallback; route-parity tests |
| Auth UI/provider drift | Provider buttons disappeared, Google timed out, TikTok/browser handoff differed by device | Shared | Provider list comes from backend; browser handoff is tested per advertised provider; unavailable providers are not falsely shown |
| Guest/auth affordance drift | Duplicate sign-in CTAs and educational codes/actions appeared in guest context | Original UI plus AI reconciliation gaps | Central auth-return and course-action selectors; selector and screen contract tests |
| Product copy leakage | Developer notes, legal implementation detail, verbose/robotic dialogs and inconsistent Arabic appeared to learners | Shared | Settings/content contract tests and centralized presentation copy; further visual acceptance remains required |
| Placeholder/design leakage | Developer avatar, duplicate icons, overdesigned guest art and stale assets appeared in production UI | Original assets plus AI-generated replacement iterations | Central asset/design system and icon tests; screenshot acceptance remains required |
| Course metadata mismatch | Cards showed steps/projects instead of verified duration, rating and learners | Original presentation | Shared duration formatting and catalogue selectors; guest course smoke asserts duration |
| Release-only behavior | R8, native modules, signing, deep links and production environment were not exercised by ordinary Jest tests | AI process weakness | Android lint/unit/R8 build in CI plus signed local APK provenance and emulator smoke |

The screen-first family is now guarded mechanically: files under `src/screens`
cannot import raw AsyncStorage, SecureStore, Axios/API transport, `fetch`, or
read environment variables. Remaining local notification and demo-portfolio
state moved behind services, as did legacy chat cleanup and public URL policy.
This turns the pattern into a build failure rather than another review note.

## 3. Dashboard defects

| Family | Representative defects observed | Attribution | Current prevention/evidence |
|---|---|---|---|
| Role boundary | Original `admin` middleware granted administrators and moderators the same dashboard surface | Original | Permission matrix, separate administrator-only middleware, authorization feature tests and mutation audit log |
| Content/control parity | Course plans, benefits, projects, attachments, reel titles and learner prompts were not editable as one course model | Original | Course operations editor and dashboard parity tests; published data feeds the same mobile contract |
| Product settings sprawl | Rewards, support/social links, welcome bonus, retention copy and feature switches were scattered or hard-coded | Shared | Typed settings/product operations pages and feature-flag service |
| Financial visibility | Revenue, actual paid amount, wallet contribution, grants, channel and gateway net were not reconciled views | Original | Immutable financial provenance and reconciliation findings/dashboard |
| Cost visibility | AI/service usage per learner and plan was absent from pricing decisions | New requirement, AI-assisted implementation | AI reservations/usage events, plan budgets and operational reporting |
| Notification operations | No reliable global audience workflow or editable system-message registry | Original | Notification dashboard, delivery state, audience controls and presentation parity tests |
| UX inconsistency | Legacy Blade pages, inline styling and dense CRUD screens did not share one operating model | Original; some AI pages initially added another visual layer | Shared admin shell/components, responsive/inline-style contract tests; browser acceptance remains required |
| Silent mutations | Sensitive changes lacked an attributable audit trail | Original | Admin mutation audit middleware and audit log model/tests |

## Repeated methodological patterns

### Original mobile developer

The repeated weakness is screen-first implementation. A screen owned provider
configuration, API calls, persistence and presentation together. It can look
correct in a simulator while failing when environment, guest state, navigation
return or a release-only native module changes. The family scan therefore
targets every critical screen that still performs direct transport, storage or
environment decisions.

### Original backend/dashboard developer

The repeated weakness is additive Laravel development: a new controller,
Resource, route or migration solved the immediate request without first making
one canonical domain contract. That pattern naturally extends to duplicate
routes, response fields that differ by endpoint, migrations that assume one
historical schema, and moderator/admin access inherited from one middleware.
The family scan therefore targets all duplicated serializers/routes, all
schema-dependent migrations and every sensitive dashboard route.

### AI-assisted work

The repeated weakness is breadth before live closure. The AI added production
security, operations, payments, analytics, UI and compatibility layers at high
speed, and produced many unit/contract tests, but initially allowed mocked
boundaries to stand in for the signed APK talking to the deployed backend. It
also tended to preserve old and new paths simultaneously and sometimes exposed
implementation/legal detail or over-written copy in the learner UI.

The corrective rule is: no critical capability is complete because its files,
unit tests or endpoint pass independently. It must pass one deployed journey
with the exact signed artifact, and every generic failure must identify its
stage in operations telemetry without exposing secrets to the learner.

## Closure gates

1. Backend: fresh and upgrade migrations, full feature tests, route contract
   inventory, production launch preflight and external-integration health.
2. Mobile: release checks, signed APK provenance, clean-install guest journey,
   course details, every advertised social-provider handoff, callback/session
   persistence across process restart, playback and checkout.
3. Dashboard: browser tests as administrator and moderator, denial checks for
   every sensitive route, course publish-to-app round trip, notification
   delivery and finance/cost reconciliation.
4. Cross-system: create/edit in dashboard, observe exact public API response,
   consume it in the signed app, perform learner mutation, and verify the
   resulting dashboard/audit/financial state.
