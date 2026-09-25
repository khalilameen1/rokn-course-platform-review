# Media and project service boundaries

## Bunny media

- `BunnyConfiguration` resolves provider credentials and delivery configuration.
  It does not send HTTP requests or own cleanup records.
- `BunnyService` is the remote provider client. Callers inject it for allocation,
  transfer, inspection or deletion, never just to render a protected URL.
- `BunnyDeliveryService` signs playback, embed, thumbnail and private Storage
  URLs using `BunnyConfiguration`. It has no provider-client/cleanup dependency.
  `videoPlayback` is the single HLS signer for configured and explicit TTLs.
- `LessonMediaDeliveryService` projects already-published lesson media state for
  course previews. It requires a matching, reconciled, ready, non-quarantined
  generation and never probes, creates or repairs media state while reading.
- `BunnyMediaRegistry` owns durable cleanup reservations and the publication
  guard. Cleanup-only consumers inject this service, not the remote client.
- `BunnyStoragePath` owns canonical storage-key validation. The client and
  registry must use the same normalization before addressing an object.

A storage upload records its cleanup reservation **before** sending bytes.
Publishing consumes that reservation in the transaction that creates the live
reference. A key whose DELETE was already attempted cannot be reused while its
remote outcome is unknown. Do not move either guard to an after-upload callback.

Legacy `BunnyVideoAllocationIntent` recovery remains necessary even though the
unused buffered/verified video-upload entrypoints have been removed. Recovery
must be able to retire allocations created by interrupted older deployments.

Behavior coverage: `BunnyMediaRegistryTest`, `BunnyStoragePathTest`,
`BunnyDirectUploadLeaseTest`, `BunnyVideoStatusContractTest`,
`BunnyUploadSafetyTest`, `BunnyCleanupWorkflowTest`, plus portfolio replay and
media-delivery contracts. SQLite tests do not prove MySQL lock scheduling.

Read-only consumers (course resources, saved lessons/folders, watch history,
learning dashboard, admin outline and playback manifests) use delivery directly.
Reconciliation, portfolio readiness and restore drills explicitly receive both
the remote client and delivery signer because they need both responsibilities.
There are no forwarding signing methods left on `BunnyService`.

Delivery keeps the existing directory token for HLS relative segments, distinct
embed format, independent Storage key, canonical path rejection and configured
or requested lifetimes. A short explicit HLS lifetime still has the same
600-second floor; the embed lifetime remains independently specified. Existing
public presentation keys and upload/deletion/cleanup behavior are unchanged.

`BunnyDeliveryOwnershipTest` forbids resolving provider/cleanup/reconciliation
services and forbids HTTP while exercising signing and loaded preview state.
It checks TTL bounds, signature formats, missing configuration, Storage/Stream
separation, mismatched generations and source immutability without database IO.
The existing fixed signing vectors and portfolio/reconciliation integration
assertions remain in place, with mocks attached to their actual new owners.

## Project submission files

- `ProjectSubmissionFilePolicy` is the source of effective MIME types, the
  per-project file count and the project/provider size cap. Project reads,
  course payloads, HTTP validation and storage admission all use it.
- `ProjectSubmissionInputService` normalizes input, fingerprints retries and
  checks content/readability budgets before storage. It depends on the file
  policy, never on the submission orchestrator.
- `ProjectSubmissionEffortGuard` evaluates whether the submitted effort is
  meaningful. It does not persist learner progress or allocate paid AI usage.
- `ProjectSubmissionOrchestrator` coordinates the learner action and translates
  preconditions into its public result states. `ProjectSubmissionService` owns
  durable submission/storage work and committed replay resolution.

A project's file whitelist may narrow the globally enabled, supported formats.
An explicit empty whitelist disables files; null inherits the global formats.
The HTTP gate additionally admits raw OOXML container MIME aliases, but actual
content inspection still decides whether the file is genuine DOCX/PPTX. Those
transport aliases must not appear as learner-selectable formats.

