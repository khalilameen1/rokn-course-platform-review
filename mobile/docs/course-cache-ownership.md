# Catalogue and course-details cache owners

`courseCatalogueCache` owns the public catalogue snapshot, its four-page bound,
two-hour freshness policy, revision/generation fence and invalidation. It uses
one shared-queue key for that public snapshot. Reads wait for the captured pending
write tail before inspecting disk, preserving the previous invalidation ordering.
Account-specific ownership/progress is still stripped from catalogue storage.

`courseDetailsCache` owns each account's version-5 details snapshots and bounded
eight-course index. Save, touch and removal share the queue key of that account's
base storage key, not the individual course ID: otherwise concurrent courses
could overwrite their common index. Unrelated accounts do not share a queue.
The existing record format, one-day freshness policy, optional guest key resolution,
eviction behavior and best-effort read parser are retained.

Both owners use separate instances of `createKeyedAsyncQueue`. They do not import
each other. `courseDetails` coordinates confirmed unavailability with public
catalogue invalidation; that application flow remains above the storage owners.
Callers import the appropriate module directly. The former combined `courseCache`
module is removed rather than retained as a forwarding facade.

This does not permit offline playback or restore authenticated entitlements from
cache. `getCourseDetailsSnapshot` still checks the captured session, uses a fresh
server response for authenticated learners, and permits cached details only for
guest display recovery. Native writes keep their original captured storage key.

`courseDetailsCacheOwnership.test.ts` reproduces the old cross-account queue stall
and checks same-account ordering, the eight-course limit, isolation of eviction,
failed-write recovery and existing persisted compatibility. Catalogue unavailable-
recovery and guest/session tests exercise the real application consumers. No
storage format migration, native build, live-server change or store upload occurs.
