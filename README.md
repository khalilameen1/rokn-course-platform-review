# Rokn Course Platform

This is the working repository for the Rokn mobile application and its Laravel API and dashboard. The production deployment uses this repository. Production credentials, runtime data, dependency folders and mobile build outputs do not belong in source control.

## Structure

- `mobile/` — React Native / Expo application for Android and iOS.
- `backend/` — Laravel API, commerce and learning domains, operations, and the admin dashboard.

## Review status

The source is under active repair. A deployed backend or a passing test suite is not a claim that the latest APK has passed acceptance. Track source changes, deployment and device verification separately.

- Mobile and backend verification commands are listed below. Treat their current
  output and CI artifact as evidence; do not rely on a copied historical test
  count.
- Current files pass the repository secret scanners. The old source repositories are deliberately not included because their historical commits contained credentials that must be rotated independently.
- iOS remains release-blocked until `Podfile.lock` is regenerated on macOS with the pinned modern Ruby/Bundler/CocoaPods toolchain.
- Production promotion still requires staging API parity, payment reconciliation evidence, a database restore drill, and operator approval.

## Changing behavior

Start from the existing operation owner rather than adding a parallel path.
HTTP controllers own request validation, authorization context and responses;
application services own multi-step writes, locks and transaction boundaries.
Simple single-query adapters do not need extra layers just for naming symmetry.
Mobile screens compose existing domain hooks; durable drafts, payment recovery
and account-scoped storage retain their own owners and public contracts.

- Course editing and publication: [authoring input](backend/docs/course-authoring-application-input.md), [revision writes](backend/docs/course-revision-write-ownership.md), [section operations](backend/docs/course-section-application-ownership.md).
- Money and operational decisions: [checkout policy](backend/docs/course-checkout-commercial-policy.md), [settlement](backend/docs/kashier-settlement-boundaries.md), [payment review](backend/docs/urgent-tasks-and-payment-review.md).
- Dashboard changes: [course selection](backend/docs/dashboard-course-selection.md), [tracked image authoring](backend/docs/design-settings-authoring-ownership.md), [post-commit cache invalidation](backend/docs/package-authoring-and-cache-invalidation.md).
- Mobile lifecycle changes: [project drafts](mobile/docs/project-submission-boundaries.md), [saved collections](mobile/docs/saved-collections-boundaries.md), [native billing](mobile/docs/native-store-billing-ownership.md), [course cache](mobile/docs/course-cache-ownership.md).

Each boundary document identifies the regression tests and invariants to preserve.
Use behavioral tests for save/retry/rollback and stale-state transitions; a matching
source string is not proof that the operation works. Older verification notes are
historical, not acceptance evidence for the current working tree.

## Local maintenance verification

Local evidence recorded on 2026-09-25, not a deployment or device acceptance:

- Mobile: type checking and release lint passed; 307 suites / 2,440 tests passed,
  plus 93 release-script tests.
- Dashboard: all 11 browser suites passed using local fixtures and production
  assets; 15 asset-policy/upload-module tests passed.
- Backend: the complete 2,417-test run ended with 2,410 passes, one test-fixture
  error and six MySQL-only skips. The remaining fixture omitted a required course
  module. Only that test helper changed afterward; its complete five-test class
  then passed with 21 assertions. No application code changed between these runs.
- MySQL 8.0.43: clean schema migration and all 17 native contract tests passed
  with 126 assertions, including all six SQLite skips. This used an isolated
  local instance and a new test database, not a copy of production data. The
  temporary server was stopped after verification.
- PHP syntax passed for all 456 changed/new PHP files; the corrected fixture and
  final whitespace check also passed.

The local reports are `output/maintenance-backend-junit-2.xml`,
`output/maintenance-backend-bunny-final.xml` and
`output/maintenance-mysql-junit.xml`. Read them together: this was a full suite,
verification of its corrected fixture and the separate native database contract
suite, not a claimed all-green single run. Across these reports every one of the
2,417 backend test identities has a passing result. Native JSON/storage contracts
were exercised; this is not a multi-process database load test. Native store
purchases, physical-device behavior, staging parity and release builds were not
verified by this maintenance pass. Later source changes require fresh evidence.

## Local setup

### Mobile

```bash
cd mobile
npm ci
npm run verify:config
npm run typecheck
npm run lint:release
npm run test:release
```

### Backend

```bash
cd backend
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan test
```

Use only development or staging credentials. Never copy production secrets into this repository.

## Rights

Public visibility is provided for source review. No additional license or permission is granted beyond licenses explicitly included with third-party components.
