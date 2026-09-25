# Course-code redemption boundaries

## Owners

- `CourseCode` stores the code and its relationships, protects its authoring
  contract after first use, and resolves historical display targets. It does not
  enroll learners, create financial receipts, dispatch notifications or report revenue.
- `CourseCodeEligibilityService` reads admission rules for preview and redemption.
  It returns a `CourseCodeRejection` or no rejection and never writes.
- `CourseCodeRedemptionService` owns the operation and its transaction. It locks
  the learner, code and target course in that order, then repeats all admission
  checks against current rows. A preview is not an admission reservation.
- `CourseCodeReceiptService` writes or repairs the zero-value order and bill
  inside the redemption transaction. It never charges a wallet, refunds a
  purchase, or grants paid-plan capabilities.
- `CourseCodeController` validates HTTP input, maps expected domain rejections
  to the existing API contract and presents access from `CourseEntitlementService`.
  Usage history is not authority to access a course.

## Transaction and replay

Usage, durable institutional-grant identity, quota, receipt, enrollment and the
new-enrollment inbox item commit together. Storage failures roll them all back.
Push dispatch happens after commit through the existing notification owner;
a broker outage cannot consume a second grant or reverse the committed access.

Already-effective course access short-circuits redemption without consuming a
code or replacing a paid plan. Existing ineffective enrollment can instead be
re-sourced to a new valid code receipt. Earned completion remains unchanged, paid
capability fields are cleared, and any debt or hold against the old order is not
silently forgiven. The new receipt gives only its own entitlement.

An existing usage with withdrawn access is not a repair authorization. The old
model's usage-recovery branch was unreachable through the public endpoint, which
rejected used codes before calling it. It has been removed rather than exposed
as a way to resurrect withdrawn or reassigned grants. A historical zero-value
receipt without a consumed usage can still be repaired during valid admission.

Only a unique-constraint failure accompanied by a durable grant-identity conflict
maps to `grant_already_claimed`. An unrelated financial integrity failure remains
a server error and rolls back; it must not tell a learner they already claimed a
grant. Rejections use one enum/message vocabulary for preview and execution.

## Contracts retained

- One institutional acquisition per account or normalized original email;
  support reassignment does not clear that identity.
- Restricted domains require verified email; the writer rereads the locked user.
- Legacy partial-lesson codes are history only, never new grants.
- New grants require a published, catalog-visible target and cannot redirect a
  stale course-specific purchase request to another course.
- Zero-value receipts contain code IDs, not redeemable code secrets. Request
  IP and user agent are persisted only as fingerprints.
- No forwarding methods remain on `CourseCode`; callers use the actual owner.

## Verification

`CourseCodeRedemptionOwnershipTest` exercises API parity, retries, rejection
without writes, financial/inbox/broker failures, rollback, receipt reuse,
withdrawn access, fresh email checks and request-context privacy.
`CourseCodeRedemptionSchemaTest` uses the production migration chain and real
commits, including bill uniqueness, foreign keys, durable grant identity and
financial holds. SQLite explicitly drops three retired tenant columns that its
legacy migrations skip but MySQL already removes. This is not evidence of native
MySQL lock/concurrency behavior; those guarantees still require that database.
`CourseCodeEndpointTest` requires actual successful results, not merely a status
other than 404. Existing learning/entitlement/privacy suites cover the consumers.
