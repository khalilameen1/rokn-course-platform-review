# Notification campaign boundaries

## Authored intent and recipient selection

`NotificationCampaignIntent` is immutable authored input. All production callers
construct it with named arguments: notification identity, bilingual copy, target,
presentation hints, requested time and author cannot be confused by positional
arguments or an open generic payload. It canonicalizes the delivery key once and
copies a mutable requested time into a `DateTimeImmutable`. It does not render,
query models, select recipients or send jobs.

`NotificationAudience` owns selector names, the explicit-ID limit, normalization
and admission validation. Course audiences stay lazy selectors, not materialized
enrollment IDs. Its recipient comparison accepts the same normalized legacy
arrays as before; a replay cannot change or expand the stored audience. Delivery
jobs use its selector constants but still recheck current recipients and access
at delivery time. Neither job serializes either new input object.

## Persistence, presentation and delivery

`NotificationCampaignService::queue` takes one intent. It projects presentation
through `StudentNotificationPresentationService`, applies quiet hours through
`NotificationDeliveryPolicy`, persists the campaign and dispatches its existing
delivery-key-only job after commit. Retry/claim behavior and job payloads are
unchanged. A broker outage leaves the committed failed campaign for recovery;
a rollback cannot send a job for a campaign that never committed.

The first committed link, button labels, image and schedule remain authoritative.
Recomputing a link after course withdrawal must not manufacture a payload
conflict. Explicit image changes, authored copy, type, bound target, author or
recipient changes still conflict under the same delivery identity. A missing
image on replay must not erase the stored image.

`AdminNotificationCampaignAuthoringService` injects the campaign owner directly.
It accepts a validated `NotificationAuthoringInput` without an HTTP Request and
constructs the delivery intent within its tracked-upload cleanup boundary.
Image replay, scheduling validation and recipient/course checks remain its own
responsibility. The controller owns request validation, authenticated author
identity and the HTTP receipt callback; creation invokes that callback in the
campaign transaction. See `notification-authoring-input.md` for that boundary.
The old array-to-positional `notifyGeneric` adapter is removed.

`CourseContentNotificationService` replaces the misleadingly generic
`NotificationService`: it only produces new-course, course-update and lesson
campaigns from authored templates. Disabled-template/publication checks are
unchanged. The unused course-update-type argument is removed; publication
callers pass the real delivery identity explicitly. There are no compatibility
forwarders left behind.

## Verification

- `NotificationCampaignIntentTest` exercises pure inputs without a Laravel app:
  bounded normalized IDs, valid/lazy selectors, immutable arrays/schedules,
  stable key normalization and unmodified authored bilingual copy.
- `NotificationCampaignIntentWorkflowTest` uses real migrations and commit
  boundaries for exact persisted fields, audience/content conflicts, committed
  presentation replay, quiet hours, rollback and broker failure/recovery.
- Existing notification/certificate workflow, admin parity, image retry,
  course publication, staged authoring, delivery hardening and endpoint tests
  retain their behavioral assertions. Source contracts follow the real renamed
  content producer rather than an obsolete forwarding class.

No database schema, mobile payload, notification text or queue job constructor
was changed by this refactor. These tests do not replace live-device push checks.
