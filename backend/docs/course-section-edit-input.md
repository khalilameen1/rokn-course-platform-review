# Course section edit input

`Http/Requests/Admin/CourseSectionInput` owns HTTP normalization, validation and
construction of `Data/CourseSectionEdit`. The edit is a readonly snapshot, not a
reference to the request. The validator keeps the established partial-title/type/
module fallback and rejects unsupported delivery types before any media upload.

The expected authoring version is part of that immutable edit. After media staging,
the controller calls the same request-free lockExpected operation used by other
course editors. Delete/reorder pass their validated version and section list explicitly.
There is no separate request-reading concurrency method. Missing/invalid versions
remain validation errors; stale versions remain conflicts before content mutation.

The snapshot separates effective section position/title from optional content
changes. An omitted patch key means keep the existing attribute. An explicit
null clears a nullable attribute and explicit false disables a boolean. Creation
defaults belong to `CourseSectionContentService`; they must not be applied to
omitted fields in an update. Patch keys are whitelisted and cannot set ownership,
provider settings or unrelated project policy.

Empty order means append for creation and preserve the current locked position
for update. Do not snapshot the old section order before acquiring the course
lock: a sibling insert/delete may have changed it. Previously nullable validation
allowed a null to reach the non-null database order column; the HTTP regression
test demonstrates that failure and verifies the corrected behavior.

`CourseSectionContentService` receives the edit, course, optional existing section
and `CourseSectionMediaStage`. It no longer receives a request or unused ordering
arguments. It owns lesson/project persistence and media-generation reset, not
form parsing, authorization, outline ordering or HTTP responses.

`CourseSectionMediaService` receives the same validated edit and an explicit
authenticated actor. A video claim in the edit is still untrusted until verified
against actor, course, section and upload lease. Staging does not consume the
claim; attachment remains inside the existing section transaction. The stage
captures previous video/thumbnail paths at construction, so a later mutation of
the lesson model cannot redirect cleanup to its replacement media.

`CourseSectionController` remains the HTTP transaction coordinator: acquire the
authoring lock, attach media, save content/section, order the outline, persist the
create receipt, advance the version, commit and then request media probing. The
existing rollback/cleanup, concurrency checks and response contracts are retained.
This does not introduce a second save endpoint or compatibility forwarding service.

## Evidence

`CourseSectionEditInputTest` covers real HTTP partial edits, nullable ordering,
explicit null/false, project submission policy, a detached validated snapshot,
request-free content editing and actor-bound media staging. External video/storage
operations are substituted only in the staging test; upload claims and database
leases remain real. `CourseSectionMediaStageTest` checks previous-media snapshot
semantics. Existing atomicity, studio ordering, authoring receipt and upload-lease
tests continue to cover surrounding contracts.

This change is local. Tests do not prove device behavior or a live provider upload.
It also does not remove request coupling from other dashboard application services;
that remains a separate part of the repository-wide maintenance work.
