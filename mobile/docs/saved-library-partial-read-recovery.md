# Saved library partial-read recovery

Reference: the shipped open-source Bluesky React Native app uses an inline
recoverable error beside retained content, separately from a full-screen error:
https://github.com/bluesky-social/social-app/blob/main/src/view/com/util/error/ErrorMessage.tsx
https://github.com/bluesky-social/social-app/blob/main/src/components/Error.tsx
Actual adoption: its official Google Play listing shows 10M+ downloads:
https://play.google.com/store/apps/details?id=xyz.blueskyweb.app

This follows the recovery pattern, not a source-code copy. Bluesky's component
depends on its ALF, Lingui and internal layout/button packages. Rokn reuses its
existing native retry notice and StatusView rather than importing those systems.

## Ownership and state

- The saved-lesson read owns the primary content error.
- Folder index/count reads own the partial folder error. A confirmed DELETE
  cannot become a failed-delete or failed-content message just because its
  count refresh failed. Unknown counts stay unknown rather than guessed.
- Successful lesson rows remain playable beside one retry notice.
- Empty successful content keeps its empty state beside a folder warning.
- A primary failure without lesson rows has one full content recovery action.
- A busy inline retry is disabled. Explicit retry requires a fresh folder read;
  normal focus reads retain the existing offline cache fallback.
- Retry repeats reads, never the confirmed delete. Existing account/focus
  generation boundaries remain authoritative.

## API and dashboard

The backend already exposes independent lesson and folder reads in
SavedSectionController and canonical lessons_count in SavedFolderResource.
There is no new field, admin control, persistence or migration for this fix.
No backend/dashboard behavior or existing reviewer contract is changed.

## Verification status

Regression cases are authored in savedFolderNavigation.test.tsx and
savedLibraryFocusLifecycle.test.tsx for partial failure, retained playback,
last-item deletion/count failure, read-only retry and restored cache fallback.
They have not been executed. Runtime tests, build and deployment are deferred
to the user's final combined verification pass. Source review is not a claim
that these runtime scenarios pass.
