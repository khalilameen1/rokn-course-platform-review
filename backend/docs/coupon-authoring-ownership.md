# Coupon authoring and canonical course selection

`AdminContentInventoryReadService::courses()` defines the dashboard's logical
course inventory. It includes unpublished/unlisted original courses and excludes
soft-deleted courses, revision working copies and retained publication archives.
Coupon create/edit choices and the grade course list use this same query. This
is not the public discovery filter: administrators can prepare an offer before
publishing a course.

`AdminCouponAuthoringService` owns coupon mutations. It receives validated fields,
an optional uploaded image, explicit editor/request identities, and a creation
receipt callback. It does not resolve an HTTP request, controller or receipt
adapter. `CouponController` owns form validation, view/redirect selection and
mapping domain errors back to the form. `CouponEditorVersion` is shared by the
read form and locked writes, including the featured image path.

Creation stages immutable image bytes with the existing orphan ledger, then
locks the selected canonical course and commits the coupon, photo reference and
receipt together. A failed receipt no longer leaves a partially created campaign.
The storage worker remains the sole owner of physical deletion. Old interrupted
creates that already committed a coupon/photo can still finish with the same
intent and image; changed or deleted intents cannot redefine or restore them.

Updates lock the selected course before the coupon, recheck its editor version,
and retire replaced images within the same write transaction. An invalid legacy
course reference may be retained while disabling a campaign, but cannot be
newly selected or activated. The form shows that retained invalid reference
explicitly rather than silently converting it to an all-course campaign. The
existing model protections for redeemed campaign terms remain in effect.

Regression coverage was added in `AdminCouponAuthoringOwnershipTest` and the
HTTP receipt-failure/legacy-resume cases in `TrackedAuthoringUploadRetryTest`.
Local verification results and their limits are recorded in the repository README.
