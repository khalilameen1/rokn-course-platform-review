# Home-row curation

`AdminClassificationReadService` owns row listings, visible canonical course
choices, current memberships and their editor version. The version still includes
hidden canonical membership but excludes working-copy/archive snapshots. Reading
choices or editor data never resolves the writer or changes the catalogue.

`AdminClassificationAuthoringService` owns creation, updates and deletion. It
accepts validated row fields, explicit selections and an editor version without
an HTTP request. The controller retains field validation, normalized booleans,
views, redirects and the create-intent receipt callback.

Writes retain the existing publication-compatible lock order: all affected
course IDs in sorted order, then the classification row. Revision-only deletion
also locks the canonical parents. Updates reject stale membership; deletion
rejects a membership added outside the locked set. Creation and update both
recheck selectable-course eligibility after course locks are acquired.

Only the visible canonical subset is synchronized. Hidden taxonomy memberships
and authoring snapshots are preserved. A hidden canonical course still prevents
deletion; revision snapshots alone do not trap an otherwise empty row. Existing
model-owned catalogue invalidation and course-publication three-way merge are
unchanged. Creation and its receipt share a transaction.

`AdminClassificationOwnershipTest` covers read isolation, receipt and outer
rollback, selection eligibility, stale membership, snapshot preservation and
deletion rules independently of HTTP. `AdminHomeCurationTest` and
`CourseStagedClassificationMergeTest` retain the route, cache and publish-merge
coverage. See the repository README for local verification evidence and limits.
