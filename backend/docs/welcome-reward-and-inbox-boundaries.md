# Welcome rewards and direct learner notifications

## Ownership

- `WelcomeRewardOfferService` is the read-only owner of the configured welcome amount, recommended-provider supplement and indivisible reward-wallet ceiling. Login discovery, the landing page, generic guest-message variables and the grant action read this owner directly.
- `WelcomeRewardService` owns the one-time financial action: identity tombstone check, learner lock, immutable ledger identity, legacy audit protection, wallet credit, audit and welcome receipt in the same transaction.
- `StudentNotificationService` owns direct inbox admission, localized template rendering, committed presentation and recoverable after-commit push dispatch. It cannot quote or credit a welcome reward. Its dependencies are explicit constructor arguments.
- `StudentNotificationIntent` carries named immutable authored fields. It does not access storage or render templates. Direct-user delivery remains separate from bulk campaigns, which have audience selection and different replay semantics.

The old notification-owned `sendRegistrationBonus` and `registrationBonusOffer` entry points were removed, not retained as forwarding adapters. Their callers resolve the actual owners. All direct notification producers use the typed input; there is no positional compatibility overload.

## Financial invariants

The durable welcome identity remains `registration-bonus:<user id>`. A consumed acquisition identity or a legacy audit without a corresponding wallet ledger entry must never receive a second credit. The earning-method row is an audit reference, not a second amount source. A disabled/missing audit method does not replace the configured reward rule.

The configured offer is indivisible. The offer reader rejects an amount above the wallet ceiling, while the wallet writer also checks current available room under the learner lock. A rejected grant creates no claim receipt. Existing successful ledger amounts, not today's offer, supply replayed audit and notification amounts.

Credit, audit and inbox receipt share the login transaction. Receipt-storage failure rolls back the credit; outer login rollback removes all three and cancels deferred pushes. Push-broker failure after commit cannot undo the reward. The durable inbox remains available to the existing stalled-push recovery command.

## Presentation and delivery

The welcome receipt's dashboard template key is `welcome_bonus_received`, but its persisted notification type remains `coins_claimed`. They are intentionally distinct. Disabling the receipt template does not disable the financial reward. The dedicated receipt operation runs under the grant owner's transaction and learner lock; ordinary direct notifications independently recheck the current recipient and notification permissions.

Direct delivery is idempotent per learner and delivery key. A replay retains the first committed copy and presentation and does not send a second push. Explicit authored links/images keep their existing precedence; configured template variables and actions continue through the existing renderer. No database schema, mobile response shape, stored delivery key, push-job constructor or review-server state changed.

Guest engagement messages previously calculated their own welcome amount without the wallet ceiling. They now read the same capped offer as discovery and the grant action. Explicit variables such as a historical receipt's actual credited amount still take precedence over the current offer.

## Verification

`WelcomeRewardOwnershipTest` covers provider amounts, one-time credit, the separate welcome template, disabled-template replay, immutable ledger amounts, legacy audits, inactive audit methods, configured caps, current wallet room, receipt failure, outer commit/rollback, broker failure and read-only capped guest messages.

`StudentNotificationIntentWorkflowTest` covers distinct bilingual fields, first-receipt replay, per-user delivery identities, long/generated keys, disabled templates, template variables/actions, stale recipients, marketing preference boundaries and rollback. `StudentNotificationIntentTest` verifies authored input and immutability. Existing authentication, payment, engagement, support and notification suites exercise the migrated producers.

These are local automated checks, not evidence of live device delivery or deployment.
