# Account erasure and stored-file ownership

## Entry point and transaction

`AccountDeletionService::delete` owns deletion of the learner identity. It marks
linked social identities against stale login attempts, locks the user, revokes
Apple authorization when required, records consumed acquisition rewards before
unlinking providers, and anonymizes the retained account shell.

All database erasure runs in this account transaction. Financial and enrollment
records keep their relational identity; deleting personal content must not delete
evidence of a paid provider call or grant the same welcome reward twice.

The account owner delegates these independent responsibilities:

- `AccountUploadedContentErasureService` captures profile/project/certificate,
  AI input, support attachment, and legacy photo paths before scrubbing their
  database references. Its learning, support and legacy-photo entry points keep
  the existing deletion order around portfolio/AI erasure. It retains submission
  review evidence, revokes certificates, and scopes every lookup to the learner
  (including the photo model type). It does not own identity, the cleanup ledger,
  queue dispatch, or physical deletion. Returned disk/path pairs are admitted by
  the account owner into the same transaction's cleanup ledger.
- `AccountAiDataErasureService` settles already started/landed provider work and
  erases personal AI metadata. It never starts another provider request. A landed
  answer retains exact cost without spending learner allowance. Work not started
  releases its reservation. The operational whitelist survives repeated erasure,
  including false/zero values and flattened prompt-version/feedback-level fields.
- `AccountPortfolioErasureService` erases portfolio descriptions and public
  visibility while retaining private media references required for remote
  deletion. It removes empty items immediately and registers remote cleanup only
  after the outer transaction commits. Failure to enqueue leaves those references
  available to `privacy:cleanup-portfolio-media`.
- `StoredFileDeletionService` records local/disk cleanup intent in the existing
  encrypted `account_file_deletions` ledger. It does not upload or delete bytes.

The three content erasure owners reject calls outside the account transaction. The caller
must also hold the learner lock; the transaction check does not acquire it.

## Upload versus cleanup

`StoredFileUploadService` owns upload destination naming, physical byte writes,
same-target resume primitives, and the shared request I/O deadline. All upload
callers use this owner directly; the deletion owner has no forwarding upload API.

`storeTrackedUpload` commits potential-orphan intent **before** the first byte
write. It rejects an outer transaction through the cleanup admission contract.
If recording intent fails, no storage write starts. If storage later fails or the
process dies before domain publication, the durable row remains recoverable.
Generic retries keep logical filenames but use distinct physical attempt paths.
Explicit same-target resume remains a decision of the domain admission owner,
not an inference from an orphan row.

The request deadline attribute, multipart threshold, bounded S3 timeout policy,
and retry behavior are unchanged by the ownership split.

## Cleanup admission modes

- `deleteOrQueue` is for released paths: a currently live reference prevents
  admission.
- `queueReleasedFiles` is for the transaction removing the references, such as
  account erasure. It requires that transaction and deliberately does not check
  references before commit. It normalizes/deduplicates disk plus path, records the
  account owner, and dispatches only after the outermost commit.
- `trackPotentialOrphan` is for bytes about to be staged. It requires no active
  transaction, records delayed cleanup before writing, and returns whether the
  same ledger target had already existed.

All three modes use one ledger writer. External URLs and blank destinations are
not cleanup targets. Stored paths remain encrypted. Database rollback discards
both cleanup intent and its pending dispatch callback.

`DeleteAccountFile` rechecks references before physical deletion, keeps failed
paths for retry, and clears successful/skipped paths. Queue failure does not undo
committed intent; `privacy:cleanup-account-files` redelivers the existing row.
Job identity, retry schedules, ledger schema, and commands remain unchanged.

## Verification

- `StoredFileOwnershipTest`: transaction admission, outer-commit dispatch,
  rollback, deduplication, reference checks, queue recovery, storage retry,
  pre-write ledger ordering, ledger failure, immutable retry paths.
- `AccountDeletionOutboxTest`: end-to-end account deletion, Apple revocation,
  rollback of identity/files, portfolio erasure, queue and remote cleanup recovery.
- `AccountAiDataErasureTest`: unstarted/started/landed work, exact costs, whitelist
  idempotency, isolation from other users, rollback, and full account integration.
- `AccountUploadedContentErasureTest`: all uploaded-content families, retained
  review evidence, certificate revocation, deduplicated cleanup, account and photo
  model isolation, complete rollback/retry, and the no-byte-deletion/no-dispatch
  boundary of the content owner. These tests use real outer commits in an isolated
  in-memory database and fake disks.
- Existing storage budget and domain upload retry tests cover project files,
  attachments, course authoring images/PDFs and notification artwork.

These local tests do not establish production storage availability, native-device
behavior, or completion of repository-wide maintainability work.
