# Notification permission recovery

This local change corrects the notification primer after a learner returns from phone settings. A failed account preference write or device registration previously left the primer saying permission was denied, directing an already authorized learner back to settings. Manual activation and settings return now share one outcome handler. Independent source review accepted this unit; the final runtime gate remains pending.

## Reference and reuse

Rocket.Chat separates device permission from server push availability in [its notification troubleshooting saga](https://raw.githubusercontent.com/RocketChat/Rocket.Chat.ReactNative/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/sagas/troubleshootingNotification.ts). [Its device settings component](https://raw.githubusercontent.com/RocketChat/Rocket.Chat.ReactNative/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/views/PushTroubleshootView/components/DeviceNotificationSettings.tsx) directs users to system settings only when device permission is disabled. [The company reports more than 12 million platform users](https://www.rocket.chat/company/about-us); this is adoption evidence for the platform, not a mobile download count or a performance measurement for this change.

The reference informs the separation of recovery responsibilities. No Rocket.Chat source was copied and no matching primer plus account activation adapter was found. The original Rokn integration reuses the installed Expo notification permission implementation, React Native Linking and AppState, the existing account preference write queue and push registration owner. It adds no dependency, API endpoint, financial operation or second notification delivery system.

## Recovery contract

- `onEnable` resolves false only for OS permission denial and rejects for activation failures. Settings preference storage and remote write failures propagate to the primer after the existing rollback, without a second native alert. The Reels nudge also rejects a failed local write before remote activation and rejects a retired presentation rather than labeling it OS denial. Device registration failure remains a failed activation after the existing compensating disable attempt. A compensation failure never reports successful activation.
- Initial press and a real settings return use the same activation handler. A denied result offers phone settings; a rejected result offers retry; success closes only the presentation that initiated it.
- Settings launch completion is not proof of return. The primer waits for inactive or background followed by active. Repeated active events and repeated presses cannot duplicate the in-flight activation.
- Returning activation uses the existing busy presentation and disables actions while its physical request is pending. Hiding the modal retires presentation authority but cannot cancel OS permission or captured-account settlement. Reopening waits for the old flight to finish, ignores its success or failure for the new presentation, then permits a new explicit activation.
- Failure to launch phone settings has a separate message and a settings-opening retry. A late launch result cannot overwrite activation already started after return.
- The approved layout, welcome artwork, marketing choices and notification audience remain unchanged. Both Settings and Reels use the same primer. Backend `ProfileController` still saves `notifications_status`; `NotificationDeliveryPolicy` separately checks device delivery preference and marketing eligibility. The dashboard notification panel still requires both enabled preference and a registered device before showing phone notifications as enabled. No backend or dashboard edits were needed for this presentation defect.

## Deferred verification

The independent reviewer accepted the source after the Reels caller checked local storage success instead of treating an unsaved opt-in as activation. A follow-up source review also accepted the real guest nudge plus primer binding. This is acceptance of source coherence within this unit, not proof of executed tests, OS settings return, native appearance, notification delivery or completion of the overall project goal.

Authored but not executed: real primer settings-return denial versus failure, shared manual retry, busy and duplicate prevention, settings launch failure and late launch result, hide and reopen with pending success or failure, retired settings return, unmount, unsupported devices. The Settings suite binds the real preference hook to the real primer for storage, server and device registration failures after settings return, verifies rollback without stacked alerts, and then successful retry. Its existing rejected-enable scenario now consumes the intentionally rejected activation promise. The Reels nudge suite separately covers actual OS denial without writes, failed local storage without remote activation and a successful retry, and a retired presentation without a permission request. A real nudge plus primer binding covers a guest returning from settings before the foreground hook updates, failed opt-in storage producing retry without scheduling or remote calls, and successful retry closing the primer.

Before release, the combined gate must run these cases with the existing Settings and reminder suites, then exercise Android and iOS permission rejection, settings return, registration/network failure, retry and account change on devices. Tests, lint, typecheck, native build, commit, push and deployment have not been run for this unit. Source review cannot prove native rendering or actual notification delivery.

## Final gate — Settings binding, 5 October 2026

The complete Settings write-ordering suite now passes all 86 cases. Its fixture
selects the real native choice row by its accessible name and radio role, and
the real primer button by its role and existing text. React Native exports a
memoized Pressable whose export identity is not the rendered row's type.
The preset's existing Linking mock also needs its call history cleared before
each parameterized settings-return case; restoring spies alone does not clear
that history. The expected single settings launch per case remains unchanged.

No application code, assertion, timeout or test case was removed or changed
to accommodate these fixture corrections. Independent review accepted this
limited correction, and ESLint passed for the changed Settings suite.
Evidence: `mobile/.cache/final-gate-20261005/mobile-settings-accessible-control-final.log`.
This does not prove native OS permission delivery, device appearance or release
readiness; the standalone primer and Reels binding gates remain separate.

The standalone primer suite subsequently passed all 11 cases, including busy
actions, settings return, denied versus failed activation, late launch results,
hide/reopen, unmount and unsupported devices. Its fixture uses the same actual
button-role/text selector and resets the native Linking mock's call history
before each case. No application code or expected outcome changed. Independent
review accepted this limited fixture correction.
Evidence: `mobile/.cache/final-gate-20261005/mobile-primer-native-control-final.log`.
Native permissions and notification delivery still require device acceptance.

The Reels reminder ownership suite also passed all 26 cases after its real
guest-primer binding selected the actual accessible button rather than the
memoized export type. Independent review accepted that single selector change.
Failed guest storage still schedules nothing and registers no server device;
the explicit successful retry schedules once and records one dismissal. The
native AppState return still precedes the mocked foreground hook update.
Evidence: `mobile/.cache/final-gate-20261005/mobile-reminder-native-control-final.log`.
The 86 Settings, 11 standalone primer and 26 Reels cases were run as separate
complete suites, not as a new full-project gate or live device acceptance.
