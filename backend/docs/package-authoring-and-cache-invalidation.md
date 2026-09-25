# Payment product authoring

`AdminPackageAuthoringService` owns create/update/delete transactions, active
channel eligibility, optimistic editor versions and financial-history retention.
The controller keeps field/format validation and HTTP receipt/redirect handling.
`PackageEditorVersion` is shared by the form and locked mutation. Issued store
product IDs and coin quantities remain protected by the Package model for all
writers. Store price verification and order settlement are unchanged.

Product creation and its receipt commit atomically. Products with order history,
store receipts or issued SKU identities cannot be deleted, including when their
channels are disabled. Administrators can deactivate a product without rewriting
its historical commercial contract.

## Derived cache effects

`AfterCommitCacheInvalidation` owns the small repeated scheduling rule for
best-effort cache invalidation: execute immediately outside a transaction or
after the outer commit, discard on rollback, catch failures when the callback
actually executes, and isolate optional reporting failures. It must not be used
for authoritative database writes, financial settlement or durable job delivery.

Package, settings, reward rules, public settings and course catalogue generations
use this boundary. Their keys, TTLs, generation algorithms and reporting policies
remain with their existing owners. Previously several callers caught errors only
while registering the callback; a later cache error could escape after a successful
database commit and make the dashboard report a failed save.

Added coverage: `AdminPackageAuthoringOwnershipTest` and
`AfterCommitCacheInvalidationTest`, alongside the existing catalogue revision and
public-settings tests. See the repository README for local verification evidence
and limits.
