# Rokn defect-family audit

This is a living engineering audit, not a claim that every row has already
passed production acceptance. A row is complete only when its stated evidence
exists against the deployed backend and the signed mobile artifact.

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
An isolated CSS layout fixture covers 360/768/1024/1440px. The deployed page was
also inspected at those four widths. At 1024px the rule inputs are approximately
301px wide instead of 70px; save/delete actions remain within each card without
overlap or clipping. Mobile and wider layouts have no horizontal page overflow.
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
