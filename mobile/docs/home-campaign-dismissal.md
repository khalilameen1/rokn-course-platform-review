# Home campaign dismissal — local source, 4 October 2026

## Proven reference and actual reuse

[OneSignal's adoption report](https://onesignal.com/blog/record-breaking-momentum-and-exceptional-growth-in-2019/)
records more than 10,000 companies using its In-App Messaging in 2019. This is
historical real-use evidence, not a current customer count or measured Rokn result.
Its public [InAppMessagesManager](https://github.com/OneSignal/OneSignal-Android-SDK/blob/main/OneSignalSDK/onesignal/in-app-messages/src/main/java/com/onesignal/inAppMessages/internal/InAppMessagesManager.kt)
persists dismissed message IDs locally in `messageWasDismissed`, independently
of click/impression network requests. This separation is the reference principle.

This is architectural inspiration, not copied OneSignal code or SDK installation.
No matching provider-independent implementation of Rokn's account-scoped Home
receipts was found. The small integration stays inside the existing hook. Actual
reuse is the installed AsyncStorage-backed `saveItem`, existing account storage
keys/session boundaries, existing authenticated `markNotificationRead` endpoint,
and launch presentation owner. No new library, queue, table or delivery service.

## Two independent receipts

Closing or opening the presented campaign clears its visible card and consumes
the presented boundary immediately. The course CTA does not await disk or HTTP.
A captured-account local write records the existing Home seen key without waiting
for the server read ACK. A separate request retains the existing inbox mark-read
behavior; it is not blocked by a failed/stalled local key or write. Either failure
cannot cancel the other operation or promote the next popup in the same launch.

A failed HTTP read may leave the ordinary inbox unread. That is truthful server
state, not a reason to re-present this Home card on the same installation after
its local receipt was persisted. No offline read is reported as a server success,
no durable retry outbox is manufactured, and inbox reading remains independently
available through its existing UI. If both persistence and HTTP fail, durable
suppression cannot be promised; one-card-per-launch still prevents a popup loop.

The receipt key is captured for the presented account, not recomputed for the
current user after I/O. Session retirement before the key resolves prevents its
write. A native write already started can finish only on its old scoped key;
completion has no navigation, campaign-state update or replacement-account write.
An old/read completion does not start persistence. Repeated retained callbacks,
stale epoch presses and unmounted presentations keep the existing retirement.

## Backend and dashboard consistency

The backend's authenticated ownership check and idempotent notification mark-read
remain unchanged. Dashboard delivery, audience preferences, current course cards
and general/v60 inbox shapes remain unchanged. Local dismissal is an installation
presentation receipt, not global campaign deletion, enrollment or a financial
mutation. No new dashboard toggle is required for this ownership correction.

## Deferred acceptance

Authored, not run: failed/pending HTTP with persisted dismissal across a fresh
launch, failed/stalled disk and key with independent HTTP/CTA, save helper false,
both failures without a false durability promise, refused navigation, repeated
callbacks, stale epochs/account replacement, delayed key retirement and a native
write completing only under its captured account key. Existing one-launch-slot,
selection pagination, opt-out and welcome-reward suites remain final-gate work.

No tests, lint/typecheck, build, commit, push or deployment ran for this unit.
Final acceptance must exercise the signed app with a presented card, disconnect
the network, dismiss/relaunch, and inspect the same account's inbox separately;
repeat account replacement and failed-storage behavior. Source review is not
runtime proof or acceptance of the whole active goal.

Independent source review accepted this unit: no remaining practical finding
within its dismissal/receipt ownership scope. The reviewer inspected current
source, authored cases and backend/dashboard boundaries without executing tests
or modifying files. This scoped acceptance does not close the device gate above
or any other inventory unit.
