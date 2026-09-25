# Home dashboard read ownership

`HomeController` owns HTTP period validation and role-based view selection only.
It resolves exactly one projection after checking the role. Do not constructor-inject
the administrator projection into the controller: that would instantiate financial
report dependencies during a moderator request.

`ModeratorHomeReadService` owns the paginated content workspace. Pagination counts
canonical courses, then replaces visible cards with their active working copies.
Archived revisions never consume a slot. Publishing audits are keyed to the actual
card/editor identity, including draft IDs. Page number and URL query parameters are
explicit inputs, not ambient requests inside the read service.

`AdminHomeReadService` assembles the administrator home from the existing payment,
financial ledger, AI usage and provider invoice report owners. It does not introduce
another money calculation or combine cash with virtual coins. Period, settlement
completeness, chart and comparison semantics remain those of the existing reports.

`AdminContentInventoryReadService` supplies the shared inventory. Both home views
count logical, non-deleted courses, excluding every `revision_course_id` irrespective
of revision status. Modules, sections and lessons must belong to those courses.
Unpublished canonical courses still count as courses; only the published subtotal
requires `is_coming_soon = false`. This inventory is not the public discovery filter.

Coverage added in `AdminHomeReadOwnershipTest` exercises canonical/draft/archive
counts, deleted parents, shared projections, read-only SQL, pagination and HTTP
role/period behavior. Existing financial-isolation and report-period integration
tests remain applicable. See the repository README for local verification evidence
and limits.
