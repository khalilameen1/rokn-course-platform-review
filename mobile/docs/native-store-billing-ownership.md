# Native store billing ownership

nativeStoreBilling coordinates connection/listener lifecycle, active payment
sheets, authenticated account binding, product hydration and foreground recovery.
It does not parse verification responses or directly finish transactions.

nativeStoreReceipt owns verification and transaction finalization. Callback and
recovery paths share its in-flight receipt map. A different account cannot join
another account's verification. Server authorization and valid credited amounts
are required before finalization. Server-consumed Google purchases are not
consumed twice; Apple still finishes on the device. Matching terminal checkout
bindings are cleared after finalization, not on a failed verification/finish.

nativeStoreCredits owns bounded deduplication, current-account checks and screen
subscriptions. It cannot open or finish a payment. Subscribers import this module
without loading the store SDK. Local scope lookup failure leaves notification
delivery retryable; one throwing observer cannot prevent other observers from
receiving an accepted result. Settlement does not depend on notification success.

All paths use the existing CoinCheckoutResult contract rather than a duplicate
native result shape. The durable course binding format and server endpoints are
unchanged. Native and external payment coordinators retain their distinct provider
lifecycles; neither is a forwarding wrapper for the other.

nativeCourseCheckoutBinding owns durable recovery hints per account and store
product. Save and conditional removal share one keyed mutation queue, including
the old-checkout status lookup. A delayed removal cannot erase a newer save, and
concurrent replacements must validate the latest binding rather than both accept
the same expired one. Account ownership is rechecked when queued work starts and
after asynchronous reads, before status lookup or mutation. A failed mutation
rejects its caller but releases the queue for retry. Different products/accounts
are independent. Recovery reads remain best effort and do not wait for mutations.

The queue primitive is shared with courseAccessAttemptStore and the external
coinCheckoutAttemptStore, but each owner has its own queue instance. The external
checkout ledger uses one key per account (not per package: all packages occupy
the same stored document). A stalled old-account cleanup therefore cannot block
a new account, while same-account ledger edits remain ordered. These queues
order operations within one JS runtime, not across processes, and do not replace
server settlement or idempotency checks.

checkoutReturn uses the same primitive with its own queue keyed by its
account-scoped destination record. Old receipt acknowledgement cannot erase a
newer destination for the same account or hold up navigation intent storage for
a different account. It retains session checks before and after storage writes.

## Local evidence

The original native billing integration suite still covers receipt recovery,
account changes, course binding, cancellation/deferred errors, listener setup
retries and Google consumption. Added integration cases exercise scope read
failure and a throwing observer without changing a successful purchase.

nativeStoreReceipt.test covers concurrent verification, account ownership,
verification/finish failure retry, pending receipts, invalid server contracts
and Google/Apple finalization. nativeStoreCredits.test covers observer isolation,
account changes, concurrent delivery, retry, unsubscribe and bounded memory
without importing the store bridge.

nativeCourseCheckoutBinding.test reproduces delayed-removal and concurrent-save
races, and covers pending bindings, terminal replacement, account changes,
independent products/accounts, storage/server failures and recovery reads.
keyedAsyncQueue.test covers ordering, independent keys/owners, failed-operation
release and old-tail cleanup while a newer operation is still queued.

These are local mocked-provider tests, not a real Play/App Store purchase or
verification on a physical device. No store build or server deployment is implied.
