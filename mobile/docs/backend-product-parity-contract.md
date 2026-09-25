# Backend and dashboard contract for playback parity

Status: implementation contract only

The production Laravel backend and its Blade admin dashboard were found at
`../rokn-backend`. That repository is outside the writable app workspace, so
this change deliberately does not claim that the server or dashboard were
modified. The contracts below are additive and preserve every payment,
enrolment, scholarship, chat, certificate, and course-access decision already
made by the backend.

## Existing capabilities confirmed in the backend

- `GET /api/v1/learning/courses` returns one authenticated progress snapshot
  for all active enrolments.
- `GET /api/v1/user/watch-history` and
  `POST /api/v1/user/watch-history` persist bounded resume positions without
  changing academic completion.
- `DELETE /api/v1/user/watch-history` clears resume history while preserving
  course progress, projects, certificates, and rewards.
- `PUT /api/v1/user/profile` already persists `watch_history_enabled`,
  `notifications_status`, `marketing_notifications_enabled`, and
  `preferred_locale`.
- `GET /api/v1/courses/list?search=...` already searches published course
  titles and is the endpoint used by the mobile app.
- `POST /api/v1/client-events` is operational telemetry. It intentionally
  rejects free-form text and files; it must not be repurposed as feedback.
- The admin dashboard has contacts and student-progress screens, but no
  learner feedback/screenshot workflow was found.

## 1. Continue-learning dock

Extend the existing `GET /api/v1/learning/courses` item. Do not add one request
per course and do not expose a video URL from this endpoint.

### Additive response fields

```json
{
  "course_id": 42,
  "title": "من الصفر إلى أول عميل",
  "image": "https://cdn.example/course.webp",
  "progress_percentage": 36.67,
  "completed_sections": 11,
  "total_sections": 30,
  "is_completed": false,
  "access_type": "paid",
  "chat_available": true,
  "certificate_available": true,
  "last_activity_at": "2026-08-10T18:33:51+02:00",
  "resume": {
    "available": true,
    "lesson_id": 901,
    "course_section_id": 1337,
    "lesson_title": "حوّل خبرتك إلى عرض واضح",
    "lesson_thumbnail": "https://cdn.example/lesson.webp",
    "section_order": 12,
    "position_seconds": 41,
    "duration_seconds": 76,
    "progress_percentage": 53.95,
    "watched_at": "2026-08-10T18:33:51+02:00"
  }
}
```

Rules:

1. `resume` is `null` when watch history is disabled or no valid watch row
   exists. The course-level academic progress remains present.
2. Use the newest row per `(user_id, course_id)` ordered by
   `COALESCE(watched_at, updated_at) DESC, id DESC`.
3. Ignore a watch row whose lesson or course section was deleted, unpublished,
   or is no longer accessible to the learner.
4. Never infer academic completion from `position_seconds`. Completion remains
   owned by `student_section_progress` and the existing learning-evidence flow.
5. `last_activity_at` is the greatest of the latest watch time and the latest
   academic section progress time. It is used only for sorting the dock.
6. Return incomplete courses first, ordered by `last_activity_at DESC`, then
   completed courses. Do not remove completed courses from the full response.
7. The mobile client opens the normal protected lesson endpoint with
   `lesson_id`; this response must not contain Bunny tokens or URLs.

### Query shape

Fetch the latest watch rows for all enrolled course IDs in one query (window
function on MySQL 8, or a grouped max subquery on older MySQL) and eager-load
the selected lessons and sections. Avoid an N+1 query per enrolment.

The existing indexes on `(user_id, course_id, watched_at)` are sufficient for
the first release. Add a deterministic unique/index migration only after
legacy duplicate watch rows have been safely consolidated.

### Backward compatibility

The existing top-level fields and `data.items` envelope stay unchanged. Older
clients ignore `last_activity_at` and `resume`; newer clients must treat absent
fields exactly like `null`.

## 2. Playback preferences synced across devices

Keep local playback preferences as an offline-first cache, then best-effort
sync them through the existing profile endpoints. A failed sync must never
block playback.

### Fields

Add nullable/defaulted columns to `users`:

