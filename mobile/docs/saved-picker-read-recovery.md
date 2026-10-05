# Retry saved-folder reads in the reel picker

## Everyday defect

`useSavedFolderPicker.open` reported a folder-index read failure using the same
string as a creation failure. `FeedSideBar` showed only that string. The student
was told to retry but had to dismiss and reopen the sheet to get another read.

## Proven reference and reuse boundary

Bluesky's published React Native inline `ErrorMessage` pairs the failed read with
a retry action in the current surface:

- https://github.com/bluesky-social/social-app/blob/main/src/view/com/util/error/ErrorMessage.tsx
- https://play.google.com/store/apps/details?id=xyz.blueskyweb.app — 10M+ downloads observed on 2026-10-04

This adopts that recovery pattern, not Bluesky's ALF/Lingui-dependent component
code. Rokn reuses its existing Gorhom sheet, native Pressable, typography tokens,
account-scoped folder read and single-flight/cache pipeline. No new dependency
or competing reader is introduced.

## Current source behavior

- Separate load and creation errors keep retry attached to the failed operation.
- The visible retry loads fresh folders inside the same sheet visit without
  presenting again, clearing the name, creating a folder or saving a lesson.
- Synchronous read coalescing prevents repeated taps from starting extra reads.
- The failed-read notice remains visible and busy while retrying; read success
  removes it, while another failure preserves the existing list and draft name.
- A retry cannot retire an active folder creation. Closing, changing the lesson
  or account, and unmounting retire callbacks/results through the existing owner.
- A normal reopen still permits the existing offline cache fallback. Explicit
  recovery requests a fresh read instead of treating old cache as fresh recovery.
- Watch-later saving and creation retry keep their existing contracts.

## Backend and dashboard

The picker calls the existing authenticated `GET saved-folders` route and
`SavedSectionController.getFolders`, backed by `SavedLibraryService.folders`.
Retry does not call any POST or change pricing, entitlements, authoring or schema.
The admin does not own personal folder-index presentation, so no dashboard/API
change is required for this defect. Previously approved controls remain intact.

## Deferred verification

Authored hook regressions cover in-place retry, one fresh request, retained draft
and folders, repeated failure, creation ownership, and a retired visit. The
FeedSideBar presentation regression checks the visible retry and busy state.
No tests, typecheck, native run, build or deployment executed for this unit.
Independent source review on 2026-10-04 accepted this scoped recovery unit with
no concrete blocker. This is source acceptance only; final batch verification
must still cover real folder storage/network and the native sheet lifecycle.
