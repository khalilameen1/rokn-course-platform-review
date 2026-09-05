# Rokn defect-family audit

This is a living engineering audit, not a claim that every row has already
passed production acceptance. A row is complete only when its stated evidence
exists against the deployed backend and the signed mobile artifact.

## Daily checkout, reply and project state closure — 2026-09-05

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
