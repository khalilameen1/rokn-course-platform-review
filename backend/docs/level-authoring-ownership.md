# Badge level authoring

`AdminLevelAuthoringService` owns level writes and badge-image references.
`LevelController` retains field/file validation, views and create-receipt HTTP
binding. `LevelEditorVersion` is shared by the editor and locked update check;
its fields and hash ordering preserve existing editor tokens.

Uploaded bytes are staged with the existing tracked-upload ledger before opening
the write transaction. The level, featured image and creation receipt commit
together. Resuming an existing creation identity keeps its featured image rather
than adding another one. Unused staging attempts remain tracked for cleanup.

Replacing a legacy `badge_image` clears that field, attaches the new Photo and
records old-image retirement in the same transaction. Deleting an unused level
also retires its legacy image. Levels linked to courses or earned student badges
cannot be deleted. External URLs and bundled assets are not local cleanup targets.

`StoredFileReferenceService` recognizes legacy level badge references (including
leading-slash paths and public URLs). Replacing one level cannot retire a shared
image still owned by another. Physical byte deletion stays with the cleanup worker.

The ownership tests cover receipt rollback/retry, one featured image on replay,
stale edits, text-only updates, shared legacy images, course/student deletion guards,
transaction rollback and external/bundled assets. See the repository README for
local verification evidence and limits.
