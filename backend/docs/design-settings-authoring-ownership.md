# Identity and artwork authoring

`AdminDesignSettingsAuthoringService` owns the singleton design document,
video-link normalization, uploaded artwork references, version checks and receipt
completion. `DesignSettingController` validates HTTP fields/files and maps results
to the existing view and redirects. `DesignSettingsEditorVersion` preserves the
full content hash, including same-second changes.

Images are staged before the database transaction with the shared orphan ledger.
The transaction takes the `design_settings` singleton lock, reads the current
version, saves the new references, schedules reference-aware cleanup of replaced
images and completes the receipt. Previously old-file cleanup happened after the
save transaction, leaving a crash window where replacement succeeded but cleanup
was never recorded. Cleanup now rolls back together with a failed receipt.

Only actual uploaded editor files may replace artwork URLs. Existing untouched
images remain unchanged, external URLs are not treated as local storage keys,
and shared files remain protected until the last reference is removed. Physical
deletion remains the existing cleanup worker's responsibility.

Added application coverage is in `AdminDesignSettingsAuthoringOwnershipTest`;
`AppArtworkManagementTest` continues to cover the HTTP/public-settings/level
fallback integration. See the repository README for local verification evidence
and limits.
