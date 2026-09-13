# Store rollout — September 13 approved changes

The owner confirmed verified sandbox credit at zero revenue and portfolio
pre-publication review, including pausing existing shared works. The backend is
now deployed; native purchase and store acceptance evidence remain separate.

## Recorded deployment — 2026-09-13

After Backend CI `34727103158` succeeded (1,709 passed, 4 skipped, 17,211
assertions), `main` was fast-forwarded to
`3c7260785db3696224672ea399bdd11fa6d37c24`. Cloud manual backup
`before-store-3c72607-20260913` completed at `00:25:47 UTC` (110.3 MB).
Deployment `202` succeeded at `00:30:56 UTC` in 1 minute 45 seconds; all five
September 12/13 migrations ran, with App healthy, one ready instance and three
domains routing. Post-deployment schema-only preflight command `165` passed.
Runtime command `166` finished at `00:39:31 UTC`: production/Redis, healthy
scheduler and all seven queues (26–27-second heartbeat ages, queue sizes 0),
and Google finalization pending/due/deferred counts all 0. Deployment metadata
was null, so that command does not prove the source SHA. No new purchase or
actual store charge was performed. See [release status](STORE_RELEASE_STATUS.md)
for the Cloud source identity, endpoint and association evidence.

There is no explicit log evidence that every old web/worker process and in-flight
request has drained. No portfolio approval is recorded. Keep portfolios pending
until the cutover evidence and subsequent 300-second wait below are satisfied.
The healthy heartbeat check does not replace that old-process drain evidence.
Play `57 (1.0.56)` remains an internal draft, not released to testers or public
review. No native purchase is verified. Its AAB remains pinned to source
`903a13b18938a992b177adef0905f2a2b9a06dc9`; backend promotion did not rebuild it.

## Deploy together

1. Record the existing release, back up production data and inspect the actual
   Laravel Cloud deploy commands/worker configuration before pushing a branch
   that may auto-deploy. Do not disable runtime gates to make a health check green.
2. Apply pending migrations before serving the new code. The financial recovery
   code uses the existing store-billing account/finalization migration. Portfolio
   review adds `2026_09_13_180000_add_portfolio_prepublication_review` and defaults
   all existing owners to pending without deleting their private projects or
   media. New owners also default to pending. Do not approve any portfolio yet.
3. Deploy the matching moderated code and confirm in Laravel Cloud that every
   old web instance has stopped serving requests, including its in-flight
   requests. Record that cutover time; a successful push or migration alone does
   not prove this. The old code does not enforce the new pending status and may
   still issue public media URLs until it has stopped. The new code refuses
   public portfolio HTML/API/media access while approval is pending.
4. After the confirmed cutover, wait at least 300 seconds before the first admin
   approval. This drains the public image/embed URLs issued by the old release.
   The 600-second HLS URLs are owner-only and are not returned by public routes.
   Keep all portfolios pending until the wait and live checks are complete.
   Already downloaded copies cannot be recalled. Do not rotate unrelated
   provider credentials. There is no separate public-portfolio ingress switch
   in the current code; do not assume `artisan down` supplies one. Its current
   file-based maintenance mode is local to an instance and affects the whole app.
5. Restart the existing isolated workers and scheduler on the new release.
   `payments:finalize-store-purchases --limit=25` runs every minute, with one-server
   scheduling and database leases. It consumes only verified credited Google
   purchases with financially effective orders; no new worker type is required.
6. Roll out the matching mobile binary and the affirmative AI-consent migration
   together using [AI_CONSENT_RELEASE.md](AI_CONSENT_RELEASE.md). Old applications
   cannot silently accept consent or complete new AI work without the new UI.

## Verify the live configuration

- Real Google/Apple consumable product identifiers must match the wallet packages
  and bundle/package ID. Store prices are read from the store, not synthesized.
- Google needs its existing Android Publisher purchase-read/consume permissions.
  RTDN must include one-time products and keep the correct OIDC audience and
  service-account identity. Do not substitute an unauthenticated webhook.
- Verify package credit, replay, user switching, pending/cancelled payments,
  app closure and subsequent recovery on the internal track/sandbox. Test coins
  have paid-lot provenance but zero actual income. A test course enrollment must
  also produce zero provider-test cash income, even when AI quota is available.
- Reconciliation uses signed provider evidence. A late or replayed notification
  must not double-credit, erase a newer reversal or revive access after a refund.
- Review a harmless existing portfolio as an administrator. Check owner pending,
  approved, rejected and suspended states, plus an edit after approval. New and
  cached Rokn delivery URLs must refuse unapproved snapshots. Keep practical
  certificate destinations pointing to the stable moderated portfolio route;
  theoretical certificates retain their verification route.
- Check private work editing, support reports, AI consent/deletion and reviewer
  access with the released binary. Keep actual IDs/timestamps and outcomes in the
  private release record. Never invent successful purchases or readiness evidence.

Uninstalling the moderation migration while serving the new application is not a
rollback plan. Keep a compatible moderated application/schema pair serving while
recovering. Reverting to the old application would expose pending portfolios
again; that rollback requires a separately verified hosting-level restriction on
public portfolio access across every serving hostname and instance first.