| API field | Database type/default | Allowed values |
| --- | --- | --- |
| `autoplay_next_enabled` | boolean, default `true` | `true`, `false` |
| `video_quality_preference` | string(16), default `auto` | `auto`, `data_saver`, `240p`, `360p`, `480p`, `720p`, `1080p` |
| `video_fit_mode` | string(12), default `cover` | `cover`, `contain` |
| `playback_speed` | decimal(3,2), default `1.00` | `0.50`, `0.75`, `1.00`, `1.25`, `1.50`, `1.75`, `2.00` |

Do not store a per-video preference row. These four account-level fields are
small and avoid unbounded storage growth.

### Read contract

Add the four fields to `GET /api/v1/user/profile` via
`StudentProfileResource`. Missing database values must be returned as the
defaults above, never as an invalid empty string.

### Write contract

`PUT /api/v1/user/profile`

```json
{
  "autoplay_next_enabled": true,
  "video_quality_preference": "auto",
  "video_fit_mode": "cover",
  "playback_speed": 1.25
}
```

Return the normal profile resource. Reject unsupported enum values with `422`.
Fields are independent and optional so a client can patch one preference.

### Client merge rule

1. On app start, render immediately from account-scoped local storage.
2. After authentication, fetch the profile once.
3. If a local preference has a pending unsynced write, retry that write; do not
   overwrite it with an older server value.
4. Otherwise apply the server value locally.
5. Guest preferences stay local and are not automatically copied to a newly
   authenticated account unless the user changes them after sign-in.

No subtitle or offline-download fields are included by product decision.

### Real media-source contract

The protected lesson/reel response may expose playback variants, but every
quality shown to the learner must resolve to a real rendition. Never generate
labels such as `720p` for one unchanged MP4 URL.

Canonical additive fields on the existing protected lesson payload:

```json
{
  "video_url": "https://stream.example/master.m3u8",
  "available_qualities": ["auto", "1080p", "720p", "480p", "360p"],
  "quality_sources": {
    "1080p": "https://stream.example/video-1080.mp4",
    "720p": "https://stream.example/video-720.mp4",
    "480p": "https://stream.example/video-480.mp4",
    "360p": "https://stream.example/video-360.mp4"
  },
  "fallback_video_url": "https://backup.example/master.m3u8"
}
```

Rules:

1. For HLS/DASH, `video_url` is the adaptive master manifest and
   `available_qualities` lists only renditions present in that manifest.
   `quality_sources` may be omitted when the native player can select those
   tracks from the manifest.
2. For a fixed MP4, `quality_sources` is required to advertise any manual
   quality. Its keys are the only manual qualities the client displays.
3. `auto` always means the adaptive master when one exists, otherwise the
   original `video_url`. It is not a separately encoded file.
4. `fallback_video_url` is optional and must be the same lesson content on an
   independently useful delivery path. It inherits the same entitlement,
   expiry, watermark, and signed-URL policy as the primary source; it must not
   bypass course access.
5. All variant and fallback URLs are issued by the protected lesson endpoint.
   The catalogue, search, and continue-learning endpoints never expose them.
6. The dashboard must show rendition state (`processing`, `ready`, `failed`),
   derive `available_qualities` from ready outputs, and never let an editor
   type a quality label with no associated rendition.
7. Existing aliases may be accepted while migrating (`quality_urls`,
   `video_variants`, `renditions`, `sources`, `backup_video_url`), but the API
   resource must emit the canonical fields above.

The app may retry a lower real rendition or `fallback_video_url` after a
bounded playback failure. It must preserve the current position and must not
download the video to device storage.

## 3. Learner feedback with one optional screenshot

This is a product-support record, not telemetry. Keep it separate from
`client_events` and from project-evaluation feedback.

### Public/authenticated write endpoint

`POST /api/v1/feedback`

- Content type: `multipart/form-data`
- Authentication: optional bearer token. Associate `user_id` only when a
  valid token is present.
- Throttling: `5` submissions per 10 minutes per authenticated user; anonymous
  requests use the existing privacy-safe rate-limit key.

Fields:

| Field | Requirement |
| --- | --- |
| `category` | required enum: `playback`, `course_content`, `account`, `payment`, `bug`, `suggestion`, `other` |
| `message` | required UTF-8 string, 10..2000 characters |
| `screen_key` | nullable, max 64, pattern `[a-z0-9._-]+` |
| `course_id` | nullable existing course ID |
| `lesson_id` | nullable existing lesson ID; if both IDs are sent, the lesson must belong to the course |
| `client_event_id` | nullable UUID, for correlation only |
| `app_version` | nullable, max 32 |
| `build_number` | nullable positive integer |
| `platform` | required enum: `android`, `ios` |
| `os_major` | nullable integer 1..255 |
| `locale` | nullable BCP-47 language tag, max 16 |
| `screen_size` | nullable logical viewport in `WIDTHxHEIGHT` form, max 24 |
| `font_scale` | nullable decimal 0.5..4.0 |
| `device_tier` | nullable enum: `low`, `mid`, `high`, `unknown` |
| `network_type` | nullable enum: `wifi`, `cellular`, `ethernet`, `offline`, `unknown` |
| `screenshot` | nullable JPEG, PNG, or WebP; maximum 4 MiB and 4096 px on either edge |

The current app-facing labels map to the stable API taxonomy before upload:
`problem -> bug`, `idea -> suggestion`, `content -> course_content`, and
`playback -> playback`. The client sends the originating route as the sanitized
`screen_key`; it never sends a navigation URL or prior chat history.

Success response (`201`):

```json
{
  "status": 201,
  "success": true,
  "message": "وصلتنا ملاحظتك",
  "data": {
    "id": "fdb_01J5...",
    "status": "new",
    "created_at": "2026-08-10T18:45:00+02:00"
  }
}
```

### Storage model

`feedback_reports`

- `id` bigint primary key
- `public_id` ULID unique
- `user_id` nullable foreign key with `nullOnDelete`
- `category` string(24)
- `message` text
- `status` string(16), default `new`; allowed `new`, `triaged`, `resolved`,
  `closed`
- `priority` string(12), default `normal`; allowed `low`, `normal`, `high`
- nullable context columns from the request above
- `assigned_to` nullable admin user ID
- `resolved_at` nullable timestamp
- timestamps
- indexes: `(status, created_at)`, `(category, created_at)`,
  `(user_id, created_at)`, `client_event_id`

`feedback_attachments`

- `id`, `feedback_report_id`
- `storage_disk`, `file_path`, `mime_type`, `byte_size`, `width`, `height`
- `sha256` char(64)
- timestamps

Only one attachment is accepted in this version. Store it on a private disk,
decode and re-encode the image to strip EXIF/metadata and polyglot payloads,
generate the filename server-side, and never return `file_path` to the app.
If image persistence fails, roll back the report write or return a clear `422`;
do not create an apparently successful report with a silently missing image.

Do not collect arbitrary device IDs, advertising IDs, stack traces, full URLs,
IP addresses in application columns, contacts, or chat/playback content.
The picker URI is session-only: the app uploads the selected image directly
and does not copy it into persistent app storage. The server retains only the
submitted report and its optional scrubbed screenshot under the retention
policy configured for support data.

### Dashboard contract

Add a protected `admin.only` feedback section:

- list with status, category, priority, app version, course, date, and whether a
  screenshot exists;
- filters for status, category, app version, course, and date range;
- detail view with message, safe context metadata, private screenshot preview,
  related client-event link when present, and audit timestamps;
- actions to assign, set priority, mark triaged/resolved/closed;
- private attachment response with `Content-Disposition: inline`, CSP-safe MIME,
  `X-Content-Type-Options: nosniff`, and an authorization check on every read.

The list must not show raw storage paths and must escape every learner-provided
string. Deleting a feedback report must enqueue attachment deletion and remain
auditable; it must not touch the learner account or course progress.

## 4. Compact progressive course search

The current `GET /api/v1/courses/list?search=...` remains supported. Add a
compact endpoint so typing in search does not hydrate modules or sections and
does not make the catalogue response contract ambiguous.

`GET /api/v1/search/courses`

Query parameters:

| Parameter | Rule |
| --- | --- |
| `q` | required after trim, 2..120 characters |
| `page` | integer, default 1 |
| `per_page` | integer 1..20, default 10 |
| `classification_id` | nullable existing classification ID |
| `course_type` | nullable existing supported course type |

Search visible/published catalogue courses only. A coming-soon course may be
returned only when it is already visible under the existing catalogue scope.
No entitlement, payment, lesson URL, module, project, or attachment data belongs
in this response.

Search these fields:

