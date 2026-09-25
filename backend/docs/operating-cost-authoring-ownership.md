# Operating invoice authoring

`AdminOperatingCostAuthoringService` owns invoice creation, updates, soft deletion
and the report exchange-rate setting. It accepts validated invoice fields and an
explicit actor/version, never an HTTP request. Creation completes its caller's
idempotent receipt inside the same transaction; receipt failure rolls back the
invoice. The existing dashboard create-intent middleware remains the replay owner.

Updates and deletion reload and lock the invoice before checking the editor
version. Legacy revision-course attribution can be retained, but new assignment
to an authoring copy is rejected. Edits compare against the locked current row,
not a route-bound snapshot captured before the transaction. Final and provisional
invoices remain distinct; editing does not silently finalize or recategorize them.

Exchange-rate edits acquire the existing settings singleton lock and compare a
rate-only version. They write only `openrouter_usd_to_egp_rate`, preserving other
settings and each historical invoice's own FX evidence.

`OperatingCostEditorVersion` is the shared read/write version definition; field
order and date/decimal normalization retain the existing editor contract.
`OperatingCostPoolController` keeps HTTP validation, response messages, invoice
list presentation and report/CSV delivery. The existing commercial report service
still owns report calculations and does not depend on the writer. No new generic
repository, report writer, provider call or migration is introduced.

`AdminOperatingCostAuthoringOwnershipTest` covers the writer independently of HTTP
and reports: receipt atomicity, creator identity, finality, stale updates/deletion,
historical course attribution, rate isolation and outer rollback. Existing invoice
entry and period-report tests retain their HTTP and financial-output coverage.
See the repository README for local verification evidence and limits.
