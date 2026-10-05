# Feedback draft departure — local source, final verification pending

## Reference and real reuse

[Rocket.Chat's shipped `useAutoSaveDraft`](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/containers/MessageComposer/hooks/useAutoSaveDraft.ts) combines ongoing editor autosave with a final draft save on departure/unmount rather than only cancelling its timer. Its [draft store](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/lib/methods/draftMessage.ts) retains draft persistence as a separate local authority. [The company reports more than 12 million platform users](https://www.rocket.chat/company/about-us), not mobile install counts.

This is architectural adaptation, not copied source or a claim that Rocket.Chat implements Rokn's guest/support-request contracts. No compatible ready-made adapter for those contracts was found. Rokn actually reuses its existing AsyncStorage-backed feedback draft queue, managed screenshot references and installed React Navigation `useIsFocused`. Rocket.Chat's room/thread database and editor action store were not imported. No second draft store, server submission or navigation confirmation flow was introduced.

## Verified source defect

The new support composer had a 250 ms debounce and a background save, but its teardown only cancelled the debounce. Normal back navigation before the timer ran could lose the most recent text or adopted/deleted image. Its rendered-state snapshot also did not contain an edit made in the same React batch as teardown. The reply composer already has departure cleanup that persists its captured reply, so this unit does not replace its writer.

## Single write owner

- Draft edits now synchronously update the snapshot/request identity and mark it dirty. The same mutation function owns text, category, diagnostics, and screenshot adoption/removal; React state displays that snapshot.
- `saveCurrentDraft` is the common autosave/departure entry. Debounce, real screen blur, foreground departure and unmount all use the existing durable draft writer. Clean, restoring, accepted, tracking-recovery and currently sending drafts are not rewritten by departure.
- A save captures one snapshot, draft restore generation, UI identity and storage scope. After async boundary capture, a newer request identity or restored draft invalidates the older snapshot. An old pending capture cannot overwrite the newer departure save or publish a save error into another account/presentation.
- The existing epoch/scope assertion is still used. No storage key is invented from a later account's session and no account boundary is reconstructed from an epoch alone.
- Accepted delivery invalidates outstanding autosave generation before existing queued draft cleanup. Explicit send still persists its exact request before HTTP, and failed delivery retains the same durable request/screenshot. Teardown cannot recreate an already accepted draft.
- Managed-image cleanup still follows a successful durable replacement. Storage failure keeps the previous persisted snapshot/file, not a falsely reported guarantee that every device can save when disk space is unavailable.

## Backend/dashboard compatibility

This is local draft lifecycle ownership. New-message/reply multipart fields, UUID/idempotency keys, guest tracking, authorization, server screenshot sanitization, and the admin support attachment timeline are unchanged. The existing backend/admin contracts examined in `feedback-screenshot-preparation.md` remain the authority; no dummy backend or dashboard rewrite is required for a local debounce loss.

## Final gate

Authored, **not run**, in `feedbackSubmissionDelivery.test.tsx`: text/category/diagnostics edit plus teardown before render/debounce; adopted and deleted image plus immediate same-account reopening; background/blur save without clean-draft rewrite on later teardown; deferred old boundary capture vs newer final save or another account's restored draft; leaving before restoration; disk failure retaining the previous saved image; accepted-report blur/background/teardown without resurrection. These use the real controller and durable draft services with native/storage boundaries mocked; they are not proof of device filesystem durability or process-kill survival.

Independent review answered the required question with limited source acceptance and no remaining daily-flow blocker found in this unit. It confirmed synchronous draft snapshots, the single existing writer across timer/blur/background/unmount, identity/restore/request guards, persist-before-POST, accepted-draft invalidation and preservation of the preceding screenshot-preparation unit. It also verified `useIsFocused` is bound in the actual screen; source acceptance is not executed test/device acceptance.

Final verification must execute this suite and existing delivery/replay/guest/conflict/image-ownership suites and prove device back/gesture navigation, blur/background, immediate reopening, upload retry and dashboard receipt/attachment coherence. OS termination before storage commit and an unavailable/full device cannot be claimed solved by a cleanup effect. Tests, typecheck, lint, builds, push and deployment remain deferred to the combined gate.
