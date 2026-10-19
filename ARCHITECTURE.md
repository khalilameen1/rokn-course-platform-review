# Rokn architecture: one way per concern

This is the single doctrine for the backend, the mobile app and the
dashboard. Every concern below has exactly one accepted way. A second way is
a defect, even when it works. Where the code still deviates, the count is
recorded in **Convergence** at the end and may only go down; the enforcing
tool is named next to each rule so the doctrine cannot drift silently.

Each rule names the established implementation it follows. Rokn adapts
those; it does not invent parallel mechanisms.

## Backend (Laravel 12)

### B1. HTTP layer: validate, call one service, shape the response
A dashboard controller validates input through a FormRequest (or the Filament
form schema after that screen migrates); it does not define inline validation.
Learner/provider HTTP endpoints retain their existing validation contracts.
A controller action
calls **one** application service method, and returns a response. It does
not open transactions, take locks, write financial or enrollment tables, or
catch generic exceptions to hand-build a 500.
- Reference: Laravel docs, "Controllers" and "Validation"; the thin-controller
  / service-layer convention used by Laravel's first-party apps (Forge, Vapor).
- Enforced by: `phpstan-disallowed-calls` (no `DB::transaction`,
  `lockForUpdate`, `sharedLock` in `App\Http\Controllers`).

### B2. One response envelope, one error path
Scope: the learner API under `/api/v1`. Provider-facing endpoints (Kashier,
Bunny and store webhooks, health checks, `.well-known` files) answer in the
format the provider defines. Every learner API response is `{status, success, message, data[, code, retryable],
server_time}` built by `App\Services\ApiResponseService` (`success`, `error`,
`resource`); `ApplyAPILocale` finalises it in one pass. Refusals throw a coded
exception (`CourseCheckoutRejected`, `KashierCheckoutConflict`, ...) that is
rendered into the same envelope with a stable `code`
(`App\Support\ApiErrorContract`). Clients branch on `code`, never on text. No
controller builds the envelope by hand or defines its own `error()` helper.
- Reference: Laravel exception rendering (`render()` on the exception /
  `withExceptions`), RFC 9457 problem-details idea of a stable machine code.

### B3. One writer per aggregate
Enrollments: `CourseEnrollmentWriter`. Wallet balances: `WalletService`.
Order approval: `OrderLifecycleService`. Portfolio publication:
`PortfolioModerationService`. Saved lists: `SavedLibraryService`. A new way to
change an aggregate is a new method on its owner.
- Reference: DDD aggregate root (Evans); Laravel Cashier owning subscriptions.

### B4. Locks and transactions
Order is user, then course, then other rows. Readers that need a stable
course take `sharedLock()`; only publishing takes the course exclusively.
Transactions live in services and retry deadlocks (`DB::transaction(fn, 3)`).
- Reference: MySQL 8 manual, "How to Minimize and Handle Deadlocks".

### B5. Side effects after commit go through the outbox
Work that must happen because a transaction committed (notifications,
rewards, grants, history merges) is recorded as an `InternalSignal` in the
same transaction and handled by `InternalSignalHandler` on a queue. A plain
queued job is only for self-contained heavy work (AI generation, media).
- Reference: transactional outbox pattern (microservices.io).

### B6. GET never writes
Repairs, remaps and backfills run on write paths, signals, jobs or migrations.
- Reference: RFC 9110 safe methods.

### B7. Schema changes are append-only migrations
`database/migration-lock.json` checksums every merged migration.
- Reference: Flyway checksums. Enforced by: `MigrationLockTest`.

### B9. Proven libraries over hand-written mechanisms
A protocol, algorithm or format parser that a widely adopted library
implements is used from that library (TUS: tus-js-client on both the
dashboard and the app; TOTP: pragmarx/google2fa, the engine Laravel Fortify
uses; Word: PhpWord; images: Intervention Image 4; money: Brick\Math via
`App\Support\Money`). Rokn code keeps only what is Rokn's (authorization,
account boundaries, limits, copy). A library that is end of life or that
loses data silently (PhpPresentation dropped text runs) is not "proven" and
is replaced by the spec-defined minimum, documented where it happens.

### B8. Static analysis and style
Larastan (PHPStan for Laravel) at the configured level with a baseline that may
only shrink; Laravel Pint for formatting.
- Enforced by: CI.

## Mobile (React Native)

### M1. Server state: TanStack Query
All reads of server data go through TanStack Query: one `queryClient`, one key
factory, every user-scoped key prefixed with the account scope, one persister.
No screen keeps its own copy of server data in `useState` + AsyncStorage.
- Reference: TanStack Query v5 (React Native guide, persistQueryClient).

### M2. Offline writes: one durable outbox engine
Writes that must survive app kills or offline time (progress, project
submissions, portfolio media, account writes, telemetry) are items in
`services/durableOutbox.ts` with a typed payload and a deliver function. A
feature does not build its own queue, retry clock or storage format.
Successful deliveries invalidate the related queries.
Adapters today: client events, product events, portfolio media, project
submissions, playback progress, section completion and account writes
(privacy and playback preferences, watch-history deletion). Each migrates its
previous storage format on first read.
- Reference: the outbox pattern on the client; exponential backoff with
  full jitter (AWS Architecture Blog, "Exponential Backoff And Jitter").

