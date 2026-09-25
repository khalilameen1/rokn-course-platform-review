# Course section authoring application boundary

`AdminCourseSectionApplicationService` owns create, update, delete and reorder
transactions. `CourseSectionController` owns HTTP validation, create-receipt
adaptation and JSON/redirect responses. No request or controller is resolved by
the application service.

Section mutations lock and validate the expected draft version before changing
content, ordering or durable media references. Creation receipt completion and
the authoring-version increment share the same transaction. Immutable media is
staged before the transaction; cleanup and media probes stay with their existing
owners. The outline presenter provides the existing response contract.

After replacing the polymorphic lesson/project target, the application service
clears the loaded `sectionable` relation before presenting the section. Otherwise
the response could describe the removed lesson even though a project was saved.

`CourseSectionOrderingService` rejects foreign modules, foreign or duplicate
section IDs, invalid learning layouts and duplicate module projects. Both direct
application callers and HTTP callers use the same ordering invariants.

Coverage is in `AdminCourseSectionApplicationServiceTest` alongside the existing
section input, atomicity and media tests. Local verification and its limits are
recorded in the repository README.
