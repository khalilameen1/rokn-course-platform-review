# Dashboard video upload

The course studio loads three first-party modules, in dependency order, through
`resources/views/admin/course-sections/partials/bunny-direct-upload.blade.php`.
Blade only provides authenticated owner/rejected-claim values and versioned asset
URLs. The `RoknCourseVideoUpload` API used by the section editor is unchanged.

## Responsibility boundaries

- `course-studio-bunny-upload-records.js` owns durable resume records and per-tab
  identity. It has no form or transport dependencies. Version 3 records remain
  scoped to owner, course, section, tab, file fingerprint and authoring revision.
  `release` drops the current handle without deleting a recoverable upload;
  `clear` discards the selected record (or this tab/section's records when there
  is no selected handle). A reload retains the TUS URL but invalidates transient
  authorization so it must be renewed. The legacy key migrates only after the
  same ownership/expiry checks.
- `course-studio-bunny-upload-transfer.js` owns one transfer: validation,
  allocation, authorization renewal, TUS POST/HEAD/PATCH, bounded retries and
  cancellation. It receives a records adapter, API request function and progress/
  claim callbacks. It never reads a DOM node or browser storage. Both a fresh
  preparation and a lost preparation response use the same allocation path.
- `course-studio-bunny-upload-form.js` owns presentation, section context and
  form submission. An allocated claim is **not** a completed upload. Only a
  successful transfer or an already-completed claim supplied by the server may
  bypass uploading on Save. The form checks cancellation before accepting the
  result and submitting. Reconciliation errors retain the existing reload gate.

## Invariants when changing this flow

Persist the allocation identity before calling the API. Retrying preparation
must reuse that identity. Never resolve a draft conflict by assigning an old
claim to a new authoring revision. Never let Cancel submit the form after an
outstanding request finishes. A vanished provider upload may restart once, not
recursively allocate without a bound. A context switch must not delete an
uncommitted resumable record; successful commit must clear it.

The form serializes transfers and resets the transfer cancellation state only
when a new attempt can actually start. A repeated click on Resume while the
cancelled attempt is still unwinding must not undo cancellation.

## Verification

- `npm run test:admin-upload` runs in Backend CI and exercises records
  and transfer without a document; the transfer tests also remove browser
  storage. This covers expiry, identity isolation, legacy migration, bounded
  restart, preparation identity ordering and cancellation before tab readiness.
- `node scripts/tests/bunny-direct-upload-recovery.browser.mjs` runs the real
  modules in headless Chrome against a local fixture and intercepted provider
  requests. It covers lost allocation responses, POST/HEAD/PATCH recovery,
  cancellation, reload/resume, completed-claim reuse, form reset and duplicated
  tab collision handling. Set `ROKN_PLAYWRIGHT_MODULE` to a Playwright ESM entry
  when Playwright is provided by the host rather than installed locally.
- Backend contracts remain in `AdminCourseSectionStateContractTest`,
  `BunnyDirectUploadLeaseTest`, `BunnyDirectUploadAuthorizationTest`,
  `BunnyUploadSafetyTest` and `TrackedAuthoringUploadRetryTest`.
- New/changed public modules must pass `node scripts/verify-public-assets.mjs`;
  review their first-party inventory hashes rather than blindly regenerating
  the entire inventory.

These local checks do not exercise live Bunny credentials or publish any course.
