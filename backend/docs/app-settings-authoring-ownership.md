# App settings and provider cleanup review

`SettingsController` owns HTTP validation, authentication context, redirects and
the settings-page view. `AdminAppSettingsAuthoringService` owns normalization and
atomic persistence of settings, public social links, encrypted provider keys,
global plan policy and device-lock changes. Those are one application operation,
not independent saves that can leave half of the configuration applied.

The writer acquires the settings/design singleton locks and compares the editor
version against freshly loaded rows. `AppSettingsEditorVersion` preserves the
existing ordered hash contract, including provider ciphertext revisions without
exposing the secrets. Both reads and writes use this owner rather than a private
controller method accessed through reflection.

Provider keys are removed from the request immediately after field validation,
before domain validation of plan policy or social links. This keeps failed forms
from flashing keys back into the session. The validated write payload retains them
only for the encrypted settings update. Empty key inputs preserve existing keys.
An incomplete three-tier policy produces a validation error instead of reading
missing array keys. Existing tier limits and normalization remain enforced.

`AdminBunnyCleanupReviewService` owns individual and bulk video cleanup approval.
Both use one reference check and one approval writer under the candidate row lock.
Single approval reloads current deletion state rather than trusting route binding.
Bulk approval skips already reviewed/deleted rows and orders locks by ID. Neither
entry point deletes media or dispatches remote work: retention and the cleanup
worker's later reference check remain in force.

New settings and review ownership tests cover stale editors, transaction rollback,
secret old-input exclusion, selected-row isolation and referenced media retention.
These passed local verification with the existing dashboard, configuration
concurrency, authorization and Bunny workflow tests, including the corrected
cleanup-reference fixture. See the repository README for evidence and limits.
No live provider verification is implied by the refactor.
