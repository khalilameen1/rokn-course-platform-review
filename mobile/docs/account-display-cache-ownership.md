# Account-scoped display cache ordering

Wallet and notification snapshots have independent owners, each using its own
`createKeyedAsyncQueue` instance. Their persisted account-scoped key is also the
queue key. A pending native write for one account cannot hold another account's
cache writes. Same-account writes remain ordered until native I/O really settles,
including across replacement sessions; an optional UI timeout is not cancellation.

Wallet reads use `waitForPending(key)` to await the already queued writes for
that account. The returned promise is a snapshot of the current tail, not a queue
slot for the read and not a wait for later operations. A suspended optional read
cannot prevent a newer snapshot from being saved. Notification reads preserve
their existing non-blocking behavior without a write barrier.

Session checks remain around native I/O and at queued write execution. Writes
queued by a retired session fail before storage; an already executing native
write can finish only on the key captured for its original account. Neither
cache supplies purchase authorization, reward entitlement or notification read
acknowledgement. Server state still owns these decisions.

Settings writes reuse the same primitive with a separate instance and account
scope as their key. This replaces the equivalent private tail map; existing
preference revision checks, native/server ordering and rollback behavior stay
with the settings owner.

Storage namespaces, version-2 formats, cache parsers and limits are unchanged.
No data migration, logout policy, financial storage or remote deployment is added.

`accountDisplayCacheOwnership.test.ts` covers cross-account independence, same-
account ordering, retired sessions, storage failure recovery and read barriers.
`keyedAsyncQueue.test.ts` verifies tail snapshots and failure handling. Existing
wallet invalidation, inbox storage lifecycle and settings write-order tests cover
the real consumers around these seams. These are automated tests, not device
storage or live-account verification.
