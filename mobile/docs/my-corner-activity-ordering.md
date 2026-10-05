# MyCorner course activity ordering

## Reference and compatible reuse

Moodle's [recently accessed courses](https://docs.moodle.org/405/en/Recently_accessed_courses_block)
is a shipped dashboard experience. Its [mobile implementation](https://github.com/moodlehq/moodleapp/blob/main/src/core/features/courses/services/courses-helper.ts)
sorts the `lastaccess` option by descending server-provided course access time,
not by video playback position. The [Android listing](https://play.google.com/store/apps/details?id=com.moodle.moodlemobile)
shows 50M+ downloads when inspected on 2026-10-05. That is adoption evidence
for the app, not a count of users of this particular block.

This unit follows the visible recency principle and its data separation, not
copied Moodle code. Moodle's Angular/site-services stack is not a compatible
drop-in owner for Rokn's React Native/Laravel contracts. No matching ready-made
Rokn activity adapter was found. The existing payload mapper, stable array
sort, account-scoped dashboard cache and course shelf remain the implementation
owners; no dependency, second feed or new presentation is introduced.

## Demonstrated defect

The backend already publishes `last_activity_at` as the latest watching or
completed-section progress. Passing a project records its completion time.
The mobile mapper instead preferred `resume.watched_at`, and MyCorner sorted
that value as though it were course activity. A course watched on October 1
and with a passed project on October 5 could appear behind a course watched
on October 4. This contradicted the existing newest-activity-first shelf.

## Contract

- `CourseProgress.lastActivityAt` maps canonical `last_activity_at` separately
  from `lastWatchedAt`, which now describes the actual resume watch only.
- MyCorner sorts by canonical activity. Missing/invalid activity falls back to
  watching for existing device snapshots; equal/missing times keep server order.
- Sorting works on a copy. Progress, completion status, `next_section`, video
  seek positions and project gates do not change.
- Dashboard cache version 3 retains the additive field using its existing
  serialization/normalization. Old snapshots remain readable; offline display
  does not invent a recent project time absent from the stored data.
- Backend and dashboard already share the correct progress/entitlement owners.
  This is a client mapping defect, not a reason for fake server changes or a
  deployment that could affect the Google Play reviewer.

## Authored evidence, not executed

`myCornerResumePresentation.test.tsx` adds real payload-mapper -> model -> shelf
coverage for the old-watch/new-project example, independently correct lesson
and project resume actions, serialized snapshots, legacy fallback and stable
ties. `myCornerStorageBoundary.test.tsx` adds real learning GET -> mapper ->
dashboard cache write/read -> model coverage and an older version-3 snapshot
without the additive field. Existing ordering, focus, partial-read, account-cache
and canonical-next section cases remain in the final combined gate.

No tests, lint, typecheck, dependency installation, build, push or deployment
have run for this unit. Source inspection is not native acceptance.

Independent review accepted the limited source unit and the added real-cache
fixture after verifying the counterexample, unchanged lesson/project targets,
stable sorting and existing cache normalization. No remaining everyday source
blocker was found within this unit. Execution and physical-device storage remain
unverified until the final combined gate.
