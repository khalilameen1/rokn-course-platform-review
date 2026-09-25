# Commercial report ownership

Course and platform reports read the same financial evidence. Their constructors
now expose the distinct responsibilities instead of using the course report as
both a data collector and a general-purpose financial calculator.

## Owners

- `CourseCommercialReportService`: loads a course's learner/order data, joins
  period cash and service-cost results, and assembles current/previous reports.
- `CourseCashAttributionService`: reads the source-lot allocations in one batch
  and projects their cash evidence for a supplied group of accepted orders.
  `forOrders` consumes already-loaded allocations and validated coin splits; it
  does not read current package/plan prices, re-read the wallet or mutate orders.
- `CommercialLearnerSummaryService`: aggregates already-built learner rows and
  calculates unit ratios. Both course and platform reports call it directly.
  It distinguishes people from enrollments and unknown amounts from zero.
- `CoursePlanHistoryReportService`: attributes period purchases to immutable
  order snapshots and usage to a contract accepted at the event time. It does
  not substitute the current enrollment tier when historical evidence is absent.
- `CourseFinancialLedgerReportService` remains the owner of validated wallet
  debit splits. `CourseCostReportService` remains the owner of service costs.

The old `groupSummary`, `cashForOrders` and historical-attribution implementations
were removed from the course report. There is no compatibility forwarding layer
or second calculator left there. The platform report depends on the row summary
owner explicitly; HTML/CSV consumers still receive the same report keys.

## Financial invariants

1. Reward coins and grant access are not newly collected money.
2. Proven service compensation and provider test purchases are separate
   zero-new-cash channels, not missing evidence or paid revenue.
3. Missing/reversed funding sources are unreconciled, not confirmed net income.
4. A catalogue estimate stays separate from evidenced gross. A store price with
   no supplied settlement currency is not converted into apparent EGP revenue.
5. Foreign currency exposure stays separate; no current FX rate is invented.
6. An explicit provider net amount, including zero, takes precedence over fees.
   Missing net and fee information leaves settlement incomplete.
7. Allocation ratios are summed before monetary rounding. No per-fragment
   rounding, price adjustment, tax rule or commission rule was introduced.
8. A missing wallet link or historical contract prevents known tier growth.
   Unknown provider cost is not a free request; a failed but evidenced provider
   charge still contributes cost without becoming a delivered answer.
9. Period boundaries remain half-open. A contract can predate the reporting
   window, and retiring its operational order does not erase its snapshot.

## Verification

Existing course/platform report, selected-period page and CSV tests continue to
exercise the full report. Their expected amounts and assertions are unchanged.

`CommercialAttributionBoundaryTest` blocks resolution of course reports, costs,
live plans and the ledger reader while testing projection/summary directly.
It checks source immutability and absence of database queries during projection,
provider/net/currency evidence, compensation, rounding and incomplete summaries.

`CoursePlanHistoryBoundaryTest` provides only order and usage tables, with the
course report, cost service and live plan service forbidden as dependencies.
It checks reused plan identities, retired order snapshots, period boundaries,
missing contracts and paid-but-undelivered provider responses.

This is a local ownership refactor, not a financial policy change or a claim of
live settlement verification. No deployment, schema migration or data rewrite is
part of it. Repository-wide maintainability still requires its broader audit.
