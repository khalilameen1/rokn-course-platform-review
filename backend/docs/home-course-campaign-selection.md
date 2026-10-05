# Home course-campaign selection — local source, 4 October 2026

## Proven reference and actual reuse

[OneSignal's adoption report](https://onesignal.com/blog/record-breaking-momentum-and-exceptional-growth-in-2019/)
states that more than 10,000 companies used its In-App Messaging in 2019 and
names shipped education/training applications. This is historical adoption
evidence, not a current customer count or a Rokn performance measurement.
Its public [InAppBackendService.listInAppMessages](https://github.com/OneSignal/OneSignal-Android-SDK/blob/main/OneSignalSDK/onesignal/in-app-messages/src/main/java/com/onesignal/inAppMessages/internal/backend/impl/InAppBackendService.kt)
fetches an identity-specific IAM list independently of a generic inbox or the
screen's loaded catalogue. This unit follows that architectural separation.

No suitable provider-independent source implementation of Rokn's Laravel course
rights, dashboard campaign bindings and one-popup-per-launch decision was found.
The OneSignal SDK/protocol is not copied or installed. Its backend identity,
delivery and HTML renderer would replace, not integrate with, Rokn's existing
owners. The product-specific adapter is written Rokn integration.

Actual code reuse: the existing Laravel cursor paginator and Eloquent queries,
CourseCatalogueQueryService.constrainPublic, CourseEntitlementService's batched
captured-rights reader, the existing notification presentation/HTTPS image rules,
dashboard Course morph binding and the launch presentation-session owner. No
new package, inbox, campaign table, delivery job, write endpoint or cache exists.

## One opt-in presentation contract

GET notifications with surface=home and pagination_mode=cursor is a read-only
slice of the authenticated learner's existing delivered inbox. General inbox,
show and mark-read contracts remain unchanged, including v60 consumers.
The Home response explicitly echoes its surface; a new phone rejects an older
server silently ignoring it rather than reverting to first-page guessing.

Unread new_course, course_promotion (the dashboard's actual promotional type)
and bound legacy course_recommendation rows are candidates. They must reference
a real Course. Existing delivery targeting is retained; Home does not manufacture
recipients from undelivered campaigns. Link-only unbound rows remain in the inbox
but are not proof of a current eligible course. The current production/dashboard
new-course and promotion producers already bind Course::class and its ID.

The shared public boundary removes hidden, deleted, coming-soon, sectionless
and commercially unavailable courses before pagination. The same offers/news
preference used by marketing delivery prevents old rows interrupting Home after
opt-out; OS push permission remains independent of in-app presentation.
Per-page batched entitlements remove already-owned learning access, including
captured grant/free rights and the canonical financial-hold rules. No popup
reader decides paid status or grants access independently.

Ownership can leave an empty presented page. Its cursor/has-more metadata belongs
to the underlying ordered candidate rows, not the post-filtered cards. Home
continues empty or locally seen pages until the first eligible unseen card or
the terminal cursor. It detects repeated cursors and aborts retired focus/account
requests. It does not impose another first-thirty cutoff or drain popup queues.
Pagination is captured from models before ResourceCollection can mutate a
paginator's collection. Eligibility also finishes before resource conversion.

Each presented row adds home_course containing only canonical ID, current course
title and current HTTPS cover. Authored historical inbox title/body/art remain
unchanged. The mobile validates identity/kind/read state and that the summary ID
equals canonical course_id. It has no dependency on Home's loaded course list.
The approved badge and start-course CTA remain unchanged, as do installation
gift timing, login suppression, automatic reward grants and one launch slot.

Dashboard course publishing/hiding and existing campaign audience/type/binding
already supply this contract; no pretend new dashboard switch was introduced.
Editing the course's title/art is reflected at the next Home read, while changing
historical campaign copy does not overwrite the current course card.

## Deferred acceptance

Authored, not executed: real migrated HTTP/resource pagination beyond unrelated
inbox rows, current course art vs authored inbox copy, unavailable/deleted/owned
and expired courses, empty owned page followed by an eligible older page, opt-out,
authentication, explicit cursor surface and unchanged legacy response. Mobile
cases cover thirty locally seen candidates, empty-page continuation, repeated
cursors, late focus/account retirement, strict summary contracts, one launch slot
and unchanged gift/daily-credit ownership.

No tests, typecheck, native build, push or deployment ran for this unit. Final
acceptance must execute these cases and exercise a dashboard-created delivered
course promotion against the matching API and signed app, including v60 inbox
compatibility. Independent source review is not live/runtime acceptance.

Independent source review accepted this unit after correcting ResourceCollection's
paginator mutation ordering and the owned-page fixture's missing captured plan
snapshot. The subsequent review also accepted cursor-only Home validation and
marketing opt-out behavior. This is scoped contract/ownership acceptance, not
executed test evidence, production deployment or acceptance of the whole goal.

Separate local source unit: dismissal now persists its seen receipt independently
of the network read ACK. This selection unit does not claim that correction or
its runtime acceptance; see
[Home campaign dismissal](../../mobile/docs/home-campaign-dismissal.md).

## Final gate — legacy SQLite fixture columns, 5 October 2026

The real migrated campaign suite initially failed six of seven cases while
creating courses without the legacy required `tenant_id`. The SQLite migration
retains that column, whereas the production MySQL migration drops it. A local
fixture helper now supplies the legacy tenant only when the column exists,
including the two enrollment fixtures that previously hardcoded it. No global
factory, schema or presentation/eligibility code changed. Independent review
accepted the limited correction. Five cases now pass; two localized-title
expectations remain open.
Evidence: `mobile/.cache/final-gate-20261005/backend-home-campaign-fixture-recheck.log`.
This is not MySQL, native campaign presentation or full-project acceptance.

The suite now sends the real mobile transport's `Accept-Language: ar` header.
Symfony's synthetic request defaults to English, which correctly overrides a
test's earlier application-locale setting in the existing API middleware.
Production localization was unchanged. An added HTTP case requests English
then Arabic, verifies both response language and the live course title, and
keeps the originally authored campaign translations intact. Independent review
accepted this limited fixture correction and the real middleware coverage.
All eight cases now pass with 61 assertions; PHP syntax validation also passed.
Evidence: `mobile/.cache/final-gate-20261005/backend-home-campaign-locale-final.log`.
This does not establish MySQL locking, native pixels or delivered dashboard
campaign acceptance; the full project gate is still incomplete.
