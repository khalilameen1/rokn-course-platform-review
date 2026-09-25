# Saved collections ownership

The saved-library transport and persistence code has separate owners. The public
exports in `courseLearningApi.ts` and `savedCollections.ts` remain the same
functions, not an extra forwarding service or a second implementation.

## Owners

- `savedCollections.ts`: authenticated server commands, duplicate-command
  suppression, response acknowledgement checks and saved-state reconciliation.
  It coordinates a confirmed write but does not implement local storage reducers.
- `savedFolderIndex.ts`: folder response validation/mapping, folder index reads,
  offline fallback, serialized index writes and the shared read revision.
  Membership changes invalidate that revision and mark affected totals unknown;
  they must not guess a second increment/decrement against a server snapshot.
- `watchLaterFolder.ts`: finding/creating the default folder and serializing its
  persisted destination hint. A hint is neither membership nor access authority.
- `savedMembershipCache.ts`: repairing page caches and player membership after a
  confirmed command, and applying an authoritative read to queried lessons only.
  It makes no HTTP requests and does not create folders.
- `savedCollectionCacheRepair.ts`: bounded best-effort waits after a confirmed
  server write. A storage failure does not turn an accepted server mutation into
  a retryable mutation. Account-boundary failure still propagates.
- `savedCollectionConcurrency.ts`: shared flight lifetime and account/epoch keys.
  The owning operation supplies its own map, so folder reads, commands and
  default-folder resolution do not accidentally join one another.

## Preserved ordering and contracts

1. The server acknowledges a mutation before its local caches are repaired.
2. A committed folder/membership change invalidates pending reads. An overtaken
   folder read retries using its original fresh-only or offline-capable policy.
3. Index writes remain serialized per account even after the caller's wait times
   out. Timing out is not permission to release a native write queue.
4. Independent page/player repairs start without waiting for one another.
5. A saved-state read checks its revision again inside the player write callback.
   A newer mutation must not be overwritten by a previously queued response.
6. Removing one membership keeps a bookmark that remains in another known folder.
   Removing everywhere and deleting a folder keep their existing distinct rules.
7. All routes, acknowledgement shapes, storage keys and public function signatures
   are unchanged. This work does not migrate or delete stored user data.

## Verification

The existing integration tests still exercise the public entry points, including
offline folder metadata, stalled native storage, read/mutation ordering, account
changes, focus changes and picker defaults. They were not replaced by mocks of
the new owners.

`savedFolderIndexOwnership.test.ts` exercises the index with mutation orchestration,
default-folder creation and player persistence forbidden as dependencies.
`savedMembershipCache.test.ts` exercises actual cache reducers and queue guards
with the HTTP client forbidden as a dependency.

## Screen ownership

- `Profile/saved/useSavedLibraryRead.ts` owns focus/account generations, loading,
  pagination, selection and retry. Commands receive an operation scope with two
  explicit checks: whether their original view is still current, and whether
  the original account is active on a newer view that should be refreshed.
- `SavedLibrarySnapshot.ts` owns the in-memory rows and folder totals. A mutation
  gets a rollback receipt rather than copying arrays and count-version rules
  into each callback. A newer server total wins over an older optimistic delta.
  Receipts settle once and cannot restore data after clearing the view owner.
  React observes a coherent snapshot through `useSyncExternalStore`; the model
  has no transport, storage or React dependency.
- `useSavedLibrary.ts` coordinates user commands, confirmation dialogs and their
  busy/error states, and derives the displayed groups. It does not own a second
  copy of the rows, counts or pagination generation.

`savedLibrarySnapshot.test.ts` checks rollback, overlapping mutations, refreshed
counts, stale count responses, owner resets and snapshot immutability directly.
The pre-existing full-hook lifecycle tests continue to exercise the new owners
together. An additional regression test confirms the folder editor clears when
accounts change without remounting the screen; previously its unsaved name and
open state survived while the other account-owned state was reset.

This is not a claim that the repository-wide cleanup is complete.

Verification here is local automated verification, not a device or store build.
