# Notification authoring input

`NotificationsController` owns request validation, authenticated author identity,
receipt completion and HTTP redirects. Its dependencies are required injected
arguments, not nullable fallbacks to the global container. Existing route
permissions and rate limits remain unchanged.

`Data/NotificationAuthoringInput` snapshots only the validated authored fields and
optional uploaded image. The author ID is supplied separately by the authenticated
caller, never copied from a posted author field. It does not normalize links,
select recipients, grant permission, or call the database. The application owns
copy fallbacks, course readiness, target validation, schedule conversion, delivery
identity, image replay, tracked file cleanup and construction of the existing
`NotificationCampaignIntent`.

`AdminNotificationCampaignAuthoringService` has no Request, controller, route
helper or HTTP receipt adapter dependency. The HTTP owner supplies a completion
callback. On creation, that callback runs inside the same transaction as the
campaign; failure rolls it back and prevents after-commit queue dispatch. On an
already committed replay the existing immutable payload is checked first and
completion is repeated without another image write or delivery job. A callback
must propagate its failure. This preserves the existing replay behavior rather
than introducing a second creation path.

The downstream campaign/delivery policy, job payload, UI copy, image identities,
quiet hours and storage compensation remain unchanged. The earlier image retry
tests now supply input plus their real HTTP receipt callback; all their cleanup
interleaving and replay assertions are retained. Direct controller tests use
container method injection, matching route execution, instead of relying on
optional service resolution inside the controller.

Verification combines a pure input test, real-migration application tests and a
real HTTP receipt failure/retry test. Application tests forbid resolving HTTP
owners, check rollback and dispatch boundaries, reject unavailable recipients,
check schedules and replay, and retain existing image/broadcast/certificate
coverage. The tests fake queue/network transport and are not live-device delivery
verification.

## Final local browser gate — 5 October 2026

The existing notification-recipient regression renders the actual create Blade
and its included production draft script with Laravel, then serves the rendered
pages in fresh headless Chrome contexts. All five cases passed: student-to-
student isolation, individual-to-broadcast isolation, broadcast-to-individual
isolation, per-student restoration, and same-recipient reload/course-search
preservation. Evidence is
`mobile/.cache/final-gate-20261005/dashboard-notification-authoring-recipient-browser-runtime-final.log`.
The initial browser log failed during PHP rendering because the default PHP
configuration lacked mbstring; configuring the existing pinned PHP 8.4.24 with
its normal extensions resolved that environment failure without source edits.

Independent read-only review accepted this bounded recipient/draft evidence.
It noted two limits: search does not separately reassert `message_ar`, and the
broadcast-to-student case does not separately reassert `course_id`. Actual
recipient-owned draft source supports those fields; the result is not proof of
unasserted outcomes. Fixture students and a local page server replace the
authenticated HTTP route, the outer dashboard layout is omitted, and no push
or notification dispatch occurs. No build, deployment or store change was made.
