# AI usage accounting ownership

## Responsibilities

- `AiEntitlementBudgetService` owns admission and the reservation lifecycle:
  reserve paid entitlements or platform-funded relevance review, release unused
  reservations, detach old requests at entitlement replacement, and reclaim
  expired leases. It no longer exposes settlement methods or settlement outcomes.
- `AiUsageSettlementService` owns provider-cost recording, learner usage debit,
  active-account settlement/replay and unknown-result settlement. It does not
  resolve current catalogue terms, payment-risk admission or a provider client.
- `AiProviderExposureService` owns the unknown-outcome count/window/cooldown and
  threshold signal. Its mutation entry point requires the caller's aggregate lock.
- `AiUsageCost` is the shared non-negative USD micro-unit conversion. Reservation
  and settlement do not maintain competing numeric/rounding implementations.
- `PaidAiCallExecutionService` still owns the durable provider-attempt lease and
  success landing. Its recovery sweep accepts the settlement owner directly.
  Unknown-result accounting now belongs to the settlement service, not this
  provider-attempt service.

Jobs, account deletion, project review, chat cancellation and the recovery command
call the appropriate owner directly. There is no compatibility settlement proxy
left in the budget service. Job constructor payloads and persisted state names
were not changed.

## Transaction and recovery invariants

- The same row-lock order and transaction boundaries are retained. Settlement
  writes the usage aggregate, immutable cost event and durable internal signal
  in one database transaction. Signal storage failure rolls them all back.
- Replay checks remain under the active-account and event locks. A settled reply
  cannot debit a second message or emit a second settlement signal.
- Missing provider usage falls back to the reservation. Explicitly reported zero
  cost remains zero. An undelivered answer records platform cost without spending
  the learner's message/token/cost allowance.
- A provider result that already landed is preserved by lease cleanup for
  recovery, not replaced by an unknown result or a second provider call.
- Entitlement replacement detaches provider-started reservations from the old
  aggregate. Their late cost is retained without debiting the new allowance or
  retaining/presenting a superseded private answer.
- `finalizeLockedUnknownOutcome` is the cleanup-only event writer after aggregate
  release. The caller must own the event lock and surrounding transaction. It
  deliberately does not release the same aggregate a second time.

## Internal signals

The dependency audit found that generic signal storage used to construct course
completion services even for an AI-cost event. `InternalSignalService` now owns
only payload normalization, idempotent storage and after-commit dispatch.

`LearningAchievementSignalService` owns the earned course revision and captured
reward contract for `course.completed` and `project.passed.first_reward`. The
course-completion and project-passage callers use this owner explicitly; ordinary
financial, AI and notification events use generic storage directly. Existing
revision grandfathering, reward snapshot replay, signal keys and queue routing
are unchanged.

## Verification

`AiUsageResponsibilityBoundaryTest` blocks unrelated container dependencies and
exercises replay, known-zero cost, signal-failure rollback, landed-result expiry,
unknown-outcome cooldown and late results after entitlement replacement.
`AiUsageCostTest` covers shared precision. `InternalSignalOwnershipTest` verifies
generic storage independence and immutable reward-contract capture. Existing
chat, report-recovery, account-deletion, learning and financial suites exercise
the callers without weakening their behavioral assertions.

Local automated tests are not proof of a deployed worker, real provider call or
store review. This change does not modify schemas, published endpoints, pricing,
plan benefits or the reviewed mobile artifact.
