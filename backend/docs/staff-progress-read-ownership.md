# Staff progress reads

`AdminStudentProgressReadService` owns dashboard progress listings, student detail,
comparison and aggregate statistics. The HTTP controller only validates filters
and selects the view/JSON response. `StudentProgressSummaryService` remains the
batched latest-enrollment reader used by both the student workspace and progress
listing; it is not folded into the dashboard controller or duplicated.

`SectionProgressSummary` is a query-free projection of an already-entitled sequence
and lineage-resolved progress. It is shared by latest-enrollment summaries, detail
and comparison. It filters unrelated rows, deduplicates completions, groups section
types and computes last activity once. The unused controller detail helper and
separate last-activity query are removed.

Purchased snapshots and legacy completion policy still choose whether projects
belong to a student's denominator. Statistics continue averaging unrounded
user/course percentages before rounding the final mean; they do not average the
rounded display summaries. Empty detail/latest activity remains null, while the
existing comparison API retains its zero empty-path marker. No read records
completion, modifies entitlements or calls the completion writer.

Pure projection tests and staff-read ownership cases complement the watch-only,
legacy, upgrade and HTTP contracts in `WatchOnlyStaffProgressTest`. See the
repository README for local verification evidence and limits.
