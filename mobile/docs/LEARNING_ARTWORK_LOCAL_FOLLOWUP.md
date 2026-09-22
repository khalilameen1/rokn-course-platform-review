# Local learning surfaces and dashboard artwork

Date: 2026-09-20

## Scope and release boundary

Implementation in `E:/Rokn/rokn-course-platform-review` only. No push, deployment,
Google Play upload, production migration, device installation, or change to
reviewer build 60. Earlier checkout, rewards, certificate and project-report
changes in this worktree are preserved.

## Learner surfaces

- Enrolled course outline: one row per lesson/project, one concise status,
  module progress, and the existing server-owned gates and Reels destination.
  Project requirements remain inside the project, not duplicated in the outline.
- Help: category choices, message, optional image, explicit diagnostics opt-in
  and send action. Character count appears near the limit. Existing drafts,
  errors, retry and submission contracts are unchanged.
- MyCorner: current and next rank first; later ranks and earned badges expand
  on demand. Rank artwork is resolved from order data, not translated titles.

## Artwork ownership

Dashboard: **إعدادات التصميم → صور التطبيق** edits the coin mark, coin stack,
and three default badges. **إدارة المستويات** still owns per-level overrides.
The editor displays the approved defaults even in an empty local database.
No destructive seed or forced replacement of existing custom level images.

The additive migration `2026_09_20_000001_add_app_artwork_to_design_settings.php`
adds five nullable URL fields. Uploads reuse tracked public storage, authoring
receipts, transactional saves, stale-editor protection and deferred cleanup.
References to the five fields are protected from deletion. Editor identity now
includes content to detect concurrent saves within one timestamp second.

The public settings response adds `artwork` without removing fields or changing
the installed-client contract version. Its revision changes with artwork edits.
Level defaults share the same service and use one settings query per request.
Profile earned badges include an additive numeric `order` field.

App root loads public settings once per foreground activation using the existing
shared cache (60-second fresh TTL, up to 24-hour offline fallback). Individual
images do not start network settings requests. Image priority is:

1. Per-level image URL
2. Dashboard default
3. Approved bundled image if unavailable

Uploads have unique storage paths. A new URL resets failed-image state. Bundled
artwork also provides a loading placeholder. Public artwork uses the existing
HTTPS media policy; a local integration host must provide HTTPS URLs reachable
by the device. No production endpoint or credentials were copied to a new setup.

## Approved files

Backend serves `public/assets/app-artwork/v1/` with `coin.png`, `coin-stack.png`,
`junior.png`, `mid-level.png`, and `senior.png`.

Badges are copied byte-for-byte from the approved `output/dashboard-badges-2026-09-20`
files: Junior white v3 printed, Mid-level silver v3 printed, Senior gold v4 warm
print. No bars/numbers beneath the mark. Mobile offline copies use the
`*-printed.png` names. Coin originals remain unchanged.

## Verification

- Mobile full suite: 285 suites / 2203 tests passed
- TypeScript: passed
- Scoped ESLint: checked on implementation and new tests
- Backend selected suite: 49 tests / 756 assertions passed
- Backend coverage includes dashboard rendering, five uploads, public settings,
  defaults, custom level precedence, reference protection, stale editor rejection,
  same-second conflict handling, profile/learning endpoints and admin pages
- Backend tests use isolated in-memory SQLite and temporary storage
- No native-device visual pass or installed build was performed in this turn

The migration has been exercised in the isolated test database, not applied to
a persistent local or production database. No backend `.env` exists in this
checkout. Before running the integrated local stack, provision its own local
database/storage and HTTPS origin and apply pending local migrations there.
Do not point that setup at the reviewer server. Publishing remains a separate
explicitly authorized step.
