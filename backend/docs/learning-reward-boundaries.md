# Learning reward ownership

`LearningRewardService` admits learning activity and captures its commercial
promise. It owns check-in days/streaks, qualified study slots, first-project and
course-completion identities, grant exclusion, archived-course resolution and
the learner-facing result. A previously captured rule is not repriced from the
current dashboard when settlement retries.

`LearningRewardConfigurationService` is read-only. The public economy endpoint
uses it directly; the activity service no longer forwards configuration calls.
It reads dashboard rules/settings and supplies migration-default caps without
creating a global settings row. The same cap reader is used by settlement.

`LearningRewardCreditService` owns all-or-nothing financial settlement. It locks
the learner, checks the durable reward identity, calculates the rolling window
and current balance room, and credits only the reward bucket through
`WalletService`. It does not register study time, decide whether a project passed,
resolve a course revision, or change the offered reward amount.

## Invariants

- The displayed/captured reward is indivisible. Insufficient room creates no
  partial credit and does not consume the reward identity.
- Daily/study rewards may return no credit until a later request has room.
  Earned project/completion signals can defer through `RewardGrantDeferred`.
- A temporarily blocked achievement retries no earlier than twelve hours and,
  when necessary, after enough rolling credits expire. An impossible contract
  does not defer forever.
- Idempotency keys, categories, source rules, metadata, UTC persistence and the
  configured business calendar are unchanged. Calendar-window expiry still
  respects daylight-saving transitions.
- The activity admission transaction and the credit transaction retain their
  existing boundaries. A captured day/slot can survive a failed credit and be
  settled from its original contract on retry. Outer rollback still cancels
  nested financial writes.
- Settlement receives explicitly named arguments from every activity caller.
  No compatibility financial methods remain on the activity owner.

`LearningRewardOwnershipTest` covers read-only/default configuration, live
dashboard caps, API wiring, frozen credit amounts, replay, whole-award caps,
deferral, impossible contracts, ledger failures, rollback and check-in retry.
`LearningRewardEndpointTest` and `LearningRewardSignalDeferralTest` additionally
cover weekly streaks, frozen study contracts, signal siblings and DST-sensitive
rolling expiry. These SQLite tests do not replace native MySQL concurrency tests.
