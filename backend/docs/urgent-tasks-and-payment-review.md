# Urgent queues and payment review ownership

## Urgent tasks

`AdminUrgentTasksReadService` owns selection for the dashboard preview and full
lists. The overview counts all matching records but hydrates at most five per
queue. Full lists paginate twenty records with an ID tie-breaker. Account-state
versions are computed only for students on the returned page. Course setup uses
the canonical content inventory, not working copies or publication archives.

The view links to the audited order page using **مراجعة الطلب**. It does not
claim to approve or reject a payment. Existing POST routes remain redirect-only
for already-open browser pages; they never mutate the order. Account activation
continues through `StudentAccountStateService` with its existing concurrency guard.

## Reconciliation findings

`PaymentReconciliationReviewService` owns the locked state transition and its
transaction. `PaymentFindingEditorVersion` defines the shared evidence/version
contract. The HTTP controller validates the form and identifies the authenticated
actor, then delegates. Existing route authorization and audit middleware remain.

Only open findings can be resolved or ignored. A closed finding must be reopened
before another decision. New evidence invalidates an old editor version even if
the finding is still open. Notes are trimmed and validated at the write boundary.

Human review changes the finding only. It does not settle an order, change a
wallet balance, verify a provider receipt, or issue compensation. Those operations
retain their separate existing owners.

## Regression coverage

`AdminUrgentTasksOwnershipTest` covers full counts versus bounded previews,
stable pagination, canonical setup detection, review links, redirect-only legacy
actions, and HTTP pagination validation. `PaymentReconciliationDashboardTest`
covers route auditing and unchanged orders, shared version compatibility,
transaction rollback, new evidence, note validation, and allowed transitions.

See the repository README for local verification evidence and limits. No
deployment or store build is part of this refactor.
