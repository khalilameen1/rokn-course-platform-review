# Student avatar consistency between dashboard and mobile

The local student list and student profile card now read User::profile_image_url, the same accessor returned by StudentProfileResource to the mobile app. Previously both dashboard views used HasPhoto::getImageAttribute, which could prefer a historical featured photo or construct a local asset URL instead of the configured public disk URL. A current learner upload or social avatar could therefore differ from the dashboard image.

## Reference and reuse

[Rocket Chat AvatarContainer](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/containers/Avatar/AvatarContainer.tsx) supplies shared user and server information to [Avatar](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/containers/Avatar/Avatar.tsx), which uses one getAvatarURL resolver. AvatarContainer, Avatar and useAvatarETag were read before this change. [Rocket Chat reports over 12 million platform users](https://www.rocket.chat/company/about-us), not a mobile installation count.

This change follows the shared resolution principle. No Rocket Chat code, cache invalidation system, database or avatar protocol was copied. The actual reuse is Rokn's existing canonical User accessor and PublicDiskUrl through Laravel Blade. There was no need to write another resolver or introduce a library for two view bindings.

## Scope and contracts

Only the avatar bindings in admin/users/index.blade.php and admin/users/partials/show/profile.blade.php changed. Their existing placeholder, alternative text, CSS classes and escaping remain intact. Neither view can override the current learner profile image with a historical featured relation.

ProfileController, StudentProfileResource, mobile profile parsing, upload validation, private file preparation, revision and receipt handling, storage and cleanup remain unchanged. Teacher portraits, generic HasPhoto consumers and admin authoring commands were not rewritten. Photo-only legacy authoring records are not migrated by this read-side change; the canonical learner policy is still the API's existing policy. This is not a claim that every image writer or legacy record in the project has been audited.

## Independent review and final gate

Independent review accepted the source and authored cases within this consumer scope. It confirmed that the student read models contain the required User columns and that the two views consume the same accessor as the API.

StudentAvatarPresentationParityTest exercises the actual profile API and dashboard routes, not mocked URL resolution. Authored cases cover a real learner raster replacement while a historical featured relation remains, social HTTPS URLs with escaped query parameters, configured public disk CDN URLs with raw and storage-prefixed keys, and placeholders for absent or insecure canonical images. Image assertions target the student image classes so a dashboard header image cannot satisfy them accidentally.

These tests have not been executed. Native device behavior, rendered dashboard verification, tests, lint, build, commit, push and deployment remain deferred to the combined final gate. No database migration or external upload was performed for this unit, and the overall goal remains open.

## Final combined gate update — 2026-10-05

The avatar feature suite and PublicDiskUrlTest have now run together: seven
cases, six passed and one GD environment error. The log is
`mobile/.cache/final-gate-20261005/backend-avatar-cdn-fixture-recheck.log`.
Configured CDN, legacy storage prefixes, HTTPS social URLs and placeholders
are checked through the real API and both dashboard views; the public URL
resolver's existing cases also pass. Uploaded-image replacement stops at
Laravel's fake-image preparation because GD is unavailable, before the actual
replacement request. That flow is not accepted by these results.

Laravel's Storage fake copies only `throw` from the configured disk and does
not inherit its `url`. The test now passes the already-configured CDN URL into
the existing fake; production URL resolution and the fixed expected URL are
unchanged. Independent source review accepted this fixture correction and its
limited six-case evidence. No build, push, deployment or external upload ran,
and the broader combined gate remains incomplete.

## Current fixture acceptance — 2026-10-05

GD now loads in the pinned PHP 8.4.24 runtime. The full backend run completed
2529 cases with one failure: this test's RefreshDatabase outer transaction
conflicted with the real pre-write durable cleanup admission. The production
ProfileController and StoredFileDeletionService were not changed or bypassed.

The final fixture reuses the existing tracked image-upload pattern in
AdminCourseImageReplacementTransactionTest, TeacherPortraitUploadTest and
AccountUploadedContentErasureTest: Laravel's real `migrate:fresh` in the isolated
testing `:memory:` database, without a test-owned outer transaction. It asserts
that transaction level is zero before the profile replacement API call. Original
CDN, legacy-photo, unsafe-image and both dashboard route assertions remain.
The actual feature file passed four cases/99 assertions in
`mobile/.cache/final-gate-20261005/backend-avatar-parity-fixture-final.log`
and its matching JUnit. Independent read-only review accepted this fixture unit.

An intermediate DatabaseMigrations trial passed the API assertions but exposed
two indexed-column migration rollback defects during teardown. The failed
`backend-avatar-parity-accepted.log` and `backend-avatar-parity-roundtrip.log`
are retained; their names do not make them accepted runs. The actual migration
owners 79 (notification scheduling) and 72 (publication revision) now remove
their own indexes before dropping the columns using Laravel Schema. Their `up`
paths are unchanged. MigrationRollbackIndexTest executes both real owners
through two up/down cycles, retaining unrelated columns; it passed two cases/23
assertions in `backend-publication-notification-migration-roundtrip.log`.
Each correction received independent bounded review. This is not acceptance of
every historical migration rollback or a MySQL run. No native device, live CDN,
cleanup worker, new full backend pass, build or deployment is implied.
