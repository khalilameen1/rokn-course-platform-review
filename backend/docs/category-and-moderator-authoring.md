# Category and content-staff authoring boundaries

`AdminCategoryAuthoringService` owns the retained public taxonomy's write and
image lifecycle. It is separate from home-row course classifications. Uploaded
bytes are staged with the shared orphan ledger; category/photo/receipt writes
commit together. Retries preserve the owned image and reject changed create
content. Updates share `CategoryEditorVersion` with the editor and preserve
gallery images when replacing the featured image. Deletes retain the existing
route contract and retire owned files through the existing model/cleanup owner.

`AdminModeratorAuthoringService` owns content-staff account writes and receipt
atomicity. It forces the moderator role at creation, rechecks role and editor
version under lock for updates, increments the profile revision and changes
credentials only with explicit `manage_credentials` intent. Email verification
is cleared only when the normalized address changes. Existing HTTP validation,
administrator authorization, MFA setup and session behavior are unchanged.

`CategoryController` and `ModeratorController` keep request validation and
responses. Small view queries remain in those adapters; no extra read wrapper
is needed for a single bounded list. `CategoryEditorVersion` and
`ModeratorEditorVersion` preserve their former field/hash contracts.

Added direct application coverage is in `AdminCategoryAuthoringOwnershipTest`
and `AdminModeratorAuthoringOwnershipTest`; existing HTTP receipt retry and
account concurrency tests remain applicable. The local verification results and
their limits are recorded in the repository README.
