# Feedback screenshot preparation — local source, final verification pending

## Established reference and actual reuse

- [Rocket.Chat's shipped media chooser](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/containers/MessageComposer/hooks/useChooseMedia.ts) separates selection/attachment normalization and validation from explicit message sending. Its [message composer](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/containers/MessageComposer/MessageComposer.tsx) sends a prepared attachment snapshot and retains failed message input rather than presenting success before delivery.
- [Rocket.Chat's company page](https://www.rocket.chat/company/about-us) reports more than 12 million platform users. This is platform adoption, not a claim about mobile install counts or proof of Rokn runtime behavior.
- This unit adapts the prepared-attachment/explicit-send architecture. No Rocket.Chat source was copied. Its picker/cropper, room database, attachment server and authentication protocol are not Rokn's and were not imported. The reference is not claimed to implement Rokn's exact private-file preparation race gate.
- Rokn actually reuses its installed `react-native-image-picker`, RNFS-backed `cacheLearnerDraftFile`, durable feedback/reply queues, multipart encoder and idempotency receipts. No compatible ready-made adapter for Rokn's account-scoped drafts, guest credentials and support-case API was found. The shared preparation owner and contract binding are original Rokn integration, explained before implementation.

## Defect and ownership

The native picker can return to the form while the selected image is still being copied into private draft storage. Both support composers previously kept only a ref-only picker flag, but send did not consult it. A normal tap during that copy could send the old draft without the chosen screenshot. A late selection could then appear in a cleared draft. The old unconditional picker-finally could also clear the next account/case's newer operation.

`useFeedbackScreenshotPreparation` is now shared by new reports and case replies. Its token belongs to one account/draft key, survives picker and private-copy awaits, and is released only by its owner. Account/case changes, restoring a different draft and unmount invalidate adoption. The real picker adapter checks this owner before opening native UI and after its awaits, so a late copy failure cannot show an alert over a departed owner. A late unadopted private file is released through the existing managed-file cleanup, not a broad filesystem delete.

Both send entry points inspect the live preparation lock before any persistence/HTTP, including a tap before React has rendered the disabled button. Adopting an attachment updates the current draft snapshot and request identity before unlocking send. Sending then persists and encodes the same frozen prepared snapshot. Text edits remain allowed during preparation, cancellation preserves the previous image/text, and a failed send retains its existing idempotent request and attachment.

The existing form layout is preserved. `جارٍ تجهيز الصورة` is separate from `جارٍ الإرسال`; add/remove-image and send controls are disabled while preparation is pending, with matching accessibility state. The actual `Feedback` screen passes the state from both real controllers into their respective surfaces.

## Backend and dashboard coherence

No API/schema/provider changes are required for a client preparation race. The new-message `feedback` and reply `feedback/{publicId}/messages` encoders still use `screenshot` and the same UUID in multipart and `Idempotency-Key`. `FeedbackController` validates the existing optional screenshot on both routes; the submission/writer services retain account/guest authorization, screenshot fingerprint and sanitization, and replay semantics. The admin support timeline displays each message's admitted sanitized attachment through its existing scoped delivery route. No duplicate support storage or dashboard upload mechanism was introduced. Compatibility with old clients is retained at source-contract level, not yet proven on a device/live server.

## Verification status

Authored, **not run**: real controllers + real picker adapter + real multipart/durable draft services, with native selection and `cacheLearnerDraftFile` boundaries doubled and deferred to expose the race; same-render/stale-callback send during preparation; latest text/prepared image and identical request replay after a lost response; cancellation/delete lock; copy failure on active/departed owner; old account/case result vs a newer picker; existing unmount/account cleanup and durable superseded-file cases; actual form/reply preparation labels, enabled text input and disabled/accessibility-consistent controls. These cases do not execute the device picker or actual filesystem copy.

Independent review answered the required question with limited source acceptance: no remaining practical blocker found in preparation vs new-message/reply sending. It confirmed the synchronous send lock, prepared snapshot/request identity, token/account/case/restore/unmount ownership, departed-owner error suppression and unchanged backend/admin attachment contracts. It explicitly distinguished source acceptance from actual RNFS/device behavior. Updating every text/delete mutation's snapshot synchronously could strengthen programmatic same-batch calls, but was not judged a demonstrated normal-user blocker in this unit; no unrelated rewrite was added for it.

The final combined gate must execute these suites and existing feedback coverage, then prove native picker return/copy/send, failed retry, account/case changes, guest tracking and dashboard attachment visibility end to end. Tests, typecheck, lint, native build, push and deployment remain deferred; no runtime acceptance or whole-project completion is claimed.

## Final gate — presentation control selection, 5 October 2026

The fresh presentation run failed all four cases before checking the controls:
the current React Native memoized `Pressable` was not found by exported type
identity. The fixture now selects the existing button roles and accessibility
labels. The unlabeled reply action is selected by its role and the same original
`onSendReply` handler. Production controls and their handlers were not changed.
All assertions remain: preparing blocks send even with stale `canSubmit=true`,
blocks add/remove, allows text editing, distinguishes preparation from sending,
and enables send after preparation, with and without an existing attachment.

The complete presentation suite passes 4/4. Independent read-only review
accepted the selectors and unchanged assertions, without rerunning tests.
Evidence: `mobile/.cache/final-gate-20261005/mobile-feedback-preparation-controls-reproduction.log`
and `mobile-feedback-preparation-controls-final.log` in the same directory.
This closes the presentation-fixture failure, not physical picker/private-copy,
HTTP delivery, dashboard attachment visibility or whole-release verification.
