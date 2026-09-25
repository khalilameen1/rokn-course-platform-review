# Kashier payment responsibilities

The old `KashierPaymentService` aggregate facade is removed, not retained as a
second path. Checkout, notification and reconciliation flows now name the
specific owners they use. Routes, response fields, pricing, signatures and
provider verification requirements are unchanged.

## Boundaries

- `KashierCheckoutOrderService` creates/reuses a pending order under the user
  lock. It snapshots price/coins, preserves request-key replay semantics and
  rejects a second outstanding checkout. It depends on channel pricing, not the
  provider HTTP client or financial settlement.
- `KashierGatewayEvidenceService` interprets provider status, transaction
  identity, reversals and settlement facts. Its capture/conflict predicates are
  read-only. It sanitizes evidence and validates amounts/currency but cannot
  credit a wallet, issue an order or call the provider.
- `KashierProviderOrderService` owns the order probe, including enriching a
  captured notification whose transaction ID is missing. It depends on provider
  configuration and evidence interpretation, not financial writers. Fetching a
  provider result is not local fulfillment.
- `KashierOrderSettlementService` owns authenticated local capture/failure/
  reversal decisions. It delegates actual wallet, receipt and provenance writes
  to `OrderLifecycleService`, as before. `fulfillOrder` runs the locked capture
  decision, then the existing follow-up finding/notification path. No provider
  fetch or current catalogue repricing occurs inside settlement.
- `Order::packageCoinAmount()` is the nonnegative purchased coin snapshot.
  Package fulfillment/reversal and Kashier presentation use it. Missing snapshot
  coins remain zero; they never fall back to the package's current catalogue
  amount.

## Financial invariants retained

Keep the user lock before the order lock. Re-read order ownership and status
under those locks. Transaction-ID uniqueness, signed evidence matching and the
actual approval remain inside the same database transaction. Capture retries
repair interrupted local fulfillment without issuing another wallet credit.

A reversal may arrive before capture. Preserve terminal/review states rather
than crediting a delayed capture. Missing/conflicting transaction IDs require
review; partial refunds remain review evidence, not an inferred full reversal.
A late authenticated capture for a cancelled/expired checkout is still real
payment and follows the existing exactly-once recovery path. Deleted accounts
are recorded for reconciliation without reopening access or crediting them.

Follow-up findings/notifications retain their original error isolation and
idempotency keys. This refactor does not change when an outer caller's database
transaction commits or introduce an asynchronous financial write path.

## Verification

`KashierResponsibilityBoundaryTest` uses the real migrations and deliberately
binds forbidden collaborators to throw. It verifies read-side independence,
checkout without remote/settlement dependencies, local replay fulfillment after
catalogue changes without provider/pricing dependencies, and missing-transaction
probing without database queries or financial writers.

`KashierPaymentTest`, `KashierReconciliationTest`, endpoint hardening and financial
provenance tests keep their existing behavioural assertions. Only their direct
service entry points moved to the proper owner. Run the complete backend suite
after changes because `OrderLifecycleService` also handles store purchases and
course entitlements. Local SQLite/HTTP-fake tests do not prove live provider or
production-database concurrency behaviour.
