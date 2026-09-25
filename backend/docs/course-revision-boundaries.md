# Course revision ownership

## Read-side identities

`CourseRevisionResolver` resolves the canonical course, an existing draft,
explicit draft presentation choices, retained archives and published entity
aliases. It performs reads only and has no dependency on publishing/authoring
services. Reading a course must not call `draftFor` to discover its working copy.

`CourseRevisionLearnerReadService` projects persisted learner facts through those
aliases. It reads progress, watch evidence and submissions; it does not create
completion or mutate the published curriculum.

Use `currentEntityId` for surviving published identity mappings. Use
`currentLearnerEntityMap` / `equivalentEntityMap` for mappings that also carry
learner state. A surviving PDF or module is not automatically a transferable
project completion. Draft rows must never participate in learner continuity.

The retained-playback resolver preserves the existing narrow grace window for
an old session allocated before publication. Resolving that session is not a
new access grant: playback callers still enforce enrollment/entitlement checks.

## Authoring writes

`CourseStagedAuthoringService` owns draft creation, confirmation of editorial
choices and publication. Publication keeps clone/swap/lineage changes within
the existing canonical-course/revision/draft lock order and transaction.
Notification campaigns and attachment grants retain their existing durable
commit boundaries.

Consumers that both edit and read, such as the course authoring coordinator,
receive both services explicitly. Reader-only API controllers and dashboard
preview/report services must not depend on the writer.

`CourseAuthoringRevision` owns the persisted status/marker names and draft-slot
identity shared by readers and writers. Those values describe existing stored
rows; changing their spelling is a data migration, not a cosmetic rename.

## Verification

`CourseRevisionResolverTest` checks that read-side services resolve without
authoring dependencies, do not write to the database or call the network, and
ignore draft/deleted mappings while following successive published identities.

Keep the integrated staged-publication, classification merge, access-plan
identity, attachment lifecycle, project revision, saved-library, playback and
completion tests. They verify the actual publish/read path rather than just
checking the new file names. SQLite coverage does not prove concurrent MySQL
lock scheduling.
