# Rokn defect-family audit

This is a living engineering audit, not a claim that every row has already
passed production acceptance. A row is complete only when its stated evidence
exists against the deployed backend and the signed mobile artifact.

## Current acceptance boundary — 2026-09-05

The latest user-installed APK failed Arabic alignment and course assistant
entry-point acceptance. Earlier checkpoints below are historical evidence,
not approval of the current app. No replacement APK has been built for this pass.

Current source work covers partial authoring updates, one course-details/map
snapshot, visible versus usable chat actions, Arabic direction, payment review
recovery and the shared dashboard runtime. Browser shell checks cover navigation,
storage denial, AJAX/native saves and responsive widths, not complete page QA.

Authentication, project submission, portfolio/certificate journeys and remaining
dashboard page implementations are still being reviewed. No whole-project
completion or zero-defect claim follows from these individual repairs.

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

The deployed global launch gate is also intentionally red: Kashier, AI, Bunny,
mail, push, Android app links, Google and TikTok are ready, while store billing,
Apple app links/providers and Facebook are not. This accurately prevents a
test APK checkpoint from being mistaken for store-launch readiness.

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
