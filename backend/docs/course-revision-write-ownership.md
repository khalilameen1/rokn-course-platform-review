# Course revision writes

## Ownership

`CourseStagedAuthoringService` is the application entry point for isolated drafts
and publication. It owns the canonical -> revision -> draft lock order, expected
version checks, readiness gate, lifecycle transitions and the outer transaction.
The dashboard continues to call this entry point; learner reads continue to use
`CourseRevisionResolver` and `CourseRevisionLearnerReadService`.

`CourseRevisionGraphService` owns cloning the content graph, clone-time identity
mappings, media-state copies, exchange of content ownership and classification
merging. A real temporary course row keeps the exchange foreign-key valid. This
owner does not render HTTP responses, read learner state, or start/commit an
independent transaction.

`CoursePlanPublicationService` owns the commercial part of that exchange. It
updates editable offers without moving plan IDs between courses. Enrollment,
order and AI ledger identities and purchased snapshots are not rewritten. It
reserves unused unsigned sort slots before applying permutations. Offer editing
and pricing validation remain in `CoursePlanAuthoringService`; publication is
not a second pricing or checkout implementation.

`CourseRevisionLineageService` runs after the graph becomes canonical, in the
same outer transaction. It retains only surviving section/content aliases,
carries their original learner roots and advances lesson-scoped grant pointers.
Deleted or semantically replaced sections do not inherit prior completion. A
lesson grant whose scope disappears is disabled, never expanded to the course.
Historical progress rows are not duplicated per learner during publication.

## Atomicity

All four exposed component operations have `WithinTransaction` names and reject
calls outside a transaction before writing. The application entry point remains
responsible for acquiring the course/revision locks; the transaction guard does
not claim to prove lock ownership. Components do not catch failures and pretend
success. An exception reaches the one outer transaction and rolls everything back.

The established order is unchanged: content owner reassignment, editable offer
exchange, content graph exchange, canonical attributes, learner aliases/grants,
revision archival and notification intent. Notification preparation is inside
the transaction. Cache invalidation is after commit. The durable attachment-grant
signal is recorded inside the transaction through an explicit dependency.

## Verification contracts

`CourseRevisionWriteOwnershipTest` exercises the real migrated SQLite schema
without an enclosing test transaction. It checks rejection of fragment writes,
isolated graph copying, caller rollback, repeated learner-root continuity and
revocation of a removed lesson scope. Its late-failure test observes changed
content ownership, offer prices and grant pointers before interrupting notification
preparation, then compares every affected graph/lineage table to its prior state.

Existing `CourseStagedAccessPlanIdentityTest` covers sold IDs, immutable receipts,
real relational constraints, legacy plan creation, repeated publishing and sort
permutations at the unsigned boundary. Classification merge, attachment lifecycle,
project lineage, completion acknowledgement and studio recovery tests continue to
exercise the same public entry point with the real new components. Only readiness
checks are substituted where those tests intentionally isolate publication from
external media availability.

`CourseRevisionResolverTest` forbids resolving any of these writers during learner
read operations. Read paths must not become coupled to the authoring dependency
graph just because publication has been separated.

Local tests do not substitute for native MySQL-specific tests or testing on a real
device. No schema, public API contract or deployed server is changed by this split.

## Remaining work

This boundary does not finish the repository-wide maintenance goal. Section content
and media input now have a separate boundary documented in
`course-section-edit-input.md`. Course-level dashboard authoring and shared authoring
receipt/concurrency adapters still require their own input/ownership assessment,
preserving partial-save semantics and existing controls rather than copying form
parsing into additional layers.
