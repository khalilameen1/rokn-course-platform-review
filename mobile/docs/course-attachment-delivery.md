# Course attachment delivery

The learner-facing entrypoints remain `openCourseAttachment` and
`quiescePrivateAttachmentDownloads` in `attachmentActions.ts`. Screens should
not call the native transfer helpers directly.

## Ownership

- `attachmentActions.ts` owns action deduplication, account generation checks,
  user messages, refresh/retry decisions and system Save to Files handoff.
- `attachmentAccess.ts` renews signed access and maps the authorized response.
  It receives the action's ownership assertion and checks it around async reads.
  It does not download bytes, hold account state or show dialogs.
- `attachmentTransfers.ts` owns native transfer settlement, cancellation,
  active Android jobs and retired private staging targets. It registers accepted
  Android jobs itself; callers cannot accidentally omit them from shutdown.
  It receives an ownership predicate for enqueue completion, not an account ID
  or a UI callback. It does not renew links or choose user-facing messages.
- `attachmentMetadata.ts` owns filename/MIME normalization and bounded HTML
  detection. It has no IO or platform state.
- `attachmentDownloadPolicy.ts` selects recovery from native error codes.
  `attachmentDownloadNotice.ts` and `attachmentSavePresentation.ts` retain their
  separate presentation/lifecycle responsibilities.

## Invariants

1. Invalidate the action generation before cancelling jobs, notices or save
   sheets during account shutdown.
2. A late enqueue result must be cancelled after timeout or ownership loss.
   It must never be presented as a new download for the next account.
3. Every iOS attempt has its own staging target. A cancelled target is not
   eligible for recovery while the old native callback can still delete it.
4. Native cancellation does not have to settle its promise for JS cancellation
   to finish. Late bytes are cleaned only from the retired attempt's path.
5. Signed course links must not escape to another app as an Android fallback.
6. A progress notice must dismiss before presenting the save sheet. A download
   is successful only after the save handoff reports success.

## Verification

The attachment behavior suites exercise the public action, including binary
files, permission handoff, account changes, refreshes, cancellation, background
recovery and presentation ordering. `attachmentTransfers.test.ts` separately
tests transport ownership without UI. Keep those tests as behavior contracts;
moving a helper should not require weakening assertions.

Run TypeScript checking, scoped ESLint and the attachment Jest suites after
changes. These JS tests do not replace Android/iOS device checks of the native
download manager and document picker.
