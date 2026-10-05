# Portfolio transfer progress — local source, runtime acceptance pending

## Reference and actual reuse

Starting reference: Rocket.Chat's shipped React Native file-upload flow, [Upload.ts](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/lib/methods/helpers/fileUpload/Upload.ts) and [Upload.android.ts](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/lib/methods/helpers/fileUpload/Upload.android.ts), report native transport sent/expected bytes separately from the request result. Its [upload presentation](https://github.com/RocketChat/Rocket.Chat.ReactNative/blob/develop/app/views/RoomView/components/UploadProgress.tsx) uses a compact progress line and distinct error/retry states. [Rocket.Chat reports over 12 million platform users](https://www.rocket.chat/company/about-us); this is platform adoption, not a verified count of mobile users or a Rokn performance result.

Actual library reuse: the installed Axios client handles image request progress through `onUploadProgress`, including its existing event throttling. Videos reuse React Native's existing native XMLHttpRequest upload-progress events on the existing bounded 4 MiB TUS chunks. This is architecture/visible UX adaptation, not copying Rocket.Chat's source or claiming access to TikTok's code. Rocket.Chat's Expo-specific Android transport is not compatible with this non-Expo application and is not imported. No suitable full implementation of Rokn's TUS claim/idempotency/account outbox contracts was found; the binding/aggregation is original integration around these existing libraries, not a renamed reference implementation.

## One responsibility and preserved contracts

- Foreground create and add-files-to-existing-project use the same progress projection and presentation. Percentage comes from transport events, weighted by payload size, never completed-file count or a timer. For multipart images, the per-request native sent/total fraction is weighted by image file size; this is a transfer percentage, not an exact multipart-wire byte counter.
- Missing/invalid native transport totals use an indeterminate indicator rather than an estimated percentage. File preparation, upload, server attachment and project finalization have separate labels. A fully transmitted image or chunk is not accepted solely on the upload event.
- Video resume starts from the HEAD-confirmed TUS offset. Each chunk still requires the exact returned offset before advancing; authorization refresh and claim remain unchanged. A rejected chunk may legitimately move presentation back to the confirmed offset. No fake high-water retention promise.
- Foreground and replay join one delivery flight and observers receive its latest snapshot, so subscribing does not create a duplicate upload. Progress callbacks (including initial and per-file settlement presentation) cannot fail the content mutation. Mutation/account guards remain outside presentation catches. Late callbacks and old-account events are suppressed; observers retire on flight settlement.
- Existing backend `PortfolioMediaReadinessService`, claim validation, upload entitlement and dashboard preview readiness remain authoritative. Client percentages create no progress endpoint, database status, public approval or fake `ready` state. No backend/dashboard schema or write changes are needed for this transfer-only unit.
- This transfer-progress unit does not itself enable cancellation or pretend background replay has a new always-visible progress screen. The subsequent source-reviewed [pause/resume unit](portfolio-upload-pause-resume.md) now covers durable stop and resumed-project UI: stopping a transfer must not leave automatic replay free to restart it invisibly. Both units remain pending runtime acceptance.

## Final combined acceptance

Independent source review accepted this unit within transfer-progress responsibility after correcting observer-error isolation at initial and per-file settlement notifications. The follow-up confirmed that batch mutation/account guards remain outside presentation catches and that discarded files do not count as successful attachments. This is source acceptance, not proof of native runtime behavior or completion of the project-wide goal.

Authored but not run: weighted image/video percentage, unknown totals, legitimate retry rollback, discarded/late events, one foreground/replay flight with latest progress, old-account isolation, batch init/transfer/settlement observer errors with remaining-file continuation and account-change rejection, Axios image events/late callback retirement, resumed video chunk progress/exact-offset/claim separation, and shared Arabic/accessibility presentation. Existing recovery tests remain in the final gate.

Before release: run these tests plus existing portfolio integration suite once at the combined gate; prove on Android and iOS over slow network that real native progress events arrive, upload percentages never imply published/playable media, a stalled native event remains indeterminate where necessary, and recovery/claim/idempotency/entitlement/dashboard review still work. Phone pixels, memory and runtime event delivery are not proven by source inspection. No tests, build, commit, push or deployment have been run for this unit.

## Final combined gate update — 2026-10-05

The six cases in `portfolioVideoUploadRecovery.test.ts` now pass, including
resumed byte progress followed by validated PATCH completion and server claim.
The Fetch fixture uses actual `Response` objects rather than incomplete objects
cast to Fetch/Response. POST supplies Location; HEAD supplies Upload-Offset.
The progress case awaits the actual XHR passed by its send callback, retaining
the same cancellation/progress/load lifecycle. No transport implementation or
test expectation was removed or weakened. Independent source review accepted
these fixture corrections, not native transfer acceptance.

Logs are `mobile/.cache/final-gate-20261005/mobile-video-response-fixture-recheck.log`
and `mobile-typed-fixtures-final.log` (three suites, 36 tests). Targeted ESLint
and the complete mobile typecheck exited zero. Actual Android/iOS progress,
interrupted storage/claim integration and the broader failing portfolio suites
are not thereby proven. The combined release gate is incomplete and no build,
push or deployment was performed.

## Final gate — batch progress callback contract, 5 October 2026

The fresh profile recovery contract failed its old two-argument foreground
delivery source literal. Foreground batching now passes its existing progress
observer as the third argument. The corrected assertion requires the same
delivery owner, entry and boundary plus `progress.transfer(index, value)`;
the two-argument replay assertion remains unchanged. No transport or source
code changed. Independent read-only review accepted this limited call-contract
correction. The complete class has 4 passing cases and one separate mutation
admission-count failure still open, not a fully passing suite. Evidence:
`mobile/.cache/final-gate-20261005/mobile-profile-delivery-callback-recheck.log`.
This does not prove native progress, physical upload or full release readiness.
