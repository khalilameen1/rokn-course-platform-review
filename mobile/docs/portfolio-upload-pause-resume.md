# Portfolio upload pause and explicit resume

Local source unit. Native/runtime acceptance remains deferred to the final combined gate.

## Final gate — explicit upload-session callback dependencies, 5 October 2026

Full release lint flagged six callbacks using the returned uploadSession object
while declaring only its method dependencies. Create and details now destructure
the same existing stable begin/end/pause functions and reference those values
in both bodies and dependencies. The session owner itself, account/outbox state,
upload/publication order, Back behavior and server/dashboard contracts are unchanged.
No new queue, dependency, provider or reference implementation was introduced.

Scoped lint is zero errors/warnings in both files. Six complete suites pass
28/28 in `mobile/.cache/final-gate-20261005/mobile-portfolio-callback-bindings-final.log`:
actual pause binding, session, publication mutation boundary, draft hydration,
post-learning contract and profile recovery contract. Independent read-only
review accepted unchanged ownership and concrete dependency binding. These
controlled-boundary cases do not establish native bytes, storage, provider
traffic or release readiness; the earlier full mobile pass predates this edit.

## Established reference and actual reuse

- [Rocket.Chat React Native upload](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/lib/methods/helpers/fileUpload/Upload.ts) uses native XHR upload progress and abort; its [upload progress UI](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/views/RoomView/components/UploadProgress.tsx) provides compact stop/retry controls. [Rocket.Chat's company page](https://www.rocket.chat/company/about-us) reports 12 million platform users; this is not a verified count of mobile users or evidence of Rokn performance.
- This is architectural/observable-flow adaptation, not copied Rocket.Chat code. Its Android Expo upload task and chat-server endpoint do not match Rokn, so neither is imported. No suitable prebuilt implementation matching Rokn's existing durable media outbox, Bunny claims and portfolio publication contract was found; those bindings remain original project integration.
- Actual reuse is the existing Axios `signal`/`onUploadProgress`, React Native XHR `abort`/upload callbacks and AbortController/fetch signal. No new dependency, SDK, provider or parallel upload protocol is introduced.

## One authoritative ownership path

- Explicit pause is saved on the account-scoped durable media outbox, under its existing mutation lock, **before** aborting transport. A failed storage write leaves transport live and shows a retryable error; it never claims the upload stopped.
- All pending siblings inherit the project's pause. Old entries without the optional boolean remain active. Replay skips paused rows and delivery rechecks the live project pause under the same lock, so an old replay list cannot override a saved decision.
- Images propagate signal through entitlement and Axios multipart transport. Video authorization/renewal/claim, provider create/HEAD and native PATCH honor the same delivery controller. Chunk abort detaches listeners; the existing provider URL, claim and confirmed offset remain available to resume.
- A positive accepted server receipt still wins and retires its intent. An aborted/unknown response retains the original UUID/claim for idempotent reconciliation; pausing does not undo an accepted file. Percentage is never publication proof.
- Creation Back/stop uses its upload session after files are durably staged. Its accepted server item/outbox take over file ownership before the editor draft is retired. Preparation is not advertised as cancellable.
- Selected-project upload has a separate mutation owner shared by add/resume. Preparation updates React state even before the picker and keeps selection open; its close control is visibly disabled. Once transfer is active, the one visible stop button and Android Back call the same durable pause. The window stays until the pause result and mutation finish; a later normal close is available. Normal edit/delete closing is unchanged.
- A central mounted/account/selection/generation/mutation guard checks the selected upload before unpausing and starting transport, including after native staging. Teardown cannot start an old selection's bytes or unpause it after delayed entitlement.
- Opening/foregrounding a paused project does not resume it. Details offer `استكمال الرفع` ahead of finalize/share; pending work blocks another media batch and explicit premature publication. Unreadable pending state offers a local reread, not a guessed empty queue. Explicit resume freshly verifies entitlement and removes pause under the same outbox lock before using the ordinary delivery path.

## Backend/dashboard consistency

No new server pause state is needed: pause concerns unaccepted local files. Existing server entitlement, media UUID receipts, video claims/provider readiness and portfolio review/publication remain authoritative. Dashboard approval cannot see client percentage as readiness and no approval/public link is created by pausing or resuming. Existing server APIs stay additive-compatible with version 60; no server deployment was made for this local unit.

## Authored evidence and final gate

Authored, **not executed**:

- Outbox durable sibling inheritance, explicit unpause, old-record compatibility, malformed pause rejection and account isolation.
- Delivery shared-flight pause, storage-before-abort, failed storage/no abort, stale replay snapshot, accepted response winning and replay skipping paused projects.
- Native video PATCH abort preserving claim/provider URL for resume.
- Real creation hook, API, outbox and delivery wiring: Back retains staged files, skips finalize, retires only the editor and cannot automatically replay paused work.
- Real details hook/API/outbox/delivery wiring: Back abort/persist, same UUID on explicit resume, no automatic replay, pending entitlement/preparation presentation, denied late ownership after teardown, failed pause/retry and native staging teardown.
- Session coalescing, completion awaiting durable acknowledgement, failed-write retry and late old-owner completion.
- Presentation pending/resume priority, unreadable state reread, preparation-disabled close, actual stop/pausing accessibility state and no premature publication.

Independent review accepted the corrected source ownership after identifying and fixing Back bypass, delayed start guards and the ref-only preparation render gap. The reviewer also source-reviewed the newly authored real binding/session/creation/presentation evidence and accepted its scope; none was executed. Final acceptance requires running all authored cases once with the project's combined gate, then proving pause/Back/reopen/resume on Android and iOS with actual image multipart and Bunny TUS traffic, large text and account changes. No test, lint, typecheck, native build, commit, push or deploy was run for this unit.

## Final gate — shared transfer/publication binding, 5 October 2026

The fresh post-learning contract passed two cases and failed an old regex
requiring `finalizeAfterUpload` after the `addSelectedMedia` declaration. The
actual transfer/finalization owner is shared by add and explicit resume and
declared earlier. The corrected assertions require both real bindings:
selection stages files then awaits `uploadSelectedEntries`; that owner awaits
delivery and finalizes only when neither interrupted nor paused, preserving the
processing result. No production transfer, publication or sharing code changed.

The complete post-learning contract plus actual publication/mutation-owner
suites pass 8/8. They retain immutable API certificate assertions, explicit
portfolio authorship, rejected old edit/media/publication results and newer
publication behavior. Native/API/outbox boundaries remain controlled doubles.
Independent read-only review accepted the corrected binding without rerunning.
Evidence in `mobile/.cache/final-gate-20261005/`:
`mobile-post-learning-binding-reproduction.log` and
`mobile-post-learning-publication-final.log`. This is not server approval,
physical transport/pause, published-link integration or release acceptance.

## Final gate — shared mutation admission contract, 5 October 2026

The profile recovery suite's legacy count found four direct admissions because
the add/resume paths now enter through `beginUploadMutation(showSaving)`. The
assertion still requires five `beginMutation` calls, including that shared
wrapper, and now requires both upload entries and the wrapper's immediate call
to the same synchronous mutation owner. It does not lower the count or create a
new lock. Existing cancellation, publication invalidation, deferred deletion
release and pending-upload assertions are unchanged. Production was not edited.

The complete profile recovery contract now passes 5/5, superseding the previous
4/5 progress-callback checkpoint recorded in `portfolio-upload-progress.md`.
Independent read-only review accepted both corrections, confirming the upload
flight marks the same admitted mutation rather than a second lock. Evidence:
`mobile/.cache/final-gate-20261005/mobile-profile-mutation-admission-final.log`.
This source-contract result is not physical-device concurrency, backend lock
verification or full release acceptance.
