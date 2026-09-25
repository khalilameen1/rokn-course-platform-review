# Student administration writes

`AdminStudentAuthoringService` owns profile creation and edits. Learners stay
social-only: creating a database row does not verify its email or introduce a
password login. Edits reload a locked student row, check its profile version,
advance `profile_revision` and clear email verification only when email changes.
Staff accounts are not student targets. HTTP authorization and field validation
remain in the request/controller.

Creation uses the established tracked-upload owner before starting the database
transaction. Profile, featured-photo ownership and the create receipt then commit
together. A callback failure no longer leaves a newly created profile checkpoint
behind. Existing rows from the previous checkpointed flow are still found by
`authoring_request_id` and are not overwritten on retry. Stable image operation
identities remain unchanged; cleanup is delegated to the reference-aware file
owner, not performed through direct filesystem deletion.

`AdminStudentNoteService` owns note creation and deletion, with an explicit actor
and same-transaction receipt. Deletion checks the current locked note's author or
the caller's authorized administrator role. No service reads ambient authentication.

`StudentAccountStateService` now owns device reset as well as account activation.
Reset uses the same settings-before-user lock order as settings authoring, checks
the current permanent-device policy and device editor version, and revokes API
and push credentials with the device lock/profile revision in one transaction.
`StudentEditorVersion` supplies shared profile/device versions to readers and
writers. Existing activation-version semantics are unchanged.

The profile/image/receipt, note-permission and device-reset ownership tests run
against disposable test databases only. See the repository README for local
verification evidence. No learner, device, note, server or production file was
changed by this work.
