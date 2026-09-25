# Course authoring application input

`CourseRequest` remains the HTTP validation/authorization boundary. Its existing
normalization and role-dependent editable plan filtering run before
`authoringEdit()` creates a `Data/CourseAuthoringEdit` snapshot. The data factory
accepts validated fields only; it does not replace validation or grant permissions.

The snapshot separates general course attributes from operations: plan offers,
classification and teacher selection, visibility, hero selection, publication,
existing-enrollment attachment grants, upload and expected version. A missing
selection is null and must not clear existing relations. An empty submitted list
or a true `*_present` marker means clear. Explicit nullable visibility is false,
not omitted. Explicit null offers become an empty submitted offer set, so the
plan owner rejects the incomplete set rather than silently ignoring it.

`AdminCourseAuthoringService` consumes this snapshot without a Request, controller,
route helper or HTTP receipt adapter dependency. It retains course-specific save,
publication and compensation orchestration. The shared `lockExpected` contract
checks the submitted version under the established authoring locks; conflicts
remain validation errors with status 409. CourseRequest/DTO fields do not bypass
the separate administrator and home-curation capabilities passed by the caller.

The controller supplies the creation receipt callback, following the existing
module application pattern. The application invokes it inside the course/plan/
relation transaction, including the already-created retry path. Redirect encoding
and destination URLs stay with the controller. A receipt failure during creation
rolls back the new course and its plans instead of reporting a saved object without
a replayable receipt. The callback must propagate failure, not catch and hide it.

Image upload still happens before the database write through the tracked upload
owner. Failed saves compensate through the file deletion owner. Publication, catalog
readiness, committed editor versions and recoverable staged-publish responses keep
their existing ownership and sequence. No second creation endpoint or fallback
implementation is introduced.

## Verification

`CourseAuthoringEditTest` tests field presence, explicit clear/false/null,
separation of operation controls, and snapshot independence from later input
mutation. `CourseAuthoringApplicationOwnershipTest` tests request-free creation,
callback transaction scope, duplicate-intent replay, callback failure rollback,
partial relation edits, capability checks and stale versions. It also fails real
HTTP creation receipt storage with a SQLite trigger, then retries the same intent
through the real middleware/controller/receipt path and checks that only one
course and one three-plan set exist.

Existing image-replacement transaction, course JSON response, studio publish
recovery, plan editor, classification merge and hero concurrency tests cover the
surrounding contracts. Local SQLite tests and fake external services are not live
server, device or native MySQL verification.

The creation tests remove only the retired SQLite `courses.tenant_id` column in
their isolated test database. The historical migration already removes it on
MySQL but skips SQLite table rebuilding. No production column, model observer,
fillable permission or application fallback is changed to accommodate that
legacy test-schema difference. Failure tests assert the injected receipt error
was actually reached before verifying rollback and retry.

This is one boundary in the repository-wide maintenance effort, not evidence that
the entire repository is finished. Shared authoring HTTP adapters, other controller
orchestrators and remaining client-side ownership still require current-state
assessment before a whole-project completion claim.
