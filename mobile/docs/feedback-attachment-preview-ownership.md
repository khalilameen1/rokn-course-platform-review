# Support attachment preview — source accepted, combined verification pending

## Final gate follow-up — 2026-10-05

The full mobile typecheck rejected the no-argument `useRef<Preview>()` against
the installed React 19 definitions. The reference is now initialized explicitly
as `useRef<Preview | undefined>(undefined)`, following the
[React 19 migration contract](https://react.dev/blog/2024/04/25/react-19-upgrade-guide#useref-requires-an-argument).
This reuses the installed hook, without casts, suppression or changed runtime
ownership. Independent source review accepted this limited correction. The
typecheck rerun no longer reports this hook; other test-fixture diagnostics
remain, so the complete typecheck is still failing.

The real controller/presentation test now awaits its React updates and locates
the retry control by its actual accessibility props with the existing test
renderer. The preceding type-identity lookup missed a rendered control even
though the real controller had published `previewLoadFailed=true`. Assertions
for current artifact, native attempt isolation, retry accessibility, disabled
busy state and closing remain; none were removed. The targeted final-gate
rerun passed both `feedbackArtifactPreviewOwnership` and
`feedbackSubmissionDelivery`: 82 tests. It does not prove native-device behavior
or close the remaining full-suite failures.

## Reference and actual reuse

[Rocket.Chat's AttachmentView](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/views/AttachmentView.tsx) makes attachment/loading state a viewer responsibility rather than mutating the chat timeline. Its [ImageViewer](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/containers/ImageViewer/ImageViewer.tsx) delegates image rendering/load callbacks to the native image component. [Rocket.Chat reports over 12 million platform users](https://www.rocket.chat/company/about-us); this establishes platform adoption, not that many mobile installs.

This unit adapts that ownership architecture; it does not copy source, import their database/protocol, reproduce pinch gestures, or claim their code handles Rokn's signed URLs. No compatible ready adapter for Rokn's case/guest credentials and expiring links was found. The integration gap was disclosed before implementation. Actual reuse is Rokn's existing RasterImage/native Image (including measured-size decoding), authorized feedback read/parser, installed Axios cancellation, navigation focus and existing foreground store. No new package or image download/cache layer was introduced. The approved modal layout remains.

## Concrete defect and cohesive replacement

The previous inline `openArtifact` compared `selectedCaseId` with an owner captured in the same old render. Both remained old after switching cases, so a late renewal could open/update the wrong viewer. Case selection did not invalidate the preview, and departed requests were not cancelled. The renewal also replaced the whole case from its GET snapshot: when a later reply POST completed first, that older GET could erase the newly displayed reply. Native Image errors were matched only by attachment ID; a stale error could fail a newer URL for the same attachment.

`useFeedbackArtifactPreview` now owns opening, renewal, cancellation, close and native-error state. `useFeedbackCases` remains responsible for history/reply dispatch and calls this dedicated viewer controller rather than carrying another inline version.

- A context object binds account identity, case, focus and foreground lifetime. A token binds a particular opening, and a separate key binds a particular native Image attempt. Returning to an earlier case/focus cannot resurrect its old opening.
- Rendering hides a preview as soon as its context is no longer current; lifecycle cleanup clears its state and aborts any pending renewal. New-case/account callbacks cannot reuse a previous owner's credentials. Callback ownership is checked again after boundary capture, before GET, and after the response.
- Opening accepts only a canonical attachment from the currently selected case. A caller cannot inject another URL/attachment. Expired links or explicit retries use the existing authorized GET with the current case access token and AbortSignal. Repeat taps while that attachment's renewal is pending are deduplicated; another attachment opening cancels the previous flight.
- Only a refreshed descriptor with the expected case ID, attachment ID and usable expiry is admitted. A missing/removed/expired descriptor or ordinary failure shows the existing retry action without an automatic request loop. Busy state is visible/accessibility-labelled while renewal is pending, and retry controls are disabled when busy.
- Renewal never writes `supportCases`: only the image descriptor changes. A concurrent successful reply therefore retains its server-owned updated history.
- Native Image remounts on a new image-attempt key. Its captured onError must still own both viewer token and image key, and cannot flag an old opening or a renewed same-ID image. During renewal, an error from the old URI is ignored; the current renewed attempt can still report a real failure.

## Mobile/backend/dashboard contract

`Feedback` binds actual screen/conversation-tab focus and passes viewer state into `FeedbackConversation`. Foreground state deliberately uses `useAppForegroundState`, not Android window interactivity: the native Modal itself must not immediately invalidate its viewer.

`loadProductFeedbackCase` has an optional fourth AbortSignal argument, forwarded into the installed Axios GET. Calls without a signal retain their existing config shape and all account boundary assertions. The endpoint, guest header, payload and signed-link scheme are unchanged. `SupportCaseReadService` still returns customer-visible sanitized attachments with scoped 15-minute signed routes. `FeedbackController` authorizes the case GET and scopes attachment IDs to the report; `SupportCaseAttachmentDeliveryService` retains integrity/missing-file checks. Admin feedback uses its existing scoped attachment route. No fake backend/dashboard rewrite, migration, upload or deployment is required for this local viewer lifecycle defect. Live reviewer compatibility is not yet runtime-proven.

## Independent review and deferred gate

The independent reviewer read both primary reference files, current viewer/controller/service/presentation binding, and the authored regression suite. It answered **هل وصلت الوحدة إلى مستوى المرجع ضمن مسؤوليتها؟ نعم** with source-only acceptance and no remaining daily-flow blocker found. It confirmed descriptor-only renewal, native token/key protection, cancellation/ownership, guest credentials, normal retry and actual service/presentation binding. It did not run or modify anything; this does not accept the full project or native runtime.

Authored, **not run**, in `feedbackArtifactPreviewOwnership.test.tsx`: canonical fresh opening without extra GET/POST; signed-link retry with guest header/signal; case/list/focus/background/account/unmount departure and late completion; departure before boundary capture; late failure after case switch; another attachment while renewal is pending; close/reopen same-ID stale native error; renewal same-ID remount; rapid repeated retry; delayed renewal GET after an accepted reply POST; wrong-case/missing/expired renewed descriptor; arbitrary non-member attachment; actual FeedbackConversation modal, RasterImage callback/remount, busy state and retry accessibility.

Final combined verification must execute these and related feedback/draft/preparation tests, typecheck/lint, and device back/tab/background/account-switch/slow-network image cases. Inspect actual accepted reply + attachment in the backend/admin timeline and verify the older reviewer build contract before release. Tests, build, commit, push and deploy remain deferred to the one final gate. The project goal remains active.
