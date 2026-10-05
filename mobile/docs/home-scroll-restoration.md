# Home scroll restoration

Home restoration must yield to the learner's current drag. Previously the saved-position read checked for interaction before scheduling an 80 ms restore, but the delayed callback did not check again. A drag in that interval could therefore be followed by a jump back to the saved position. The local change cancels and discards that pending restoration without changing the approved Home layout.

## Reference and reuse

[Rocket.Chat's scroll controller](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/views/RoomView/List/hooks/useScroll.ts) checks the current jump target before performing a deferred corrective scroll. Its [scroll tests](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/f505f82d7bd152c5325b70b42c3c68053a7fa9fd/app/views/RoomView/List/hooks/__tests__/useScroll.test.tsx) include a completed jump superseded by a newer one. Rocket.Chat's [company page](https://www.rocket.chat/company/about-us) reports more than 12 million platform users; this does not establish mobile installation counts or Rokn performance.

That is an architectural reference for revalidating deferred scroll intent, not a ready-made Home storage adapter or the same manual-drag policy. No reference code was copied and no library was added. Rokn continues using its existing React Native ScrollView, Home callbacks, account-scoped device storage, write queue and guest handoff.

## Restoration contract

- The saved position belongs to the Home account identity that read it. Pending positions and the previous identity's latest offset are cleared on identity change.
- Native drag start sets the existing interaction flag, clears the restoration timer and discards its pending position. A later loading transition cannot replay it. Ordinary scroll events still record the learner's current offset for persistence.
- A delayed restore rechecks its timer identity, current account identity, active presentation, loading, search state, interaction flag and ScrollView instance before calling `scrollTo`.
- Off-screen or search presentation does not restore the browse position. An untouched pending position may resume when Home is active and browsing again.
- Missing storage is not converted into an artificial zero position. An actual stored numeric zero is valid. A superseded storage read cannot adopt its result after cleanup.

The actual Home screen already connects `homeScroll.bind`, `handleHomeScroll` and `homeScroll.markUserMoved` through Content. Content forwards scrolling and drag start to its ScrollView. Those bindings remain unchanged.

## Backend and dashboard compatibility

Viewport position is device-local presentation state under the existing `@rokn/home-scroll/v1` account scope. This unit does not change a server-owned progress value, a learning entitlement, payment, dashboard authoring or any API contract. No backend or dashboard edit is needed to cancel a local stale scroll callback.

## Verification status

Independent source review accepted the viewport logic and the follow-up activity check before guest query adoption and handoff consumption. The reviewer found no remaining blocker within this unit, including completion after cleanup during a deferred handoff write. This is source acceptance only. `homeScrollRestoration.test.tsx` has eleven authored cases, not executed results. The fixture uses the real hook with a React Native ScrollView and invokes its drag and scroll callbacks. It observes the imperative `scrollTo` method on the controlled ScrollView instance; storage and account-boundary transports are mocked. It is not a full Home journey, a native measurement test or proof of real storage performance.

The cases cover one untouched restore, dragging before the timer, dragging before storage settles, loading completion after interaction, off-screen pause, search pause, account replacement, absent storage, actual zero, unmount and a guest handoff whose write settles after leaving. The final combined gate must run them and check the actual Home experience on a phone under delayed storage and loading. Tests, lint, typecheck, build, commit, push and deployment remain deferred.
