# Reporting periods and cost evidence

`ReportPeriod` freezes one UTC window for the page, its comparison and its queries.
The visible business timezone is `app.business_timezone` (Cairo by default).

- `today`: local midnight through now, compared with yesterday through the same local clock time
- `7d`, `30d`, `90d`: trailing elapsed days, compared with the immediately preceding equal window
- `all`: no prior-period percentage
- Boundaries are `>= start` and `< end`; a boundary event belongs to only one window
- Missing values have no percentage; zero-to-positive is new activity, not a fabricated 100 percent

Course purchases use `orders.approved_at`. AI usage uses the original event's
`created_at`, never its later reconciliation/update time. Existing learners'
usage is included even when they enrolled before the selected period. Current
enrollment state is labelled separately from new enrollments in the period.
Tier history comes from accepted order snapshots at the event time, not the
learner's current tier or a subsequently edited price plan.

`AiUsageReportService` reports only provider-confirmed charges (or documented
zero-cost cache hits). A charged request still costs money if delivery fails.
Reservations remain part of quota enforcement, not actual financial reporting.
Missing provider receipts and missing historical exchange rates stay unknown.

`ai:reconcile-provider-costs --limit=10` reads the official OpenRouter generation
billing endpoint for recorded request identities. The scheduler runs a bounded
batch every 15 minutes. It never regenerates answers, changes entitlements or
replays learner messages. Unresolvable historical identities remain pending.

`ProviderInvoiceReportService` reads recorded final invoices only. It is not an
automatic connection to every provider account. Each invoice is recognized on
its billing-period end date (local midnight), never prorated across report days.
Platform invoices are counted once. A shared invoice is not evidence of an
individual learner's or course's charge; only an explicitly course-attributed
invoice enters that course's known service amounts. An absent invoice is not a
zero bill. Incomplete service coverage prevents a final operating margin.
