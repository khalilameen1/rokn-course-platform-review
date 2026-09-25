# Course section access and completion

`CourseSectionAccessService` owns the read-only course/plan checks and crossing-
project gate projection shared by playback, project reads/submission, the learner
course map and completion. It has no completion, achievement or presentation
dependency. Existing plan snapshots, revision-aware project review evidence and
`CourseSectionSequenceService` ordering remain authoritative.

- `sectionAccessState` / `canAccessSection` enforce course access before reading
  project rights and sequence gates. Missing sections fail closed.
- `sectionLockStatus` projects a supplied curriculum. Resource/dashboard callers
  can pass their already resolved project policy explicitly, including previews.
  This projection alone is not course authorization.
- `sequenceState` reads a single section's gate. Completion uses this after its
  existing course/plan checks and evidence validation; it does not move error
  priority, transaction boundaries or learner/course locks.
- `projectsEnabledForUser` only chooses the curriculum shape. Anonymous/legacy
  callers retain projects; an explicit captured watch-only plan excludes them.
  It does not grant enrollment or bypass course authorization.

`CourseCompletionService` retains progress mutations, completion acknowledgements
and achievement signaling. `CoursePresentationService` builds response data and
uses the shared access reader; it no longer implements crossing-gate rules.
Consumers are migrated directly, without parallel implementations or old-method
forwarding facades. There is no schema, route or response-contract change.

Behavioral coverage includes watch-only/grant rights, purchased snapshot upgrades,
inactive/expired/missing access, missing sections, module ordering, project-review
gates, revision continuity, playback and real project completion. Read-side tests
reject completion/presentation dependency resolution and assert no database writes,
progress rows, submissions, certificates or external requests.

Local changes only. Automated tests do not establish native-device or live-media
delivery verification; this refactor does not deploy backend or mobile code.
