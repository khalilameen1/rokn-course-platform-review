# Reel reminder presentation — local source, 4 October 2026

## Shipped reference and actual reuse

[Mattermost's Android listing](https://play.google.com/store/apps/details?id=com.mattermost.rn)
showed 1M+ downloads when inspected. Its open-source
[push notification owner](https://github.com/mattermost/mattermost-mobile/blob/main/app/init/push_notifications.ts)
uses the visible navigation context for in-app presentation and excludes
call-started messages because that flow already owns its notification. Its
[navigation store](https://github.com/mattermost/mattermost-mobile/blob/main/app/store/navigation_store.ts)
owns the visible screen and modal state.

This is architectural inspiration, not copied Mattermost code or a claim that
its message notifications are Rokn's permission flow. Importing its router,
RxJS store or notification provider would not fit this existing application.
Actual reuse is Rokn's installed React Navigation focus hook, React Native
Modal/AppState, shared foreground hook, account boundaries and existing
notification preference/token services. No dependency or new overlay framework
was introduced. Approved primer styling and copy remain unchanged.

## Presentation and completion ownership

`useReelsProgress` offers the optional primer at the end of an ordinary,
accessible reel-to-reel boundary. The 95-percent evidence checkpoint does not
offer it. The final preview, course end and authored project transition do not
offer it. Completion evidence, project-map refresh and normal autoplay retain
their existing owners; playback never waits for the optional device read.

`useReminderNudge` owns one presentation opportunity per account/course/view
scope. Eligibility reads are single-flight and bound to the completed feed
context. Changing the feed item or paging state retires the pending read,
including leaving and returning to the same item. Covered routes, background,
loading, course revision refresh, chat, preview gate and content overlays cannot
promote an old read. No queue drains when an overlay closes. A later genuine
eligible completion can try again if a prior read failed or was retired.

The presented account boundary owns the dismissal receipt; a stale callback
cannot close a new account's card or record dismissal for that new account.
Once presented, route coverage or a competing decision hides the primer without
queuing its redisplay. A scope-keyed primer instance prevents phase/settings
state from leaking across account/course/view replacement.

Backgrounding into OS permission/settings is different from pushing another
Rokn route. It suspends new opportunities without dismantling an already
presented primer's return-from-settings listener. Explicit activation checks
the native AppState at dispatch rather than waiting for a React foreground
render to catch up.

## Preference settlement and backend/dashboard contract

Activation is single-flight. After the learner answers the OS request, the
existing preference/token operation belongs to the captured account, not the
lifetime of the Reels modal. Same-account navigation cannot stop successful
settlement or bypass compensation of a failed backend/token acknowledgement.
Account replacement still retires subsequent old-account writes.

The existing `notifications_status` profile update, device-token registration,
local preference and reminder scheduling are reused. `ProfileController` and
`StudentProfileResource` keep their current boolean contract;
`NotificationDeliveryPolicy` retains its opt-in and marketing audience rules.
Dashboard campaign controls and server reminder cadence remain unchanged. There
is no new backend field, schema, admin toggle, timing endpoint or entitlement
exception to keep synchronized. Guest opt-in still schedules locally; an
authenticated account still registers its token and uses server cadence.

## Local timer mutation ownership — 5 October 2026

[Loop Habit Tracker](https://play.google.com/store/apps/details?id=org.isoron.uhabits)
showed 5M+ downloads when inspected. Its shipped open-source
[ReminderScheduler](https://github.com/iSoron/uhabits/blob/dev/uhabits-core/src/jvmMain/java/org/isoron/uhabits/core/reminders/ReminderScheduler.kt)
uses synchronized scheduling and checks whether a reminder still belongs to an
eligible habit at dispatch. This is an architectural reference only; no GPL
Kotlin source was copied. Actual reuse is Rokn's existing keyed async queue,
Expo Notifications and native AlarmManager adapter.

Local IDs 8101/8102/8103 are device resources, so their schedule/replace/cancel
mutations share one device queue, not independent per-account queues. Each
public schedule captures a generation before its first asynchronous read.
Cancellation retires that generation immediately, including preparation not
yet admitted to the OS queue. Admission rechecks both account and saved opt-in.
An OS schedule that finishes after retirement is cleaned up within the same
queue slot, before a newer account's timer can land.

Cancellation returns its completion promise. Android acknowledges cancellation
after AlarmManager and stored-reminder removal; Expo matches exact owned IDs,
not suffixes belonging to another provider. Every cancellation in a batch must
settle before the queue releases, even if one fails. The rejection remains
available to awaiting callers; existing fire-and-forget logout callers do not
create an unhandled rejection. Preview IDs remain separate.

No new reminder, UI, backend field or dashboard control was added. Authenticated
learning reminders retain their central server owner; this does not introduce
a second authenticated device timer. Authored native/Expo regressions in
`localReminderMutationOwnership.test.ts` cover read-before-cancel, in-flight OS
completion, account replacement, cancellation failure ordering, dispatch opt-in,
preview/provider isolation and authenticated server-only ownership. They have
not been executed. Native cancellation promises and real OS ordering still
require the final combined gate on rebuilt artifacts.

Independent review accepted this unit at source level after inspecting both
adapters, the queue, receiver/store and callers, and the 14 authored native/Expo
cases. This is not proof of bridge/OS ordering, live push delivery, or recall of
a notification already dispatched by the OS. No tests or builds were run.

## Earlier primer acceptance boundary

Independent source review rejected the first iteration for stale feed context
and presentation-owned compensation, then accepted the corrected ownership and
authored regressions. This is static acceptance, not runtime acceptance.

Authored but not executed: ordinary completion, no 95-percent prompt, final
preview/project/course-end exclusion, covered/blocked/background reads,
single-flight and saved dismissals, feed/paging retirement, account replacement,
OS/settings return, same-account focus loss during token settlement and server
failure compensation, and activation retry.

At the final goal gate run the corresponding mobile suites and existing push,
profile/settings, playback/completion and session-boundary regressions. Accept
the actual Android/iOS permission and settings-return journeys on the matching
artifact/backend, including slow storage, last-preview purchase and project
entry. The controlled AppState/transport fixtures cannot prove native Modal
ordering or live push delivery.

No tests, type checks, native build, commit, push, deployment or live preference
change was executed for this unit. Formatting only was applied to its TS files.
Other goal inventory units and final release acceptance remain open.
