# Operations tools — local preparation and later activation

This change is local only. Installing a package is not evidence that its production worker or a cloud account is active.

## Proven implementation sources

- Filament 5 panels, schemas, actions and tables are reused directly (MIT). Lunar's published Filament-based commerce admin is adoption evidence, not source code we claim to own or have copied.
- Spatie Permission 8 owns role/permission storage and Shield 4 owns role forms. The separate `StaffMember` authorization subject avoids the documented `users.role` conflict and avoids a second login guard. `StaffRole::users()` points to that subject, not the learner identity.
- Laravel Horizon 5 and Pulse 1 are the actual packages, not custom look-alike dashboards. Published configuration/migration adaptations retain their package semantics. Laravel packages and Spatie are MIT licensed; upstream sources and versions are pinned in Composer.
- Playwright authentication setup and storageState use the official dependency-project pattern. axe-core audits the rendered authenticated application.
- Lighthouse 13.5 is the official maintained measurement engine. LHCI 0.15.1 was trialled then removed because its dependency tree added 17 npm advisories; there is no reason to retain the vulnerable wrapper to use the engine in CI.

## Staff roles

After migrations: `php artisan staff:prepare-roles` (repeatable).
Existing admin/moderator sessions keep their existing permissions until explicitly assigned.
An owner uses `/operations/staff-members` to assign active staff accounts, and `/operations/shield/roles` to define roles.
Learners cannot receive staff roles. The owner role cannot be edited or deleted through Shield.
Neither wallet balances nor order financial state are generic Filament model writes.

## Horizon — Linux/Redis only

1. Keep the current worker running until a deployment window. Never add Horizon alongside it accidentally.
2. Verify PHP `pcntl`, `posix`, Redis and the seven queue names from `config/operations.php`.
3. Replace the managed worker command with `php artisan horizon` in the worker instance, not the web request process.
4. Set `HORIZON_ENABLED=true` to schedule snapshots. `HORIZON_MAX_PROCESSES` controls concurrency; size it against the worker instance memory, not the web container. Default 4, 256 MiB per worker.
5. Job timeout max 300s < Horizon 330s < Redis retry_after 360s. Keep this ordering.
6. Deploy hook: `php artisan horizon:terminate`; the process manager restarts the new release. Scheduler still uses its existing single owner.
7. Prove one heartbeat/job on every queue and verify worker status before declaring activation complete. Windows configuration checks do not prove a running Linux supervisor.

## Pulse

The migration is published from the installed package. Recording defaults off to avoid activating a new datastore on the live system merely by deploying code.
At activation set `PULSE_ENABLED=true`, choose sampling and retention (`PULSE_STORAGE_KEEP`, default 7 days), and inspect `/operations/health` after real traffic. The default storage ingest uses the existing database and does not need another daemon. If switching to Redis ingest, explicitly provision `pulse:work` and prove it drains. `pulse:check` is optional server telemetry and is not silently started here.
Pulse and Horizon require the same dashboard identity, authorization and MFA even in local environments. Pulse also persists these checks on Livewire updates.

## Langfuse Cloud / OpenRouter Broadcast

Official integration: https://openrouter.ai/docs/guides/features/broadcast/langfuse

No PHP SDK or second request on the learner latency path is required. Broadcast is configured on the existing OpenRouter account:

1. Create a Langfuse project and key pair in that account, select the required cloud region.
2. Open OpenRouter Settings → Observability → enable Broadcast → Langfuse.
3. Save the keys there (not in public Git or frontend config), test the connection and select the Rokn API key filter and sampling.
4. Choose content capture deliberately. Privacy Mode sends usage, cost and timing without prompts/completions; full capture also sends the learner's text and attachments represented in the provider trace. This is an account/data-processing choice, not a local UI toggle.
5. Send one authorized call and confirm its generation ID in Langfuse. Until then the dashboard says setup is pending; no success is inferred from merely having a URL or flag.

Existing OpenRouter request identities and paid-response recovery bodies are unchanged by this dashboard work. Do not add changing release metadata to a request's recovery cache key without preserving the original payload.

## Browser checks

`npm run test:dashboard` starts a disposable Laravel HTTP server on 127.0.0.1:8793, using isolated SQLite/storage and fixture accounts. It does not load a copy of production data or bypass password/MFA via an endpoint. Reports and authentication state are ignored by Git.
The checked-in toolchain remains Node 22.23.2 / npm 10.9.3. A machine-local `php.ini` is optional, not required by CI.

The dashboard migration is still incremental. The course studio and any unmigrated specialized workspace must remain reachable until its replacement covers the full workflow and is checked, then remove the replaced views and keep only necessary URL compatibility.

`npm run measure:dashboard` uses Lighthouse's official authenticated-session Node API
(https://github.com/GoogleChrome/lighthouse/blob/main/docs/readme.md#using-programmatically).
It opens the real fixture password/MFA flow, then retains that Chrome session for
mobile and desktop measurements of overview, students, orders and a creation form.
Losing authorization or a Lighthouse runtime error fails the job rather than
silently measuring login. HTML/JSON reports are under `.lighthouseci`, excluded from Git.
The local baseline is not a production performance claim: PHP's development server
is single-threaded and its static delivery has neither production compression nor
cache headers. Do not disguise that by changing the scores or treating local response
time as measured Laravel Cloud latency. CI preserves the reports for comparison;
there is no arbitrary performance-score threshold claimed as a product guarantee.