### M3. Layers
Network calls only in `src/services/api/*`. Device storage only in
`src/services/*` storage modules. Screens and components call hooks; hooks
call services.
- Enforced by: ESLint `no-restricted-imports` / `no-restricted-globals` /
  `no-restricted-syntax` in `mobile/.eslintrc.js` (the HTTP client and `fetch`
  only under `src/services`; AsyncStorage only under `src/services`,
  `src/constants` and `src/utils`; tests and scripts exempt).

### M4. Navigation
Root tabs and stacks are described once; flat destinations become nested
navigation only through `src/navigation/rootRoutes.ts`.
- Reference: React Navigation 7, "Nesting navigators".

### M5. Copy
Learner-facing text is Egyptian Arabic, with one vocabulary for the same
situation (a failed save always reads the same way). Learner-facing AI text
goes through `AiPromptPolicy` / `CoachReplyText` on the backend.

### M6. Account isolation
Anything stored or cached for a learner is keyed by the account scope and
removed on logout or account switch; a response that lands after an account
switch is discarded (`assertAccountSessionBoundary`).

## Dashboard

### D1. Plain modules on one registry
Course studio code is plain browser modules under `public/admin/assets/js`
registered through `core.provide`; third-party code is published by
`scripts/publish-admin-vendor-assets.mjs` and pinned in the public asset
inventory. Uploads use tus-js-client.

### D2. Incremental Filament migration, shared application services
Existing Blade screens use FormRequests. A migrated Filament screen uses its
form schema for HTTP-facing input rules and calls the existing owning service
for writes; services retain business invariants for every entry point. Do not
move wallet, order or enrollment writes into resource callbacks. Delete the
old screen and its routes only when its replacement has passed equivalent
behavior checks. Do not migrate the course studio as a generic CRUD form.
Teacher reads live in
`AdminTeacherReadService`, writes in `AdminTeacherAuthoringService`, and input
rules in the active Filament schema. `TeacherIndexRequest`,
`TeacherProfileRequest`, `TeacherStatusRequest` remain only for retained classic
HTTP compatibility, not a second editor. Old teacher/path Blade forms have been
removed and their saved GET URLs redirect to the native Filament editors.
Paths follow the same boundary with `AdminPathReadService`,
`AdminPathAuthoringService`, `PathIndexRequest` and `PathProfileRequest`.
Home rows follow `AdminClassificationReadService` / `AdminClassificationAuthoringService`.
Badges follow `AdminLevelReadService` / `AdminLevelAuthoringService` and retain
the approved artwork. Current-badge preview and replacement upload are distinct;
a form cannot turn an arbitrary submitted storage path into a badge reference.
`UsesAuthoringCreateReceipt` adapts the existing durable creation ledger for native
Record pages; it does not introduce a second receipt store or own domain writes.
`FormValidation` maps domain field names to Filament's `data.*` state so a rejected
write has a visible field error. Media services stage tracked bytes outside their
own transaction; do not enable a generic enclosing Filament transaction around them.
- Reused implementation: Laravel FormRequest and Eloquent eager loading, the
  same service-layer boundaries already used by `AdminStudentReadService`.
- Enforced by: Larastan disallowed database calls in the converged teacher and
  path controllers; expand that rule's coverage as other controllers converge.

### D3. Rendered lists must declare their relations
`Tests\TestCase` enables Laravel's `Model::preventLazyLoading()` for every
application test. Multi-row page fixtures must render without deferred relation
queries. Explicit per-row query methods (for example `relation()->count()`)
also need bounded-query checks: Laravel's relation guard does not intercept
those. Do not replace the guard with logging or disable it for a failing page.

## Tests
Behaviour through HTTP, services, hooks or rendered output. No new tests that
read source text (`SourceTextTestRatchetTest`).

## Convergence
Counts of code that still follows a second way. Each may only go down.

| Rule | Deviation | 2026-10-18 start | Now |
|---|---|---|---|
| B1 | Controllers opening transactions or locks | 21 | 0 |
| B2 | Learner API controllers building the envelope by hand | 36 | 0 |
| B2 | Controllers with their own `error()` helper | 6 | 0 |
| B2 | Controllers hand-building a 500 | 5 | 0 |
| B6 | GET endpoints that write | 7 | 2 (public portfolio page and admin preview call `PortfolioModerationService::reconcile`) |
| B8 | Larastan baseline entries | 1550 | 1156 |
| B8 | Eloquent relations without a return type | 205 | 0 |
| M1 | Read domains outside TanStack Query | 8 | 0 |
| M2 | Feature-owned offline queues outside `durableOutbox` | 5 | 0 |
| M3 | Files calling HTTP outside `services/` | 8 | 0 |
| M3 | Files touching AsyncStorage outside `services/`, `constants/`, `utils/` | 11 | 0 |
| M5 | Standard-Arabic retry copy ("حاول مرة أخرى") in the app | 132 | 3 (the app's own "سنحاول مرة أخرى" notice) |
| Tests | Backend test files that read source text | 29 | 0 |
| B9 | Hand-written implementations of a protocol or algorithm a proven library provides (TUS upload in the app, TOTP/Base32, OOXML text extraction) | 3 | 0 |
| B9 | EGP arithmetic outside `App\Support\Money` (float round/compare sites) | 31 | 0 |
| B9 | Libraries past end of life (Intervention Image 2) | 1 | 0 |
| D2 | Dashboard controllers with inline request validation | 33 | 16 |

Dashboard JSON endpoints (course studio, uploads) answer `public/admin/assets/js/request.js`'s
own contract and are outside B2's learner-API scope.
