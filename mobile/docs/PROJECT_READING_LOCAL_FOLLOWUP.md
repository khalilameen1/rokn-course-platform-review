# Project discussion and course reading — local follow-up

Implementation scope: local mobile source only. No deployment, Play upload,
backend migration, phone installation, or changes to the review build.

## Project report

- The full authored report remains visible without truncation or a generated summary.
- `هل لديك سؤال؟` is the optional discussion entry after the report. The composer,
  follow-up history, and draft attachments stay collapsed until requested.
- Closing discussion does not clear its draft or attachments, send a message,
  or change project completion. The accepted-project continuation stays in the
  existing fixed footer outside the report scroll view.
- Report-only access opens the existing `FullTrackUpgradeSheet` directly.
  Its feature filter selects plans with project follow-up enabled. The existing
  `CourseSubscriptionSheet` runs in `upgrade` mode and owns the actual server
  quote, price difference, store checkout, and reward exclusion. No second
  purchase implementation or locally calculated price was introduced.
- After a completed upgrade, the existing course-entitlement refresh is called
  at the current feed index. A changed feedback entitlement also rehydrates the
  thread's real quota/permissions without resetting the local draft.
- Exhausted discussion quota is shown only inside the opened discussion. It
  does not incorrectly offer another purchase of the same subscription.

## Course details

- Authored descriptions initially show four rendered lines. Overflow is measured
  at the current width/font scale, with an accessibility-hidden measuring copy.
  Only overflowing descriptions get `عرض المزيد`; the complete content is retained.
- Instructor details are an optional `عن المدرب` disclosure.
- The public outline retains preview navigation and accessible locked states,
  but removes repetitive unlock explanations and the redundant footer paragraph.
  Closed reels use a lock icon; projects and free previews retain their labels.
- Course content, curriculum sequencing, project requirements, progression gates,
  and backend/dashboard authoring contracts are unchanged.

## Verification boundaries

Automated coverage includes optional discussion, draft/history preservation,
report-only upgrade entry, feature-filtered checkout, entitlement rehydration,
long reports, continuation, measured description overflow, and preview locks.
Native small-screen/font-scale/keyboard and real Google Play purchase acceptance
still require a local test build and device verification before any release.