Follow-up conversation attachments are not the course's project deliverable.
They keep the conversation's supported formats while sharing the provider size
cap. Do not apply the course's deliverable whitelist to follow-up questions.

Behavior coverage: `ProjectSubmissionFilePolicyTest`,
`ProjectFilePolicyContractTest`, `ProjectSubmissionInputTest`,
`ProjectUploadFailureResponseTest`, submission replay/evaluation tests.
`ProjectFilePolicyContractTest` uses real migrations and checks both the project
endpoint and the course resource against the same authored settings.

## Project submission lifecycle

- `ProjectSubmissionService` owns admission, request-scoped storage, immutable
  evaluation snapshots and committed upload replay. It asks the evaluation
  scheduler to dispatch after a submission is committed, but never records a
  staff/provider review decision.
- `ProjectSubmissionEvaluationScheduler` owns due dispatch and bounded recovery.
  It depends on neither input/upload services nor the provider, paid budget,
  review decisions or file retention. `dispatchIfDue` rechecks learner and
  submission state under the existing lock order; `recoverDue` selects due
  pending evaluations. Neither method can pass a project.
- `ProjectSubmissionReviewService` records a matching provider outcome or an
  authorized staff decision, preserves already-earned progression, records
  durable notification/reward intent and hands off the optional paid report.
  It uses the captured purchased terms and existing entitlement/retention
  owners, not upload admission or provider execution.
- `ProjectSubmissionEvaluationService` executes the relevance review and owns
  paid-call recovery. It sends the outcome directly to the review owner and
  requests retries directly from the scheduler, without resolving submission
  storage. API polling uses the scheduler; dashboard decisions use the review
  owner. There are no forwarding review/recovery methods on the upload service.

The legacy `auto_pass_at` database column is still a recovery deadline, never
an automatic acceptance. The operational command `projects:finalize-pending`
is retained for existing schedules; it requeues work, not decisions. Job payloads,
lock order, request identities, public responses and report/money rules are
unchanged. Queue failure keeps durable intent; rollback cannot dispatch an
uncommitted request. Paid feedback remains an enhancement, not a progression gate.

`ProjectSubmissionOwnershipTest` exercises the new owners with real migrations
while refusing to resolve admission/upload/provider/budget dependencies. Its
scheduler checks additionally forbid resolving reviews/retention/entitlements.
Coverage includes stale models, unavailable learners, terminal states, bounded
recovery, broker failure, rollback, late outcomes and duplicate reward intent.
Existing submission/evaluation, staff access, snapshot, storage retry and learner
flow tests remain behavioral coverage; canonical-state contracts include both
new owners and inspect the actual review owner for immutable certificate claims.

The ownership tests also exposed an indirect dependency: entitlement reads used
`FinancialProvenanceService`, which owns paid credit/reversal operations and AI
reservation cancellation. `FinancialEntitlementHoldReadService` now owns the
read-only hold query. Entitlements, certificates, portfolio upload eligibility
and path progress depend on that reader. Purchase/upgrade actions explicitly
receive the reader and the writer for their distinct responsibilities.
`FinancialProvenanceSchema` keeps the existing three-table readiness check in
one place for both sides. Scope, status and purchased-order matching are unchanged;
no read forwarding methods remain on the financial writer.

`FinancialHoldReadOwnershipTest` refuses writer/budget resolution, checks course
versus current-plan order identity, chat-only and resolved holds, and asserts
that hold reads perform no database writes. Financial reversal/repayment,
entitlement consistency and purchase/upgrade integration tests remain applicable.

## Completion after a plan upgrade

`CurriculumCompletionService` validates all required sections before recording
completion under the enrollment lock. `CourseEnrollment` enforces the record's
one-way transition: watch-only completion may become earned practical
completion after those checks; earned completion cannot be rewritten or
downgraded. The promotion records the practical completion time/revision, not
the earlier watching time, so certificate evidence includes the projects that
were actually passed later.

`WatchOnlyCourseLearningTest` advances the clock between Basic completion and
the upgrade. Do not remove that time gap: completing both actions within one
database timestamp second previously concealed an invalid transition.
