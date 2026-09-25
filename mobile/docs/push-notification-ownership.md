# Push notification ownership

## Owners and callers

- `notificationPresentation` owns foreground presentation and stable Android
  channels. `index.js` configures foreground delivery explicitly before mounting
  React; `useAppRuntime` prepares channels. Neither requires account access or
  registration as an import side effect.
- `pushDeviceRegistration` owns authenticated opt-in, OS permission, token
  registration, token refresh and serialized registration retirement. Settings,
  reminder prompts and app foreground reconciliation call this owner directly.
- `pushNotificationNavigation` owns tap intent, latest-tap precedence, inbox
  lookup, native cold-start recovery and the one durable pending marker.
  Navigation readiness and runtime response listeners call this owner directly.
- `pushAccountCleanup` coordinates the two owners when an account exits or is
  replaced. It contains no routing or token registration rules.
- `pushDeviceState` remains the account-scoped token/tombstone storage owner.
  Callers needing only the stored token use it directly. The old read wrapper
  and the combined `pushNotifications` module have been removed, not retained as
  forwarding APIs.

## Ordering and account isolation

Account cleanup invalidates both owners synchronously, before waiting for native
storage or network requests. An old registration reply cannot restore its token
and an old inbox reply cannot navigate. Registration cleanup waits behind prior
registration mutations. Native tray/badge cleanup can run while that wait is in
progress. A failed durable token retirement stays visible to the caller, but
does not prevent navigation cleanup from running.

All pending-marker writes and removals, including logout, share the navigation
owner's queue. Invalidating an intent does not cancel a native storage write
already underway. Deleting before that write completes can recreate the old
marker after logout. The regression test first reproduced this ordering failure;
teardown now queues deletion after the existing write using the captured account
key. No storage format, route or API contract changed.

An unresolved secure-store bootstrap is not a guest. The native tap is retained
until the session becomes known. A confirmed guest discards remote inbox taps;
a restored account opens only its fetched inbox delivery, not an untrusted push
payload. Account boundaries and latest-tap ownership checks remain in the same
places as before the extraction.

## Verification

`pushNotifications.test.ts` exercises the real owners together with controlled
native/network/storage adapters. It covers Android and iOS registration,
opt-out, invalidation recovery, duplicate/competing taps, account changes,
logout during registration and inbox reads, late marker writes and unresolved
bootstrap. `notificationPresentation.test.ts` checks explicit initialization,
stable Android channels and presentation independence from account/navigation.
Session replacement, logout recovery, settings ordering and runtime checkout
tests exercise the migrated production callers.

These tests verify local behavior. They are not proof of live FCM delivery on a
physical device or a claim that the remaining repository is fully maintained.
