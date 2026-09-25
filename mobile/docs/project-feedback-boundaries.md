# Project report discussion ownership

The optional discussion uses three owners instead of keeping remote transcript,
local draft persistence and user actions in one hook.

- `useProjectFeedbackThread` owns the server transcript, quota/access refresh,
  interrupted hydration and bounded reply polling. It cannot save drafts, pick
  files or initiate a paid message. Same-thread course summaries keep the full
  transcript but still apply a revoked reply permission.
- `useProjectFeedbackDraftEditor` owns one project/thread editor visit, its
  account boundary, text, files and durable request identity. The storage service
  `projectFeedbackDraft` remains the sole owner of storage keys, serialization,
  expiration and file references. No second storage format was introduced.
- `useProjectFeedback` coordinates reply eligibility, consent, file picking,
  upload and send/recovery. Paid submission still requires successful durable
  staging and a matching account/visit before calling the existing API.

## Draft guarantees

- Switching projects flushes the departing visit's snapshot, not refs already
  overwritten by the new render. The next draft cannot be saved before it has
  been read successfully.
- Stale callbacks cannot edit, stage or consume another visit. Account checks
  still run in both the editor and persistence layer.
- Autosave and background failures are visible through one save status and an
  explicit retry action. Retrying save does not reload over current text or send
  a message. Older write results cannot clear a newer failure.
- Mandatory pre-send staging persists text, uploaded attachment IDs and request
  identity together. A lost acknowledgement retains the same ID for explicit
  retry. It never silently starts a second request.
- Editing text or attachments invalidates the previous request identity. A late
  server acknowledgement can consume only a draft carrying that exact identity,
  not a newer unsent question. Successful consumption leaves an empty ready
  editor and cannot run twice for the same receipt.
- Unmount writes are best effort: there is no mounted UI to report a failure.
  Neither this editor nor the underlying service promises persistence when the
  OS terminates the process before a write finishes.

## Local evidence

`projectFeedbackDraftEditor.test.tsx` exercises visit changes, delayed writes,
account replacement, autosave/background failures, retry and receipt ownership.
`projectFeedbackLifecycle.test.tsx` exercises real hook composition, lost ACKs,
permission refresh, stale response rejection, late receipts after editing and
the mandatory-save barrier before sending. Presentation tests verify the save
failure and retry action. Existing hydration/retry/picker tests remain in place.

These are local automated tests, not a claim of a new native build or a live
provider/store test. No server schema, endpoint or published app was changed by
this ownership refactor.
