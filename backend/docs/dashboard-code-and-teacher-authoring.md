# Course-code and teacher authoring

The dashboard controllers retain HTTP authorization, validation, normalized input,
read/presentation queries and redirects. They no longer own database write
transactions for these entities. This is not a generic CRUD framework: each
authoring service owns the invariants of its own records.

## Course codes

`AdminCourseCodeAuthoringService` owns batch creation, editing, removal and bulk
state changes. The batch-completion callback executes once with the first created
code inside the same transaction; failure rolls back every code and receipt write.
The callback is supplied by the HTTP adapter, so the writer does not depend on the
request or the redirect-receipt service.

`CourseCodeEditorVersion` is the single field/hash contract shared by dashboard
reads and locked writes. Updates and deletions reload the current row; bulk
commands lock in ID order and validate every selected version before mutation.

Individual and bulk deletion share one rule: retain a code with usage or order
history and deactivate it instead. Unused codes can be deleted. Historical
partial-lesson codes cannot be reactivated through bulk activation. Existing model
guards against changing a redeemed entitlement contract remain in force.

Redemption, entitlement grants and financial receipts stay with their existing
owners, not this authoring service.

`AdminCourseCodeReadService` owns the shared selection for the index and exports.
It accepts scalar filters with local calendar dates, not a request. CSV data is a
generator that streams 500-row chunks through `CsvCell` formula protection. PDF
data is bounded to 501 rows so the HTTP adapter can reject results over 500 before
rendering. CSV byte output, HTTP headers, PDF rendering and redirects remain in
the controller. Reading does not create, deactivate or redeem codes.

Historical lesson-code PDF rows and AJAX lesson options use the existing localized
lesson title accessor instead of nonexistent `name_ar` or only the legacy `title`
field. Multi-lesson PDF cards retain their compact generic label.

## Teachers

`AdminTeacherAuthoringService` owns profile persistence, image staging/ownership,
credential hashing, assigned-course deletion protection and the shared published
instructor rule used by editing and toggling activity. It takes validated profile
fields and authorized credential fields, not an HTTP request. The controller's
permission matrix and credential-validation rules remain the authorization gate.

Creation retains the authoring request identity and completes its receipt inside
the profile transaction. Updates preserve credentials absent from the payload.
Image writes remain tracked before persistence; failed saves queue reference-aware
cleanup, and replacement deletes old Photo rows inside the transaction so their
cleanup can roll back with the profile. No new synchronous storage deletion path
is introduced.

`TeacherEditorVersion` replaces the duplicated hash in the controller and Blade
view. The editor receives the version as data, including the featured image path.

## Verification scope

New direct-owner tests cover batch/receipt rollback, stale editor rejection,
single/bulk code history retention, teacher credential preservation and activity
rules. Existing route tests cover moderator credential permissions, portrait
replacement and retried authoring requests. See the repository README for local
verification evidence and its limits.

`AdminCourseCodeReadOwnershipTest` adds shared selection, business-day boundaries,
CSV chunk/escaping, bounded PDF and localized-title coverage. Course-code tests use
`ProductionCourseCodeSchema` for the existing in-memory SQLite retired-column
bridge, matching the columns already removed by production MySQL migrations.
