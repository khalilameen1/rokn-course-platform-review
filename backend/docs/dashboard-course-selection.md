# Logical course selection in dashboard authoring

Selectors for coupon and grant creation, path membership and grade course lists
use `AdminContentInventoryReadService::courses()`. A hidden original course is
still an authoring target; a revision working copy or publication archive is
not. This distinction must not be replaced by `visibleInCatalog()` or a blanket
global scope: historical enrollment and authoring-revision reads need their
actual stored identities.

`AdminCourseCodeReadService::courseOptions()` serves the grant controller's
course selectors. Historical code lists, usage, exports and lesson history keep
their original target records, rather than silently rewriting them to new IDs.

`AdminCourseCodeAuthoringService` checks and locks canonical targets for new
batches and edits that activate or change a target. Grant updates follow the
redemption lock order: code before course. Bulk activation locks selected code
rows first and eligible course rows in ID order, and skips obsolete partial or
non-canonical targets. Deactivation and history-preserving deletion remain
available for old records. Validation errors from batch creation now reach the
form instead of being collapsed into a generic failure.

Coupon authoring uses the same inventory rule, with its own transaction and
image lifecycle documented in `coupon-authoring-ownership.md`.

Added regression cases cover unpublished originals, working copies, archives,
deleted targets, invalid-target updates, bulk activation and legacy deactivation.
See the repository README for local verification evidence and limits.
# Course-code form cleanup

Create/edit forms only author whole-course access, matching `CourseCodeRequest`.
The course selector is visible and required without JavaScript. Legacy lesson and
multi-lesson inputs, their AJAX/reset logic, and full lesson-table loads have been
removed. Validation redisplays the chosen course through Blade `old()` values;
no page-load script clears it. Historical code types remain readable in list,
detail and export screens, and the existing lesson lookup endpoint is unchanged.
