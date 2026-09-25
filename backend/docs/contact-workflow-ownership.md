# Contact and verified account-deletion requests

`AdminContactWorkflowService` owns read/processing/closure state changes, protected
audit-record deletion rules and orchestration of a verified account deletion.
Every mutation reloads a locked contact and compares `ContactEditorVersion`.
Audit actors are explicit parameters, not ambient authentication.

`ContactAccountLookupService` supplies the same normalized email lookup to the
read-only detail page and workflow guards. A matching email is not identity proof.
The HTTP adapter retains administrator authorization through the existing routes,
required verification-note and explicit identity/deletion confirmation validation,
plus the existing success/error presentation. No generic student-delete endpoint
is added.

Only processing, unresolved account-deletion requests can be fulfilled. Confirmation
email must match a student account. Closure claiming self-service completion or no
account is rejected while a matching account exists. Typed and legacy-marker
deletion requests remain undeletable audit records.

The existing `AccountDeletionService` remains the sole erasure/outbox owner.
Its database changes and the contact resolution record share the enclosing
transaction; a resolution failure must roll back both. Cleanup-pending status is
recorded and returned rather than claiming all storage deletion has finished.
No provider cleanup or filesystem removal is reimplemented in the workflow.

Added tests cover explicit audit actors, stale versions, protected records,
non-destructive closure, confirmation mismatch, real disposable-account erasure,
pending cleanup, audit-write rollback and required HTTP confirmations. Local
verification and its limits are recorded in the repository README. No production
deletion was performed.
# Catalogue publication timing

Account erasure invalidates course rating/enrollment projections through an
after-commit callback registered inside its transaction. If a contact workflow
owns an outer transaction, its audit write must commit before the new catalogue
generation is visible. Rolling back the contact resolution also discards the
invalidation callback. The workflow tests include a rating-bearing account for
audit failure and an explicit outer commit; no cache rotation may precede it.
