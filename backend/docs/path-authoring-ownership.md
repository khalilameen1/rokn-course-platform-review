# Path authoring and staged course publication

`AdminPathReadService` supplies canonical course choices and the path editor version.
Revision/archive rows are excluded from both. Adding or editing an implementation
copy therefore neither exposes it as a course choice nor invalidates a path form.
The version still covers canonical assignments across paths so an old form cannot
silently take back a course moved by another path editor.

`AdminPathAuthoringService` owns creation, title/interest writes, membership changes
and guarded deletion. It locks affected canonical courses in ID order before the
path, matching the publication workflow's canonical-first ordering. It rechecks
selected IDs against real canonical rows under those locks. Creation and its HTTP
receipt commit together. Assignments never mutate working copies or archives.
The existing deletion guard still rejects paths referenced by retained courses.

`CoursePathSelectionService` reconciles the second legitimate editor: the course
studio. New clones record their base path in the existing revision-entity ledger
under `authoring:path-snapshot`; zero means unassigned. Publication compares base,
live and draft choices. An unchanged draft retains newer live administration;
a changed draft applies when the live path is unchanged or agrees. Different edits
on both sides are rejected with an actionable conflict, before exchanging graphs.
The archived course retains the exact previous live choice.

An explicit path save through the course writer records the reviewed live base.
An unchanged field that still equals the original base is not treated as a new
choice just because a full form submitted it. Legacy drafts with no snapshot may
publish unchanged matching paths, but differing paths require a reviewed selection.
No migration or public API change is needed; internal snapshot rows never carry
learner progress state.

Coverage added: `AdminPathAuthoringOwnershipTest` and `CoursePathPublicationTest`.
They cover read choices, revision-independent versions, atomic receipts, hidden/
deleted selections, stale reassignment, deletion guards, three-way publication,
unchanged full forms, conflict resolution and legacy drafts. See the repository
README for local verification evidence and limits.
