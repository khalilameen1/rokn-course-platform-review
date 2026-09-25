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
