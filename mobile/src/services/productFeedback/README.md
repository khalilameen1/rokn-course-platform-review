# Product feedback ownership

Screens and account migration use `../productFeedback.ts` as the public entry
point. Keep its exports stable; feature internals must not import that entry
point back. This avoids cycles while keeping storage details out of callers.

- `contracts.ts` owns feedback types, category mapping and identifier checks.
- `wire.ts` encodes multipart requests and validates server responses. It does
  not send requests or persist anything.
- `receipts.ts` owns local case tracking and serializes receipt writes by
  resolved owner. Remote delivery and successful local tracking are separate
  outcomes. A local timeout does not cancel an in-flight native write.
- `drafts.ts` owns new/reply drafts, attachment retirement, conflict restoration
  and guest draft adoption. Adoption uses the same ordering as ordinary saves
  so an unfinished save cannot overwrite a newly migrated draft.
- `../productFeedback.ts` coordinates HTTP requests, local receipts, complete
  paginated history and guest-to-account adoption. It never manipulates raw
  storage keys, native storage or receipt/draft queues.

## Invariants when changing this feature

Preserve idempotency keys and multipart identity across retries. Do not turn
successful delivery into an apparent failure because local tracking failed.
Copy guest data before deleting its original; retain both conflicting drafts.
After asynchronous I/O, verify the captured account boundary before continuing
owner-sensitive work. A failed account history page is not an empty history.
Only retire a previous screenshot after the replacement draft is durable.

Receipt reads never delete or rewrite storage, including malformed JSON. A late
read may contain an older snapshot than the current saved list. The next ordinary
serialized receipt write replaces malformed data without a separate removal.
Storage errors remain distinguishable from an empty list. Receipt migration keeps
its own queue tracking because it must wait for raw pending writes before copying
and retiring guest data; a caller timeout does not release those writes.

`feedbackDraftMigration.test.ts` exercises concurrent save/adoption and account
replacement. The delivery, reply, pagination and shared draft restoration
suites cover response loss, late storage, attachment ownership and recovery.
`feedbackReceiptOwnership.test.ts` proves stale corrupt reads cannot erase new
receipts, reads are non-mutating and corrupt storage can be replaced without
depending on a separate delete operation.
Run from `mobile`:

```sh
npm test -- --runInBand feedbackDraftMigration feedbackSubmissionDelivery feedbackReplyDraft feedbackCasePagination draftRestorationDurability conversationDraftHydrationFailure
npm run typecheck
```

These tests use the public entry point and actual orchestration. Source-text
checks cannot substitute for these behavior checks.
