# Support case boundaries

- SupportCaseAccessService owns deterministic guest credentials and viewer authorization.
  The HTTP controller extracts and bounds X-Support-Access; services do not read a request.
- SupportCaseReadService owns the customer timeline, status mapping and signed attachment links.
  Reads exclude internal notes and unsanitized attachments, and do not resolve the case writer
  or screenshot processor. Existing response fields and link lifetimes are preserved.
- SupportCaseScreenshotService owns the original-file fingerprint shared by the initial report
  and reply receipts, JPEG sanitization, staged bytes and attachment admission.
  Its cleanup dependency is explicit. A committed orphan ledger precedes byte writes; each
  failed admission retry gets a fresh path. The message transaction attaches the staged file.
- SupportCaseService owns message and staff-state mutations, optimistic versions, replay
  checks, events and notification intents. Staff state edits receive validated values and an
  explicit actor ID, not the ambient authenticated request. An already-applied state retry
  remains a no-op; genuinely stale edits are rejected against the locked row.
- SupportCaseCompensationService owns linking case resolution to a verified order credit.
  It retains user/order/case lock order and version/ownership checks. OrderLifecycleService
  remains the authority for compensation eligibility, refundable amounts and financial
  events. The case event/version and credit commit or roll back together.
- SupportCaseAttachmentDeliveryService is the shared byte-integrity gate for API and
  dashboard attachment delivery. Controllers retain case scoping, route authorization,
  signed-link requirements and response headers. The gate rejects unsanitized/missing files
  and marks digest-mismatched attachments corrupt; it does not issue access credentials.
- SupportCaseSubmissionService owns initial report admission, its stable content fingerprint,
  context ownership and resumable first-message admission. It takes validated case values,
  a user, an optional screenshot and already-derived telemetry, not a request. The HTTP
  adapter still validates fields, derives private request fingerprints, resolves known release
  headers and renders the receipt. SupportCaseSubmissionResult carries the report, replay
  marker and optional guest credential without serializing an HTTP response.
- SupportCaseService also owns claiming a guest case. Both the explicit claim endpoint and
  signed-in submission replay use the same user/case locking, in-lock viewer authorization,
  guest-token revocation and single claim event. A case already owned by the same user is a
  no-op; another account cannot claim it. Replay recovery after a unique insert race also
  checks ownership, not only the request fingerprint.

No compatibility forwarding methods remain in the writer. Existing fingerprints and token
derivation are unchanged, so accepted receipts and guest credentials continue to work.

SupportCaseOwnershipTest exercises read-only queries, HTTP list/show without writer resolution,
internal-note filtering, guest headers and claiming, multipart screenshot creation/replay,
sanitized dimensions and digest, signed-link tampering/expiry, staff replay and image failure.
LearnerAttachmentAdmissionStorageRetryTest retains the cleanup/retry interleaving with real
database admission. External storage is a test disk; these tests do not prove live provider behavior.

SupportCaseAdministrationOwnershipTest adds direct state/replay, compensation transaction and
attachment-integrity coverage alongside the existing staff route replay and customer
signed-link tests. See the repository README for local verification evidence and limits.

SupportCaseSubmissionOwnershipTest adds guest receipt replay, fingerprint compatibility,
resuming an admitted case after first-message failure, claiming/revocation and context
ownership. Case admission intentionally commits before screenshot/message admission so the
tracked-file ledger can precede physical writes; retries finish the same case rather than
creating another. The same local verification record covers these admission checks.
