# Certificate issue and recovery ownership

## Reference and reuse

- Existing `@react-navigation/native` `useFocusEffect` and React lifecycle are
  reused, not a new navigation layer. The [official async lifecycle pattern](https://reactnavigation.org/docs/use-focus-effect/)
  invalidates obsolete presentations and ignores their late updates.
- [Rocket.Chat's shipped profile screen](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/views/ProfileView/index.tsx)
  uses this same navigation hook. Its [company page](https://www.rocket.chat/company/about-us)
  reports 12 million **platform** users, not a verified mobile-install count.
- This is evidence of actual library use, not evidence that Rocket.Chat has
  Rokn's certificate workflow or these exact account guards. No Rocket.Chat
  source is copied. Certificate flight/account binding is Rokn-specific.
- A ready-made certificate implementation matching Rokn's frozen credential,
  entitlement, dashboard snapshot and QR contracts was not found. Replacing
  those existing contracts with an unrelated SDK would not reuse this reference.

## Demonstrated source gap

The existing session helper already captures its epoch before native storage
work and rejects an account switch. That protection remains unchanged; a
delayed capture is **not** assumed to return a new account's boundary.

The missing owner was the **presentation**. An issue/recovery waiting for that
capture could still POST after its screen unmounted or blurred. An old issue
or single-row recovery rejection could also alert over the next account's
screen, and issue failure could start an obsolete reconciliation read. An
already-retained callback must not recapture another account for an old UI
intent either.

## Current contract

- One issue/recovery flight per controller remains the only mutation owner.
- The owner must still be mounted, foreground, focused and on the same UI
  identity before capture and again before POST.
- Focus cleanup retires presentation ownership, not a dispatched request's
  same-account lock. Identity reset and unmount retire the controller owner.
- Refocus reads server state and keeps a dispatched issue/recovery busy until
  its result settles. A GET completed before that POST is not its receipt;
  another canonical read after the result must finish before releasing it.
- Initial/return reads gate all mutation controls. New issue also requires a
  successful canonical certificate read; explicit recovery may retry a known
  credential after a failed read has settled. Read failure never recreates a
  name-bearing issue from cached progress.
- Late error/notices and `finally` cannot modify another identity or clear its
  newer flight. A positive accepted receipt may preserve its same-account
  pending marker after blur, without reopening the old presentation.
- A POST already dispatched is **not** claimed to be cancelled or undone.
  The server's canonical credential is authoritative and is read on return.
- Accepted `202 certificate_generating` retains the existing pending marker
  while the current owner reconciles. An empty/failed read cannot reoffer a
  second issue. Explicit recovery uses the same course endpoint without
  `holder_name`; periodic refresh remains read-only.
- API reads, cache reads and mutations check a supplied session boundary
  before initial I/O. Cache-key preparation is followed by another assertion
  before reading/writing account data. Already-dispatched native storage I/O
  is not claimed to be cancellable.

## Backend and dashboard compatibility

No route, request schema, name rules, eligibility, grant, QR, certificate copy,
design or dashboard authoring contract changes in this unit.

- `CertificateController` lists the authenticated user's canonical rows and
  resolves recovery to an already-issued user/course credential.
- `CertificateService` freezes the learner name in the first issuance snapshot.
- The database retains the unique `user_id + course_id` key.
- The admin preview continues to use `CertificateIssuanceSnapshotService` and
  `CertificateArtworkRenderer`. Mobile does not author its own certificate or
  replace dashboard certificate settings.

This is a client ownership defect, not grounds for fake server/dashboard edits.

## Authored evidence — not executed

`certificateMutationOwnership.test.tsx` binds the real controller to the real
certificate API and DTO validator. Only navigation/session/native storage,
network and unrelated learning discovery are test doubles. Its capture fake
models the existing helper's pre-await epoch and rejection after a switch.

Cases cover deferred issue after screen/background/unmount departure, deferred
single/all recovery after unmount, a stale action retained across an identity
change, late mutation failures, a newer flight surviving an older failure,
server acceptance while away then canonical rehydration, accepted pending
single-flight/recovery, deferred POST plus return read plus post-response
reconciliation (issue, single recovery and all recovery), accepted-away receipt
surviving an offline return read, stale API boundaries and an account switch
during cache-key preparation. `Certificates.tsx` binds this readiness to the
actual issue button and pending/recovery controls and accessibility state.

Existing `certificateIssueRecovery`, `certificateReadOwnership`, cache,
artifact and QR cases remain part of the same final gate, not replaced by this
new suite.

Tests, typecheck, lint, builds, pushes and deployments are deliberately deferred
to the final combined gate. Source review alone cannot prove native behavior
or release readiness. The first independent review caught a return-window
duplicate POST gap. After retaining the dispatched lock through post-response
reconciliation and authoring the real binding cases above, independent review
accepted the corrected source and authored evidence with no remaining practical
blocker found in this unit. Its non-blocking empty-pending action readiness
observation was also applied without changing the template or server contract.

## Course-scoped mobile access — Udemy reference

The user approved the **access experience**, not a replacement certificate design.
[Udemy's official mobile walkthrough](https://support.udemy.com/hc/en-us/articles/4403183419671-How-to-Download-Your-Certificate-of-Completion-on-The-Mobile-App)
shows a course-level certificate entry leading to the artifact with Download
and Share actions. Its [Android listing](https://play.google.com/store/apps/details?id=com.udemy.android)
reports 10M+ downloads. This is observable shipped UX, not copied private code.

- Rokn keeps its existing course-outline certificate entry instead of adding an
  otherwise unnecessary More menu. That entry now opens `CourseCertificate`
  for the exact course rather than the entire Profile certificate collection.
- An issued certificate opens its artifact directly. A newly eligible course
  opens only the existing name confirmation; issuance still requires an
  explicit press. Udemy permits later name changes; Rokn's approved immutable
  issuance snapshot remains unchanged, so this confirmation is necessary.
- `CertificateContent` reuses the existing native download/share/QR actions,
  server artifact and approved styles for both Profile and course access.
  No competing certificate renderer, issuance owner or API is introduced.
- The existing controller reads the canonical certificate and learning lists
  and scopes its presentation, eligibility, pending polling and explicit
  recovery to the requested course. Account + course keys remount that owner
  on a different identity or course. An unrelated pending credential does not
  delay the current certificate or get recovered from its page.
- Accepted generation remains a pending state, followed by canonical GETs;
  it is never called ready just to mimic instant issuance. No push notification
  is added. A failed read offers retry without auto-POSTing a new certificate.
- The new private route retains only its validated course ID through login.
  Guest continuation returns Home, never an account-owned certificate screen.
- Backend eligibility, immutable snapshots, uniqueness and dashboard artwork
  settings continue to own the decision and artifact. No server/dashboard
  write is needed to reproduce this access flow.

`courseCertificateJourney.test.tsx` and the added login-return case are authored
for the final combined gate. No tests, build, push or deployment has run for
this access unit. Independent review of the source and nine authored journey
cases accepted this limited access flow after correcting the selector fixture
to execute the actual account/course selector and adding account replacement
during a dispatched issue. It found no remaining everyday source blocker in
this unit. This is not runtime acceptance of native saving/sharing, artwork or
the entire certificate subsystem. The local Prettier shim pointed to a missing
implementation; no dependency installation was performed. New source layout
was normalized manually instead.

## Final combined gate — 2026-10-05

The course access suite and the mutation, issue-recovery and read-ownership
suites have now run together: four suites, 38 tests passed. The command log is
`mobile/.cache/final-gate-20261005/mobile-certificate-journey-recheck.log`.

The issued-course case now advances 60 seconds and verifies that neither
certificate nor learning reads increase, no issue/recovery request is made,
and the same course artifact remains visible. Counting all React/native timers
was not a valid certificate-polling assertion. The existing controller's first
pending poll starts at three seconds, so a leaked unrelated pending course
would violate the new request-count assertions. Independent source review
accepted this correction without changing application behavior or removing
journey assertions.

This is limited automated evidence for course-scoped access and ownership,
not native download/share, backend rendering or release acceptance. The full
combined gate still has unresolved failures. No build, push or deployment was
performed for this correction.

### Login-navigation regression boundary

The separate `authNavigationCompletion.test.tsx` suite uses the actual app
Navigation registration, navigation builder/router/container, auth reducer,
authenticated-screen boundary and interrupted-return owner, while replacing
screen bodies. Its screen list had not included the new `CourseCertificate`
body, so importing its native gradient stopped the suite before any case ran.
Adding that body to the existing list changes no route or application source
and does not mock away the registered authenticated certificate route.

All three existing login-adoption cases now pass: no destination, durable
destination and route-only destination, retaining the exact course params and
no second provider tap. Logs are
`mobile/.cache/final-gate-20261005/mobile-auth-navigation-screen-boundary-reproduction.log`
and `mobile-auth-navigation-screen-boundary-final.log` in the same directory.
Independent read-only review accepted the screen boundary and retained real
navigation bindings after reading the result; it did not run tests. These three
cases cover login return to CourseDetails, not the certificate screen body or
native provider behavior. The separate certificate journey/ownership evidence
above remains the applicable certificate access evidence.

No screen, navigation, backend or dashboard behavior changed for this fixture
correction. Native navigation, saving/sharing, backend rendering and full release
acceptance are still unproven by these checks. No build or upload was performed.

### Final release lint and recovery identity — 2026-10-05

Release lint found an inner recovery-map variable shadowing the optional
course-scoped controller argument. Renaming that local variable to
`pendingCourseId` leaves the same pending IDs, account boundary, all-settled
recovery owner and canonical follow-up read unchanged. The source contract now
requires that exact map-to-service identity instead of its obsolete local name.
Independent read-only review accepted both changes without requesting a product
or backend change. The related mutation test also awaits its existing rejected
promise matcher inside an immediately started observer, before retiring or
releasing the deferred operation, then awaits that observer at the existing
completion point. Its assertion and owner-transition sequence are unchanged.

The four complete recovery, mutation, issue and profile-contract suites passed
40 cases (`mobile-certificate-recovery-variable-contract-final.log`). The earlier
full-run log `mobile-current-tree-full-tests.log` retains the obsolete-name
assertion failure; it is not passing evidence. After that correction, the full
current-tree run passed all 354 suites and 3222 cases in
`mobile-current-tree-full-tests-accepted.log`. TypeScript passed separately and
release lint reported 818 files with zero errors and zero warnings in
`mobile-current-tree-release-lint.json`. These logs are under
`mobile/.cache/final-gate-20261005`.

This closes the automated mobile gate only. Native download/share, real backend
artifact generation, dashboard acceptance and release integration remain
separate requirements. No build, push, deployment or store change was performed
for this correction.
