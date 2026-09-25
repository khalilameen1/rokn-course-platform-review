# Dashboard reward configuration

`CoinEarningMethodController` owns HTTP validation/normalization, views and
redirects. It supplies the validated editor version separately from writable
fields. It does not open configuration transactions or implement reward funding
checks. Route authorization, MFA and create-intent middleware are unchanged.

`AdminRewardAuthoringService` owns task/rule creation, edits, deletion and global
reward settings writes. It takes normalized values, never a Request. It retains
the settings singleton lock, row locks, three transaction attempts, indivisible
reward caps, explicit zero-cap disable behavior, recommended-provider supplement
and commercial-floor check when the promotion percentage changes. Task destination
readiness is checked here against the locked current task; an inactive task can
still be saved even if its old destination is unusable.

Creation accepts a completion callback owned by the HTTP adapter. The callback
runs after the row is saved but before the configuration transaction commits.
Failure rolls back both configuration and receipt writes. This preserves the
existing create-intent replay flow without making the application service know
about request objects, route names, sessions or redirects.

`RewardConfigurationVersion` calculates the same ordered SHA-256 fingerprints as
the previous controller helpers. Views and locked writes use this single reader.
It never loads or changes database rows. No schema, editor field names, reward
amounts, API response shapes or learner payout flow change in this separation.

The service's values are internal validated inputs, not a second unvalidated
public endpoint. The controller still rejects invalid field formats, unknown
events and automatic task action keys, and normalizes event-specific intervals.

## Verification

`AdminRewardAuthoringOwnershipTest` exercises request-free creation, transactional
receipt completion/rollback, retry, persisted-row stale edit/delete rejection,
caps before receipt completion, settings isolation and read-only fingerprints.
Existing configuration, concurrency, daily dashboard, checkout-policy and task
retirement tests continue to exercise the HTTP adapter and learner contracts.
The daily dashboard suite also submits each create request twice through its
actual route and checks that one completed receipt names one persisted resource.

Local SQLite tests verify transaction boundaries and stale-version behavior, not
production MySQL lock scheduling. No deployment is part of this refactor.
