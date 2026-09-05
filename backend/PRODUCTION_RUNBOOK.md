# Rokn production runtime

This backend is prepared for the first production load, but capacity is an operational property: do not estimate a 1.6M-person spike from download count alone. Load-test the home, course-details, progress, wallet and sign-in routes against a staging copy before a large campaign.

## Required production topology

- PHP 8.4.24 or newer within the PHP 8.4 release line, with OPcache; managed MySQL 8.0.17 or newer (8.0.43 is the
  release-tested target), and Redis shared by every app instance. The plan
  snapshot integrity constraints use enforced JSON schema checks unavailable
  on older MySQL releases.
- `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning`.
- Set `APP_TIMEZONE=UTC`, `DB_TIMEZONE=+00:00`, and
  `BUSINESS_TIMEZONE=Africa/Cairo`. Persistence, queues, expiry checks, and
  inter-service timestamps stay UTC; only business-day boundaries and
  dashboard datetime-local values use Cairo. The production preflight rejects
  a mixed configuration.
- `CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`.
- Set `MOBILE_RELEASE_REQUIRED_CHANNELS` to only the channels promoted in this
  release. The initial direct Android release uses `direct`: it requires the
  direct APK, Android App Links and live Kashier, but deliberately does not
  require Play Console or App Store credentials. Add `play` or `appstore` only
  when that store's product records, server verification and official URL are
  ready; every declared channel remains a hard launch gate.
  Social login is declared independently by `SOCIAL_AUTH_PROVIDERS`; keep
  Apple out of that list until its login identifiers are ready, then add it in
  the same release that exposes the Apple button.
- Start from `.env.production.example`; it contains variable names only and no
  usable credentials. Set `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`,
  `REDIS_DB`, and `REDIS_CACHE_DB`, and point every web/worker node at the same
  Redis service.
- Run one scheduler process (`php artisan schedule:work`) and isolated workers
  for `default`, `notifications`, `ai-chat`, `ai-feedback`, `media`, `operations`, and `webhooks`.
  Never let slow provider, object-storage, certificate-rendering or operational-mail
  work occupy the default queue. Baseline workers are
  `php artisan queue:work redis --queue=default --sleep=1 --tries=3 --timeout=300 --backoff=15 --max-jobs=1000 --max-time=3600` and
  `php artisan queue:work redis --queue=notifications --sleep=1 --tries=3 --timeout=120 --backoff=15 --max-jobs=1000 --max-time=3600`.
  Keep interactive course chat ahead of longer project reports with
  `php artisan queue:work redis --queue=ai-chat --sleep=1 --tries=3 --timeout=90 --backoff=5 --max-jobs=1000 --max-time=3600`.
  A baseline project-feedback worker command is
  `php artisan queue:work redis --queue=ai-feedback --sleep=1 --tries=3 --timeout=90 --backoff=20 --max-jobs=500 --max-time=3600`;
  run media/storage/certificate work with
  `php artisan queue:work redis --queue=media --sleep=1 --tries=12 --timeout=180 --backoff=15 --max-jobs=500 --max-time=3600`;
  run durable operational signals, maintenance and alert mail with
  `php artisan queue:work redis --queue=operations --sleep=1 --tries=3 --timeout=300 --backoff=15 --max-jobs=500 --max-time=3600`;
  run webhook deliveries separately with
  `php artisan queue:work redis --queue=webhooks --sleep=1 --tries=5 --timeout=90 --backoff=10 --max-jobs=1000 --max-time=3600`;
  the Redis `retry_after` must remain greater than every worker and job timeout
  (currently 360 > the 300-second token-pruning job). Start with at least two workers for `default` and `notifications`;
  scale `ai-chat` for response latency and scale `ai-feedback` and `webhooks`
  separately within their provider budgets.
  Keep `QUEUE_HEARTBEAT_REQUIRED_QUEUES=default,notifications,ai-chat,ai-feedback,media,operations,webhooks`
  aligned with this topology; `/api/health/launch-ready` requires a recent
  heartbeat executed by a worker on every listed queue.
- Serve every reel and thumbnail from Bunny CDN. Never proxy video bytes through Laravel.
- On a fixed origin, put only the actual reverse-proxy IPs or narrow CIDRs in
  `TRUSTED_PROXIES`, leave `TRUSTED_PROXIES_ALLOW_DYNAMIC_EDGE=false`, and
  allow only those sources through the firewall. Laravel Cloud keeps the app
  origin private while its edge addresses may rotate; there use
  `TRUSTED_PROXIES=*` together with
  `TRUSTED_PROXIES_ALLOW_DYNAMIC_EDGE=true`. Never enable that pair on an
  origin reachable directly from the internet. Correct forwarded client IPs
  are required for throttling, secure URLs and audit evidence.
