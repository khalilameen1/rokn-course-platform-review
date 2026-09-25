# Product operations dashboard report

`AdminProductOperationsReadService::report` owns composition of the operations
dashboard data. It combines the existing capability, release, financial ledger,
payment, playback, recovery evidence, feature flag and runtime readers. Its
dependencies are explicit constructor arguments; it takes no HTTP request and
does not choose a view, redirect or execute recovery commands.

`ProductOperationsController::index` renders the same `admin.product_operations`
view with the report's existing named fields. `AdminOperationalRecoveryService`
owns retry, skip and failed-job acknowledgement. `AdminProductFeatureAuthoringService`
owns locked feature updates; `ProductFeatureFlagService` remains the reader.
The controller validates HTTP input, supplies the operator identity and renders
the existing redirects. Neither writer resolves the controller or report reader.
No routes, roles, MFA requirements, mutation behavior or view contracts changed.

The report's queries and presentation rules moved together, without forwarding
helpers remaining in the controller. In particular:

- The public catalogue query still determines published-course readiness.
- Course coin totals still come from financial ledger allocations, not mutable
  course prices. Payment-channel summaries still distinguish confirmed amounts
  from estimates and unsettled receipts.
- Media readiness still requires a matching provider generation, a reconciled
  positive duration and no blocking integrity issue. A course-cover warning can
  remain visible while its video is playable. The attention list is bounded to
  twenty entries; its total counter is not limited by that display bound.
- Issued, pending and revoked certificates remain separate counts.
- Today's playback window still uses the business timezone and an exclusive UTC
  end, not the server's local calendar day.
- Runtime incidents and feature flags are observed only. Reading the report does
  not reconcile incidents, restart jobs, publish courses or repair media.

`AdminProductOperationsReadServiceTest` checks the complete field contract,
absence of database writes and task/provider dispatch, persisted incident/flag
isolation, media classifications, certificate states and business-day boundaries.
The existing media display, feature flag, dashboard parity and authorization tests
exercise the HTTP entry points and rendered page.

These are local automated checks. They do not prove live provider health or
complete repository-wide maintainability.

## Command boundaries

Outbox retry and skip reload the event under a row lock and admit only a currently
failed event. Retry preserves its event key, payload and attempts, resets only its
failed deliveries and dispatches only after the outer transaction commits. Broker
failure leaves a pending event with no dispatch timestamp for recovery. Delivered
deliveries and other events remain untouched. Acknowledgement deletes only the
named failed-job record and never deserializes or replays its payload.

Feature authoring checks a known configured key, future expiry and the current
editor version under the per-feature singleton lock. The responsible operator and
reason persist with the decision. Existing HTTP expiry feedback is preserved.

`AdminOperationalRecoveryOwnershipTest` and `AdminProductFeatureAuthoringTest`
cover these command contracts. See the repository README for local verification
evidence and limits.
