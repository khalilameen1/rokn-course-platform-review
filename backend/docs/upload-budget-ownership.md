# Upload attempt budget

`Support/UploadBudget` owns one monotonic elapsed-time deadline. It does not read
the service container, the current HTTP request or wall-clock timestamps. Its
clock can be supplied explicitly for deterministic tests. Reading the remaining
time never resets the attempt.

The project submission controller creates a budget at entry and passes it through
`ProjectSubmissionOrchestrator` and `ProjectSubmissionService` to the upload owner.
Direct callers of either submission service also get the configured project
budget when none is supplied. Every file in one attempt shares the same object;
a fresh attempt has a fresh budget. Replay of an already committed submission
does not need another file write.

`StoredFileUploadService` accepts an optional explicit budget. It retains the
existing storage policy: leave two seconds for response handling, cap each remote
operation at six seconds and its connection at two seconds, disable remote retries
and avoid metadata probes when under a budget. The orphan ledger still precedes
byte writes. Calls outside project submission remain unbounded unless their owner
supplies a budget. Storage no longer reads or mutates request attributes.

Verification covers deterministic elapsed time, independent attempts, early
failure before ledger/storage work, real S3 command options through a fake AWS
transport, and the actual project HTTP route forwarding bounded write options and
returning the existing retryable 503 response. These tests do not contact storage
providers or prove a live network latency bound.
