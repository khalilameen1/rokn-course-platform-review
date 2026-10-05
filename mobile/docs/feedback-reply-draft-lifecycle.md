# Support reply draft lifecycle — local source, final verification pending

## Final gate follow-up — 2026-10-05

The installed React 19 types require an explicit initial reference value. The
draft owner is now `useRef<DraftOwner | undefined>(undefined)`, following the
[React 19 migration contract](https://react.dev/blog/2024/04/25/react-19-upgrade-guide#useref-requires-an-argument).
This retains the previous runtime empty-owner state; no lifecycle, queue,
storage, delivery or server contract changed. Independent source review
accepted this limited compatibility fix without casts or suppressed types.
The typecheck rerun no longer reports this hook but remains failing on other
test-fixture diagnostics. The targeted final-gate rerun of this controller's
`feedbackSubmissionDelivery` suite and `feedbackArtifactPreviewOwnership`
passed 82 tests across the two suites. Full mobile/backend/dashboard and native
release acceptance are still pending.

## Established reference and actual reuse

[Rocket.Chat's shipped `useAutoSaveDraft`](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/containers/MessageComposer/hooks/useAutoSaveDraft.ts) saves ongoing input and a final editor draft on departure, not an older closure on every rendering cleanup. Its [draft persistence](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/lib/methods/draftMessage.ts) has distinct room/thread ownership. [The company reports more than 12 million platform users](https://www.rocket.chat/company/about-us); this is platform adoption, not a mobile installation claim.

This is architectural adaptation, not copied source. There is no ready adapter for Rokn's support case/guest credential/multipart screenshot contracts. The implementation actually reuses Rokn's existing account/case storage keys, AsyncStorage, `withDraftLock`, managed attachment replacement and idempotent reply encoder. No Rocket.Chat database/protocol, extra library, second draft queue or server draft endpoint was added. React Navigation's installed `useIsFocused` and the existing foreground store supply departure state.

## Demonstrated defect and one owner

The reply autosave effect previously persisted its rendered closure on every dependency cleanup. A final text edit, screenshot deletion or adoption followed by back before rendering could save the predecessor instead. Ordinary rerenders queued obsolete snapshots as well. Screenshot adoption had a synchronous ref, but text/delete and the departure writer did not use that same authoritative snapshot.

`useFeedbackReplyDraft` now owns reply restoration, edits, durable save and accepted-delivery cleanup; `useFeedbackCases` owns server history and explicit send. Attachment preview is a separate viewer owner (see `feedback-attachment-preview-ownership.md`). A per-account/per-case owner holds one immutable current snapshot and discarded managed files. All edits synchronously replace that snapshot and UUID before publishing React state. Case/identity-bound callbacks cannot operate on the next selected owner.

- The 300 ms timer only saves a dirty snapshot; its rendering cleanup cancels the timer without writing a predecessor. Actual case departure, screen/tab blur, background and teardown flush the same latest owner. Clean/restoring/sending snapshots are not autosaved.
- A snapshot's in-flight save is deduplicated across blur/background/departure. A superseded pending capture is rejected before storage, and it cannot flag a later snapshot or another owner's UI.
- Reply saves reserve the existing `withDraftLock` immediately, before a supplied real boundary capture promise resolves. Reopening must read behind the departure save. Boundary rejection is observed immediately while waiting for earlier storage work, and scope/epoch assertions remain intact; no boundary is reconstructed from another account's epoch.
- Files are retired only after durable replacement through the existing writer. A failed save preserves the prior stored snapshot/file and shows a local save error while the editor still exists. Explicit send persists the same frozen multipart/UUID before HTTP, so failed sends remain retryable.
- Conflict restoration first preserves the currently edited slot. A failed save prevents the swap; successful swap keeps that current snapshot in the existing conflict store. The editor and screenshot adoption are unavailable during restoration.
- Accepted send invalidates the old owner's autosave before clearing. Cleanup compares the accepted `clientRequestId` with the stored case draft **inside the same storage lock**. A late ACK for A cannot erase a newer saved B or B's file after back/reopening. This is not just an unmounted-render guard.
- The live editor also borrows restored/adopted files through the existing `retainLearnerDraftFiles` registry with an instance-unique reference owner. Restore acquires the file reference inside the draft lock before releasing the loaded slot or enabling the editor. This covers B editing text with A's image before B's 300 ms autosave: A's accepted slot can be cleared without deleting the image B is still using. Mutation updates the reference; final persistence protects its snapshot through the existing file queue. Departure reserves save immediately, waits for any pending restore/save, then releases only this editor's reference. Failed departure keeps the registry's existing bounded commit grace, not a permanent orphan exemption. Text-only drafts without any borrowed file do not acquire image references.

## Mobile/backend/dashboard coherence

`Feedback` passes real navigation focus and whether the conversation tab is visible. The native preparation lock still gates send; the reply editor remains visible with its existing sending state, and text remains editable while a screenshot is being prepared.

The wire contract remains `feedback/{publicId}/messages`, the same multipart `screenshot` field and `client_request_id`/`Idempotency-Key`. `FeedbackController::reply` validates and authorizes the existing request and `SupportCaseService` appends the idempotent message; the admin feedback timeline continues to display each admitted image through its existing authorized route. This local lifecycle fix neither autosends a draft nor requires a fake backend/dashboard rewrite. Contract compatibility is source-inspected, not yet proven against the live reviewer build.

## Independent review and combined gate

First independent source review rejected unconditional accepted-reply cleanup: send A, leave, reopen, save B, then ACK A would clear B. The accepted-request compare-and-clear operation and actual controller deferred-POST/reopening regression were added in response. A second review rejected the narrower saved-B protection because a text-only edit of B could still borrow A's image before debounce. The existing managed-file reference registry is now reused to protect the live editor independently of accepted-slot cleanup.

Third independent review answered the required question with limited source acceptance and no additional daily-flow blocker found in this unit. Acceptance explicitly includes both durable B and the live same-image B before debounce, not the narrower stored-only case. It confirmed acquire-before-editor/inside-read-lock protection, accepted-request CAS, instance-specific release after restore/departure save, eager existing-writer reservation and the actual-file-service authored regression. The reviewer neither ran tests nor modified files; device/runtime acceptance and the full project goal remain open.

Authored, **not run**, in `feedbackSubmissionDelivery.test.tsx`: last edit plus back in the same React batch; image replacement/deletion plus immediate reopening; background/blur and no clean-render rewrite; final save queue reservation while capture is delayed; independent case switching and stale send callbacks; superseded delayed autosave; prior POST ACK after back/reopening/new text/new image; failed local save preserving the prior screenshot and blocking HTTP. The existing conflict-restoration test now starts an actual dirty save and verifies the edited current reply survives in the conflict slot. Existing preparation, native cleanup timeout, delivery/retry, guest migration, account-boundary and hydration-failure tests remain required.

The additional ACK-before-debounce test reuses the actual managed-file readability, reference registry and cleanup services, with RNFS operations backed by a fake native file map. It reopens the same image, edits text only, releases the old POST before advancing the debounce, checks the image actually remains readable, then persists/reloads B with that image. This is stronger than counting mocked cleanup calls, but still not a physical-device proof.

These cases exercise actual controllers and durable services with native/storage/HTTP boundaries mocked. They do not prove device filesystem durability, OS process-kill survival, native navigation pixels, or dashboard delivery. Final combined verification must run these suites, typecheck/lint and device back/gesture/tab/background/reopen, then send/retry and inspect the server/admin timeline. Tests, builds, commit/push/deployment remain deferred; no phone/store acceptance or whole-project completion is claimed.
