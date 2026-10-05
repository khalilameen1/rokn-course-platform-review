# Account avatar preparation and save ownership

The local account editor now shows image preparation and disables both image controls and saving until the native selection and private file copy finish. Cancelling or failing a replacement keeps the previous image. This unit addresses preparation and transfer to saving, not all account editing or release readiness.

## Reference and actual reuse

[Rocket Chat ChangeAvatarView](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/views/ChangeAvatarView/index.tsx) keeps selected avatar data separate from submitting it, renders loading and disabled controls during saving, and treats native picker cancellation quietly. Its adjacent ImagePicker.ts and submitHelpers.ts were also read before implementation. [Rocket Chat reports more than 12 million platform users](https://www.rocket.chat/company/about-us); this is adoption evidence for the platform, not a mobile installation count or a performance benchmark.

This is architectural adaptation, not copied source. The reference uses a different image picker and backend protocol and does not supply a matching private file preparation adapter or the rendered preparation phase added here. That gap was disclosed before implementation. Rokn reuses its installed react-native-image-picker, React Navigation focus hook, existing learner draft cache and cleanup, secure session boundaries, RasterImage and Button. No new library, image upload protocol, avatar screen or storage service was introduced.

## Preparation and saving

- One physical picker or copy flight remains locked until its own completion, including after leaving and returning to the mounted editor. Both image buttons expose disabled and busy accessibility state. A spinner and `جارٍ تجهيز الصورة` explain the temporary save restriction; name and portfolio headline remain editable.
- A focus visit and account identity own the selection. Ownership is checked after capturing the session boundary and after native selection and copying. Returning to the screen does not revive the previous visit. Stale results are discarded and stale errors are silent; account boundary checks also reject replacement credentials for the same account.
- The adopted private file has one synchronous owner in avatarUploadRef. File ownership transfers before rendering and unlocking. Saving freezes that file at acceptance and uses it for the request fingerprint, multipart upload, persistence check and cleanup. A retained save callback cannot upload the preceding image merely because the replacement render has not committed.
- Cancellation and selection failure do not replace or delete the previously adopted image. Replacing it releases the predecessor without waiting for deletion to finish. An unadopted late copy is cleaned up, as is the adopted file when the editor unmounts without an active save.

## Backend and dashboard contracts

No backend or dashboard source was changed for this preparation unit. The mobile API still sends profile_image to user/profile with the existing client_request_id, Idempotency-Key and expected_profile_revision. ProfileController retains its 2 MiB raster validation, decode and re-encode, revision lock, idempotent receipt and deletion service. No request is sent while preparation is pending.

The preparation unit did not rewrite dashboard learner views. A subsequent [student avatar presentation unit](../../backend/docs/student-avatar-presentation-parity.md) now binds the student list and profile card to the API's existing canonical accessor. Actual stored image rendering across mobile and dashboard still belongs to the final integration gate.

The subsequent [account save result unit](profile-save-presentation-ownership.md) separates foreground ownership of save alerts and navigation from the native gallery's editor visit. It also settles the canonical saved avatar and revision after departure or reopening without discarding newer edits.

## Review and verification limits

Independent source review accepted this unit after identifying and closing a stale-render save gap. The reviewer read the final source and authored coverage; no tests were executed.

The new profileAvatarPreparation suite exercises the real editor, Button and secure session boundary with controlled native picker and cache transports. Authored cases cover the picker and copy busy phases, repeated presses, editing text during preparation, cancellation, permission and size errors, failed replacement, leaving before boundary capture, leaving and returning, account and bearer replacement, unmount, late errors, synchronous cleanup ownership and saving immediately after replacement preparation. Existing identity editing and confirmed save lifecycle suites retain their behavior with the added focus mock.

Tests, lint, typecheck, native device inspection, build, commit, push and deployment remain deferred to the combined final gate. Native picker permission recovery, slow copying, font scaling and screen navigation must still be exercised on Android and iOS. Source acceptance is not runtime or store acceptance, and the full project goal remains open.

## Final combined gate — 5 October 2026

The complete avatar preparation suite initially reproduced 13 passes and two
failures: account/bearer replacement reached real secure-session teardown,
where the partial navigation fixture omitted `createNavigationContainerRef`.
It now exposes actual MIT core primitives re-exported by the installed native
navigation package. This preserved the real session owner rather than mocking
replacement away. Independent read-only review accepted this limited correction
at 14 passes / one failure; account replacement still had further fixture gaps.

The shared `expo-notifications` test factory lacked three real SDK exports:
`clearLastNotificationResponseAsync`, `dismissAllNotificationsAsync` and
`setBadgeCountAsync`. They now use the SDK's promise return shapes, without
changing notification retirement or its `Promise.allSettled` source behavior.
The combined avatar/push run had 43 passes / one failure across 44 cases; all
29 push-specific cases passed using their own unchanged native factory. The
remaining account case then reached another missing test export. Independent
review accepted only the notification boundary, not the still-unfinished case.

The avatar file-owner double now exposes `clearAccountLearnerDraftFiles` by
wrapping the real project implementation, not returning a no-op. Its existing
picker/private-copy doubles remain unchanged. Account replacement checks the
actual previous scope, cleanup once and native unlink of only that simulated
scope directory. Same-account bearer replacement checks no directory cleanup.
The account case also now reaches explicit last-response/tray/badge assertions;
all three run once, with badge value zero. Adoption/late-error/save assertions
remain intact. Independent read-only review accepted this unit's actual owner
binding and read the full 15/15 result; it ran no tests itself.

Logs in `mobile/.cache/final-gate-20261005/`:

- `mobile-avatar-preparation-navigation-reproduction.log` — 13/15.
- `mobile-avatar-preparation-navigation-final.log` — 14/15, missing notification API.
- `mobile-avatar-push-native-boundary-final.log` — 43/44, missing file-owner export.
- `mobile-avatar-account-cleanup-final.log` — full avatar suite, 15/15 passed.

These are mocked-native boundary checks, not proof of OS tray/cache deletion,
actual picker permissions, real multipart traffic or Android/iOS acceptance.
No production screen, session, file owner, backend or dashboard behavior was
changed for these fixture corrections. The full release gate remains open;
no build, commit, push, deployment or store write was performed.
