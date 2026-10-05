# Account save results after leaving the editor

The local account editor separates accepted account writes from the screen allowed to present their outcome. Saving can finish after departure without popping another route or showing an old alert. A reopened editor follows a newer committed account revision while preserving its newer text and selected image. Independent review accepted the source and authored coverage after correcting foreground ownership, reopened editor settlement and overtaken reads. This does not establish runtime or release acceptance.

## Reference and reuse

[Rocket Chat ProfileView](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/views/ProfileView/index.tsx) updates global account state and resets the form on accepted saving, and resets the profile form on focus. [Rocket Chat reports more than 12 million platform users](https://www.rocket.chat/company/about-us); that figure is platform adoption, not the mobile application's installation count. [React Navigation documents ignoring obsolete asynchronous effects after blur or unmount](https://reactnavigation.org/docs/use-focus-effect/), which supports the ownership rule but is not independent adoption evidence.

This is architectural adaptation, not copied Rocket Chat source. Its profile protocol does not provide a matching adapter for Rokn's private avatar files, secure session queue and revision receipts. Rokn reuses its installed React Navigation, React Redux and React Native controls, plus its existing foreground hook, secure session mutation, draft file owner and profile API. No library, alternate account store, server protocol or save route was added.

## Account and presentation ownership

Saving accepts work only from a mounted, focused, foreground editor. It checks the real secure account and bearer at acceptance and again after asynchronous boundary capture. A retained callback from a departed visit or obsolete account cannot borrow the replacement session. Once accepted, the same-account write is allowed to finish even if the originating screen blurs, backgrounds or unmounts.

Navigation and alerts belong to the originating focus and foreground visit. Returning to the screen or foreground does not revive that permission. Image selection retains its separate editor owner because the native gallery can legitimately take the application out of the foreground. This preserves the preceding [avatar preparation behavior](profile-avatar-preparation.md).

Accepted saving settles the originating form to the server's name, portfolio headline, avatar URL and revision while its controls remain locked. The selected private file is released only after its acknowledged upload or unmount cleanup; the form does not keep displaying that deleted path. A failed request keeps its mounted draft and idempotent retry identity without projecting a late alert over another visit.

## Reopened editors and canonical state

An editor opened before an earlier editor's acknowledgement can initially read the preceding revision. It now observes newer committed Redux account snapshots, verifies their revision, account and bearer against secure storage, and merges only untouched fields against its form baseline. Newer typed name and headline remain; a new private avatar selection remains owned by the new editor. The next explicit save uses the advanced server revision.

An initial cached session is not treated as a completed profile read. Account or bearer replacement resets the baseline and requests current profile data. An older hydration response or boundary rejection cannot overwrite or hide a newer accepted revision that already settled the reopened editor. Existing timeout recovery and the serialized secure mirror retain their actual operation ownership; a timed-out mirror completion does not replay a form reset into newer editing.

## Backend and dashboard contracts

This unit changes no backend or dashboard contract. The editor still posts profile_image, client_request_id, Idempotency-Key and expected_profile_revision to user/profile. ProfileController retains its validation, revision lock, idempotent receipt and image lifecycle. StudentProfileResource supplies the same profile revision and canonical image consumed by the [dashboard student views](../../backend/docs/student-avatar-presentation-parity.md). An acknowledged account save is not discarded merely because its screen closed.

## Verification still required

The authored profileConfirmedSaveLifecycle suite uses the real secure session owner and boundary with controlled API, picker and storage transports. It covers departure and return, background and foreground return, unmount and reopening before acknowledgement, canonical avatar settlement, new text and avatar preservation, an overtaken hydration read, stale callbacks, queued bearer replacement and local mirror timeout recovery. React Redux delivery is explicitly modeled by feeding the actual committed dispatch payload to the test selector; this is not a native navigation or real network integration test. The existing avatar preparation and identity editing fixtures include the foreground hook.

The overtaken read coverage includes both an obsolete epoch and a still-valid current epoch returning the same revision or a network failure. It checks that neither result replaces later text or the adopted private file, and that the next explicit save uses the accepted revision. The native gallery case returns a canonical avatar on successful saving and checks secure account settlement, file release and normal navigation.

Tests, lint, typecheck, native inspection, build, commit, push and deployment remain deferred to the final combined gate. That gate must run the account suites, verify actual navigation and native gallery behavior on Android and iOS, and exercise canonical profile rendering across API and dashboard. The overall application goal remains open.
