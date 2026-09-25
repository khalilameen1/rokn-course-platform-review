# Dashboard learner preview ownership

AdminCoursePreviewService prepares read-only course data: existing draft
resolution, available plans/grant option, selected plan, certificate wording and
the canonical published device-link identity. It takes a Course and optional
plan code, not an HTTP request or a synthetic learner. It neither allocates a
draft nor creates enrollment, progress, payment or certificate records.

CourseController owns request validation, staff actor, HTTP errors, view and
cache headers. After preparation succeeds, it asks CoursePresentationService to
build the existing learner resource and resolves that resource with the current
request. The preview uses the same resource contract as before; no parallel
dashboard-only copy of the learner payload was introduced. The selected grant
remains watch-only and does not redeem or consume its course code.

The screen can render the saved working draft while the device link still points
to the canonical published course. Unpublished courses have no device link. Read
preparation and HTTP rendering remain separate so metadata/error handling does
not need to construct a browser request or resolve presentation dependencies.
Route authorization and MFA remain the HTTP boundary's responsibility.

AdminCoursePreviewOwnershipTest checks read-only SQL preparation without resource
presentation, real preview routes, canonical/draft identity, grant capabilities,
invalid plan rejection, staff/MFA guards and absence of learning writes.
WatchOnlyCourseLearningTest retains grant resource parity coverage. These local
tests do not imply a deployment or device preview verification.
