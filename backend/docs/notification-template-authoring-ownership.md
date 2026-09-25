# Saved notification template authoring

`AdminNotificationTemplateAuthoringService` owns normalized template copy,
scheduling, canonical in-app links, tracked image staging, image replacement and
removal, create replay checks and transactional persistence. It takes validated
fields and an explicit upload/receipt callback, never a request or ambient actor.
Campaign delivery remains with the separate campaign/notification services.

The controller retains field and form validation, bounded listing, editor views
and redirects. `NotificationTemplateEditorVersion` preserves the shared version
contract including image ownership, text, schedule and template options.

Image staging precedes the transaction so the orphan ledger is durable before
bytes are written. Template, photo reference and receipt then commit together.
Failure uses reference-aware cleanup. Existing deterministic create/update image
identities and legacy partial-create recovery are preserved. Replay with different
copy or image is rejected. System template keys remain immutable and deletion
disables those templates; a manual announcement can still be deleted.

The added writer tests cover normalization, schedule/link conversion, image replay,
receipt rollback, stale image versions, explicit removal and system-key/deletion
behavior. Existing HTTP concurrency and form-contract tests now reference the
shared version/normalization owners. See the repository README for local
verification evidence and limits.