1. exact/prefix course title in the active locale;
2. title in the fallback locale;
3. teacher name;
4. classification name;
5. explicit dashboard-managed search keywords.

Ranking must be deterministic: exact title, title prefix, title contains,
teacher/classification/keyword match, `is_main_course`, `home_sort_order`, then
course ID. Normalize Arabic presentation differences for matching (`أ`, `إ`,
`آ` to `ا`; `ى` to `ي`; remove tatweel and diacritics) without altering the
stored/displayed title.

Response:

```json
{
  "status": 200,
  "success": true,
  "data": {
    "query": "تصميم",
    "items": [
      {
        "course_id": 42,
        "title": "أساسيات التصميم لغير المصممين",
        "image": "https://cdn.example/course.webp",
        "teacher_name": "ليلى عادل",
        "badge": "قريبًا",
        "is_coming_soon": true,
        "preview_count": 0,
        "ratings_count": 186,
        "rating_average": 4.9,
        "students_count": 321
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 10,
      "total": 1
    }
  }
}
```

`students_count` must use the same dashboard baseline-plus-real-enrolment rule
as course details. Do not expose a course price in search/home cards.

### Operational rules

- Add a named `catalog-search` rate limit. A suggested starting point is 60
  requests/minute per authenticated user or privacy-safe anonymous key.
- The mobile client debounces by 250--350 ms, cancels stale requests, and shows
  the newest successful query only.
- Do not persist arbitrary search-result pages on the phone.
- Do not create an unbounded cache entry for every query. Cache only catalogue
  metadata; search the current visible set or use a bounded server cache.
- The initial catalogue is small enough for a normalized database query. Before
  the visible catalogue exceeds roughly 2,000 courses, benchmark production
  query plans and introduce a token/full-text index or dedicated search engine
  only if measured latency requires it.
- Return `422` for invalid parameters, an empty `items` list for no matches,
  and a stable generic `500` body while reporting internal failures server-side.

### Dashboard fields

Add `search_keywords_ar` and `search_keywords_en` to the course form. They are
optional comma/newline-separated editorial synonyms, are never displayed to
learners, and must be length-limited (suggested 1,000 characters each). Updating
them must bump the same catalogue revision used to invalidate course caches.

## 5. Required backend file map

This is the minimum implementation surface in the sibling Laravel repository:

- migration: playback preference columns on `users`;
- protected lesson resource plus rendition/fallback metadata and dashboard
  processing-state fields for the real media-source contract;
- migration/model/controller/request/resource for feedback and its attachment;
- `ProfileController` and `StudentProfileResource` for playback preferences;
- `LearningDashboardController` for the one-query resume projection;
- a compact course-search controller/request/resource plus API route;
- course model/admin request/form for the two search keyword fields;
- admin feedback controller, routes, list/detail views, and private attachment
  action;
- feature tests for every contract below.

No change is authorized here to `CoursePurchaseController`, `PaymentController`,
`WalletService`, `CourseEntitlementService`, `CertificateService`, course codes,
financial holds, or any enrolment/authorization middleware.

## 6. Acceptance tests

1. Continue-learning returns at most one latest resume item per enrolled course
   without an N+1 query pattern.
2. Disabling watch history makes `resume` null but does not reduce academic
   progress or revoke an earned reward/certificate.
3. A deleted/unpublished/inaccessible lesson is never offered as a resume
   target.
4. Playback preferences round-trip and invalid values return `422`.
5. A fixed MP4 exposes no manual quality unless that quality has a distinct
   ready URL; adaptive streams expose only manifest-backed renditions.
6. A fallback source never bypasses lesson entitlement and a retry preserves
   playback position.
7. Playback remains usable when preference sync times out.
8. Feedback works with and without a screenshot and rejects disguised or
   oversized files.
9. Anonymous feedback is throttled; authenticated feedback is associated with
   the user without accepting a client-supplied `user_id`.
10. Admin screenshot access is denied to non-admin accounts and never reveals a
   storage path.
11. Arabic search normalization matches equivalent alef/yaa spellings and never
   changes displayed copy.
12. Search never returns drafts, protected lesson URLs, prices, projects, or
    attachments.
13. Old clients continue to consume `learning/courses`, `user/profile`, and
    `courses/list` unchanged.
14. Payment, scholarship, chat, certificate, and course-access feature tests
    remain byte-for-byte behaviorally unchanged.