- Set `PROJECT_SUBMISSION_DISK` and `CERTIFICATE_DISK` to a private shared
  filesystem disk before running more than one app node (for example `s3`,
  configured by `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
  `AWS_DEFAULT_REGION`, and `AWS_BUCKET`). Each submission records the disk
  used at upload time, so later changes do not orphan existing files. Legacy
  `local`/`public` consumers read `SHARED_STORAGE_PATH` and
  `SHARED_PUBLIC_STORAGE_PATH`; mount those durable paths identically on every
  node.
- Keep `FEEDBACK_STORAGE_PATH` on that same private shared mount and set
  `FEEDBACK_SHARED_STORAGE=true` only after every API node can read and write
  it. Feedback screenshots must survive deploys and must never use the public
  disk.
- Keep `ROKN_SEED_DEMO=false`. Fixture seeders reject every environment except
  `local` and `testing`, including when invoked directly with `--class`.

## Deploy order

Keep Laravel Cloud **Push to deploy** disabled for production. A push starts
Backend CI, not a release. Promote only the exact commit with a successful
Backend CI result, then verify Cloud deployed that commit. The production
environment currently uses manual promotion; CI-triggered deployment is not
configured yet. Do not describe the runbook alone as an enforced automated gate.

The old web release and its workers remain alive while the database expands. A
release migration must therefore add nullable/defaulted columns and accepting
constraints first; destructive renames, drops, type narrowing, and removal of
old JSON/enums belong in a later contract release after every old process is
gone. Application rollback is forward-only: route traffic back to the previous
artifact while retaining the expanded schema. Never run migration `down()` on
production as an application rollback.

1. Build an immutable release artifact without routing production traffic to it. Install production dependencies with an optimized autoloader and warm only artifact-local files; do not clear the shared application cache.
2. Take a database snapshot. Before changing an existing deployment from a non-UTC application clock, audit representative timestamp rows and document whether the database stored UTC instants or local wall times; never bulk-shift mixed historical data without that evidence.
3. From the candidate artifact, run `php artisan rokn:preflight --configuration-only --connectivity`. Do not continue while it reports a missing, placeholder, local-only, or unreachable dependency.
4. Run `php artisan rokn:release-migrate` once from the candidate artifact while the compatible old release continues serving traffic. It applies `migrate --isolated --force` with a bounded MySQL metadata-lock wait, then runs the schema/connectivity gate. `--isolated` requires the shared Redis cache. Never start it from a second node. If interrupted, retain the same artifact, diagnose the failed statement, and rerun: the forward tail is designed to resume after partially committed MySQL DDL.
5. Before switching traffic, confirm the command's `rokn:preflight --schema-only --allow-mixed-release --connectivity` stage passed. This proves that its migration ledger, required tables, required columns, database, cache, and shared storage match the code that will receive traffic while explicitly deferring old-writer backfills until the drain step.
   For the first direct release, publish the final APK at its immutable
   `https://rokn.app/...apk` URL, then create the initial release record once:
   `php artisan app-release:bootstrap-direct --version-name=1.0.0 --version-code=1 --download-url=https://rokn.app/downloads/Rokn-direct.apk --activate`.
   The command is production-only, requires `direct` to be declared, validates
   the same host/build monotonicity rules as the dashboard, is idempotent for
   the exact same release, and never rewrites an existing row. Later releases
   should be managed from the dashboard.
6. Privatize legacy learner assets before serving the new release:
   - Run `php artisan attachments:privatize` to audit module attachments.
   - Run `php artisan attachments:privatize --execute --delete-public`. The command copies each file, verifies the private copy exists with the same byte size, updates the database, and only then removes its public source.
   - Run `php artisan attachments:privatize` again; it must report no legacy public module attachments.
   - Run `php artisan security:quarantine-profile-svg`, then `php artisan security:quarantine-profile-svg --execute`, then the audit command again; it must report zero local SVG profile images.
   - Resolve every duplicate Bunny object key reported for portfolio images or lesson thumbnails by re-uploading each affected record. A shared key means deleting one record could delete another record's media.
   - Run `php artisan rokn:preflight --allow-mixed-release --connectivity` again. This pre-switch gate still fails while legacy exposure remains, but permits only the bounded old-writer backfills that step 9 closes; never use this flag after traffic switches.
7. Do not run `db:seed` on production. A disposable showcase environment may
   enable both demo flags for a one-time seed, then must disable them before it
   serves traffic.
8. Run `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache` inside the candidate artifact, then atomically switch web traffic to it. Do not run `optimize:clear` after the switch.
9. Immediately signal `php artisan queue:restart`, then confirm every supervised queue reports a new heartbeat from the candidate revision. Wait for the previous release's maximum request/job timeout so its in-flight work has drained, then run `php artisan rokn:release-finalize --old-workers-drained`. This closes exam snapshots, saved-folder normalization and course-search rows written in the old shape after the pre-switch backfill; it never deletes an attempt whose source quiz was removed.
10. Repeat `php artisan rokn:preflight --schema-only --connectivity` and confirm `/api/health/launch-ready` before promotion.
11. Keep the scheduler active on exactly one node. Do not start a second scheduler during the release. Distributed scheduler locks require the shared Redis cache.
   The scheduler releases abandoned AI reservations every minute; disabling it
   can leave learner allowances reserved after a killed worker.

If a post-switch readiness or smoke check fails, move traffic back to the old
artifact and restart workers on that artifact. Keep the expanded database in
place, repair forward, and redeploy. Schema rollback is allowed only in an
offline restore rehearsal with explicit evidence that no new-shape rows exist.

The August 5 migrations add the hot-path indexes, collapse duplicate section progress before enforcing uniqueness, classify unknown legacy wallet balances as reward coins, and add immutable paid/reward attribution to course orders. Do not manually classify legacy coins as purchased revenue.

## Minimum monitoring and scaling signals

- Alert on API p95 latency, 5xx rate, MySQL connections/slow queries, Redis
  errors, failed jobs, and disk/object-storage errors. Track oldest-job age per
  queue, not as one aggregate: page immediately when `default` or
  `notifications` age exceeds 60 seconds, when `webhooks` exceeds two minutes,
  when `media` or `operations` exceeds two minutes, or when `ai-feedback`
  exceeds five minutes. Alert if any `ai_usage_events` reservation remains reserved past
  `reservation_expires_at` for more than two scheduler cycles.
- Alert on Kashier callback failures separately; captured payments and coin credits are idempotent and must be replayed rather than edited in SQL.
- Alert on OpenRouter 402/429/5xx and Bunny signing/CDN failures. AI failure must not interrupt reel playback or project progression.
- Keep `ops:monitor-runtime` scheduled every minute. It persists deduplicated
  incidents for stale workers, failed jobs, ordered webhook blocks, push/AI
  dead letters, cleanup failures and payment reconciliation gaps in Product
  Operations, and repeats unresolved alerts at the configured interval. Never
  use the strict launch gate as the load-balancer health check:
  `/api/health/live` is process liveness and `/api/health/ready` is traffic
  readiness; a transient Bunny, OpenRouter, mail or payment-provider outage
  must not restart an otherwise healthy API instance.
- Do not use a blanket `queue:retry all`. Product Operations can replay a
  failed outbox event with the same event identity after the cause is fixed.
  Push claims with an unknown FCM outcome stay in the in-app inbox and are not
  blindly resent; payment and AI work must be replayed only by their own
  idempotent reconciliation path.
- Scale stateless HTTP nodes behind a load balancer only after sessions/cache and learner files are shared. Add read replicas only after measuring; wallet, progress, purchases and project transitions must always use the primary database.
- Cache home rails briefly and purge/update caches after dashboard changes. Keep CDN cache hit ratio high during campaigns.

## Smoke checks after deploy

Verify social sign-in, the two free preview reels, reward-first course purchase, purchased-coin package callback, wallet breakdown/history, project pending-to-pass transition, course completion, certificate/QR portfolio link, support WhatsApp link, notification inbox and push queue. Check one duplicate payment callback and one repeated purchase request to confirm idempotency.

## Backup and restore evidence

- Database and object-storage backups are infrastructure operations; the Rokn
  dashboard reports evidence but never starts a restore.
- Keep automated encrypted database snapshots and versioned/copy-on-write
  object storage outside the primary failure domain. Database-only backup is
  incomplete: course PDFs, certificates, submissions, feedback files, images
  and Bunny videos need their own provider retention/export policy. Never put
  application keys or provider credentials inside a backup artifact.
- `ops:checkpoint-recovery` runs every five minutes. It records a generation,
  checkpoint and encrypted probe inside the database; the probe proves that the
  restored `APP_KEY` can still decrypt production data. Keep
  `RECOVERY_ENCRYPTION_KEY_ID` with the secret inventory, not in user data.
  On the first release containing `recovery_markers`, run the checkpoint once,
  take a new post-migration snapshot, verify it and complete an isolated drill;
  the earlier pre-migration rollback snapshot cannot satisfy this gate.
- After the provider finishes a snapshot, download/export the artifact to a
  controlled operator machine and run:
  `php artisan ops:verify-backup --artifact=/absolute/backup.sql.gz --snapshot-at=2026-09-01T10:00:00Z --provider=provider-name`.
  The command reads the complete artifact, measures checkpoint lag, hashes it
  and writes HMAC-signed evidence. Preserve `RECOVERY_EVIDENCE_SIGNING_KEY`
  outside the database and outside the same failure domain. Never mark a backup
  healthy by manually editing a timestamp.
- At least every 90 days, use an isolated MySQL host and the exact artifact from
  the signed backup record:
  `php artisan ops:verify-restore --dump=/absolute/backup.sql.gz --database=rokn_restore_verify_20260901 --confirm=RESTORE_rokn_restore_verify_20260901`.
  The command refuses the primary database, verifies the artifact hash and
  encryption probe before migration, applies current migrations only to the
  disposable database, checks wallet/order/enrollment/store/certificate
  references, and samples private objects and Bunny videos. It records measured
  RPO/RTO and fails on missing objects, orphan rows, ledger drift or stale
  schema. Store the signed evidence file on durable shared storage and copy it
  with the operational evidence archive.

### Disaster recovery order

1. Set `DISASTER_RECOVERY_MODE=true` before attaching a restored database.
   Web nodes may serve existing learning data, but checkout is blocked, the
   scheduler skips mutation jobs and workers do not reserve restored jobs.
2. Do not restore Redis, sessions, cache entries or queue payloads. They are
   disposable derivatives and may contain work from a different database
   point. Start with empty Redis namespaces and keep all workers stopped.
3. Restore the database and matching object-store generation in isolation while
   preserving the correct `APP_KEY`, `RECOVERY_ENCRYPTION_KEY_ID` and separate
   evidence-signing key. Run `ops:verify-restore` against the exact candidate
   artifact. A missing marker or key mismatch is a hard stop, not a reason to
   generate a new marker.
4. Reconcile Kashier and store-provider events from the snapshot time forward
   through their existing idempotent reconciliation paths. Replay only named
   outbox/dead-letter identities after inspection; never use `queue:retry all`
   and never edit wallet/order balances directly.
   Compare the provider's object-version inventory with restored database
   references. Quarantine post-snapshot orphan objects for the normal retention
   window; never bulk-delete them during recovery because a later payment or
   database reconciliation may restore their reference.
5. Clear application/catalogue caches and apply forward migrations. While
   recovery mode remains active, inspect Product Operations recovery checks:
   the signed restore must match the selected backup artifact with zero pending
   migrations, ledger/orphan/media findings, and RPO/RTO within objectives.
6. Only then set `DISASTER_RECOVERY_MODE=false`, rebuild the configuration
   cache, and run `php artisan rokn:preflight --connectivity` plus
   `/api/health/launch-ready`. After both pass, restart workers with
   `php artisan queue:restart`, confirm fresh heartbeat timestamps and reopen
   traffic. Watch payment reconciliation,
   certificate recovery, missing-object errors and wallet deltas during the
   recovery window.

The default objectives are RPO 15 minutes and RTO 60 minutes. Change them only
from measured business tolerance and drill results. An unavailable third-party
provider can fail a drill without making ordinary HTTP liveness fail; launch and
checkout remain blocked until evidence is complete.

## Media integrity routine

- `php artisan media:reconcile --dry-run` inspects published courses without
  changing media-state rows.
- The scheduler runs `media:reconcile` once daily with distributed locks. It
  checks Bunny metadata, signed HLS readiness, duration, quality ladder,
  thumbnails and stored attachments in bounded batches.
- Findings set operational attention/quarantine metadata only. The command never
  deletes, replaces, unpublishes or exposes a signed playback URL.
