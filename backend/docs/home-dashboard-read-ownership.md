# Home dashboard read ownership

`HomeController` owns HTTP period validation and authorized destination selection
only. The default administrator URL redirects to the lightweight native dashboard;
an explicit saved period redirects to `FinancialOverview`. A content operator goes
to `ContentWorkspace`; another restricted operator goes to the native dashboard.
Do not constructor-inject the financial projection into this adapter.

`TodayStats` uses `AdminTodayReadService`, not the complete financial history report.
`FinancialOverview` alone uses `AdminHomeReadService` and native Filament charts.
Period changes remount those charts rather than retaining a previous scope.

`ModeratorHomeReadService` owns the native content workspace's projection. Pagination counts
canonical courses, then replaces visible cards with their active working copies.
Archived revisions never consume a slot. Publishing audits are keyed to the actual
row/editor identity, including draft IDs. Incomplete drafts retain priority within
the current 12-course page. The page creates a separate safe display paginator;
it does not replace the owner's model collection with arrays. Page number and URL query parameters are
explicit inputs, not ambient requests inside the read service.

`AdminHomeReadService` assembles the dedicated financial overview from the existing payment,
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
tests remain applicable. `FilamentContentWorkspaceTest` also checks native page
changes, safe display fields and the default home's independence from the heavy
financial owner. See `docs/DASHBOARD-MODERNIZATION-20261019-AR.md` at repository root
for verification evidence and limits.
