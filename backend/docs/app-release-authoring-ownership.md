# Release authoring

`AppReleaseAuthoringService` is the write owner for dashboard releases and the
explicit direct-Android bootstrap. Both creation paths acquire the same
platform-scoped singleton lock before comparing channel/platform build numbers
and cross-channel display names. A new direct release cannot bypass the rules
used for a dashboard release. Bootstrap accepts only an exact already-active
retry and never overwrites or reactivates an existing record.

Dashboard updates reload and lock the current row, reject stale editor versions
and preserve platform, distribution channel and build identity. Activation still
requires a usable channel-specific URL and identifier. Active releases must be
deactivated before deletion. The receipt callback shares the creation transaction.
`AppVersionEditorVersion` defines the same field ordering as the prior controller.

The controller retains field validation, platform-specific normalization, views
and redirects. The bootstrap command retains environment/channel opt-in guards,
option validation, console messages and exit codes. Neither owns the persistence
rules anymore. `AppReleasePolicyService` remains the read-side channel/link policy;
the model still owns post-commit release/public-settings cache invalidation.

`AppReleaseAuthoringOwnershipTest` covers receipt rollback, shared channel identity,
stale editors, immutable build identity, inactive deletion, incomplete activation,
bootstrap replay, independent iOS numbering and the HTTP delegation. Existing
`AppVersionPolicyTest` keeps the public update and CLI behavior coverage. Local
verification and its limits are recorded in the repository README. No release
has been activated, uploaded or deployed by this local refactor.
