# Approved Home startup and popups

The approved composition is `rokn-notification-patterns-oct4.html` in the existing local visualization handoff. Startup follows the Cake-inspired single-colour composition already approved, with Rokn's complete bundled wordmark and Arabic slogan. This is adaptation of the approved visible experience, not reuse of Cake's private application code. Implementation uses the existing React Native Modal and Animated APIs; no additional popup framework or navigation stack is introduced.

Reference provenance: [Cake's published Android listing](https://play.google.com/store/apps/details?hl=en-US&id=me.mycake) showed 100M+ downloads when checked on 2026-10-04; [Cake's own site](https://cake.day/) also reports 100M users. These establish real-world adoption of the reference app, not measured performance of Rokn or access to Cake's implementation. The popup composition is the user's approved local preview; existing Rokn artwork, dashboard-owned values and campaign targeting are reused rather than replaced by a competitor's code.

`StartupExperience` keeps navigation/Home mounted during loading. Home signals that its catalogue request has settled, including its ordinary error/offline state. A known non-Home initial destination signals readiness through the existing navigation owner; an absent route is not readiness. Session readiness also gates exit, with an eight-second escape for a stalled restore only after initial content is ready. That deadline never uncovers a still-loading Home. Catalogue transport has its existing bounded timeout/recovery policy. There is no minimum presentation delay. Reduced-motion users get no fade. Native launch artwork and the navigation fallback use the same wordmark and background.

`StartupExperience` owns one Home presentation slot for the app launch. `useHomeEngagement` owns candidate selection and account-bound callbacks. Signing in or switching accounts suppresses further presentations in that launch. Dismissing a card or remounting Home does not drain a queue. A later application launch may select an unseen course campaign.

The first guest gift has an installation-owned display receipt. Changing a template version, signing out or returning to Home does not repeat it. The amount comes from the existing `WelcomeRewardOfferService` through the public keyed engagement endpoint. The guest template owns title, buttons and optional artwork. The artwork fallback is the existing dashboard-managed `coin_stack`, then its bundled offline image. The numeric amount is separate from the body and the icon comes from the existing dashboard-managed `coin`. A zero-value or disabled offer opens no card. The old queued post-login dialog is retired silently, without changing financial ledger or inbox receipts.

The existing backend campaign audience/delivery chooses recipients. The Home popup accepts only unread course recommendations/new-course notifications from an explicitly requested Home slice of that inbox. The backend applies the shared public-course and captured-rights readers and supplies the current title, cover and ID, independently of Home's loaded catalogue. Empty/locally seen pages continue through the existing cursor, not a first-inbox-page cutoff. Reports, certificates and task offers stay outside this popup path. Campaign closure retains the existing account-scoped local/read receipts without delaying the course CTA. The local seen write and server mark-read now run independently; a failed network ACK cannot prevent local dismissal persistence, and a failed/stalled disk write cannot block the server read. General inbox and push-authored text remain unchanged. See [Home campaign selection](home-course-campaign-selection.md) and [Home campaign dismissal](../../mobile/docs/home-campaign-dismissal.md) for ownership and deferred acceptance.

The copy migration changes only known shipped defaults, not authored copy, schedules, uploaded images, activation or reward amounts. The dashboard guest-template editor permits an empty body; other templates retain their existing body validation. Gift and legacy inbox response shapes, financial grants, database tables and campaign delivery lifecycle stay unchanged; only the opt-in Home notification response adds the current course summary.

The welcome template now describes its approved fixed presentation instead of
advertising unused cooldown/dismissibility/surface/destination controls. The
existing request/writer/API share the keyed policy, while title, button labels,
artwork, activation, availability and reward-rule amount stay dashboard-owned.
See [Welcome presentation policy](welcome-prompt-presentation-policy.md) for
legacy editor compatibility and deferred dashboard-to-phone acceptance.

Gift artwork now reuses one shared template/global/bundled source owner. Uploaded
images are contained inside the existing illustration slot; only an explicitly
identified shipped default retains the approved padded framing. The additive
public settings source identity does not change old clients' artwork URLs or
reward/template contracts. See [Gift artwork framing](../../mobile/docs/home-gift-artwork-framing.md)
for source reuse, dashboard guidance and deferred native acceptance.

Authored local cases cover startup readiness, deadline release, installation-level welcome display, configured amounts, login/account suppression, popup deduplication, course artwork/title ownership and stale callback rejection. These latest source changes have not had their deferred final test run. Native launch appearance, real-device layout and live backend behavior still require integration verification before release. Local tests do not prove deployment or native performance.

## Native first-paint handoff — 6 October 2026

Signed version 63 was opened in an isolated ephemeral emulator user without
clearing the existing learner session. `64-first-guest-launch.png` captured a
blank dark MainActivity before React content, followed by the actual guest Home
gift in `65-first-guest-settled.png`. This proves a gap in that observed cold
launch, not a measured delay on every device. The temporary profile was removed
and the original emulator user retained. MainActivity immediately switches its
launch theme to AppTheme; no native splash retention library is installed.

The next correction must retain the approved Cake-inspired Rokn composition,
not replace it with a newly invented animation. A compatible established owner
is Expo SplashScreen: Bluesky's public Android source actually uses it to release
the native splash when ready, and its Play listing shows 10M+ downloads.
Sources: [Android splash owner](https://github.com/bluesky-social/social-app/blob/main/src/Splash.android.tsx),
[runtime dependency](https://github.com/bluesky-social/social-app/blob/main/package.json),
[adoption](https://play.google.com/store/apps/details?id=xyz.blueskyweb.app),
[Expo SDK 55 integration](https://docs.expo.dev/versions/v55.0.0/sdk/splash-screen/).
The local source now reuses `expo-splash-screen` 55.0.25, exactly the installed
Expo SDK 55 recommendation, instead of copying Bluesky's SDK 57 dependencies or
its application gating/animation. The upstream MIT implementation owns the
native first-content handoff through Android's `CONTENT_APPEARED` marker and
the existing iOS Expo delegate subscriber. Android registration happens before
`super.onCreate`, and the SDK's post-splash theme remains Rokn's AppCompat theme.
There is no `preventAutoHideAsync` hold, additional JS readiness owner or I/O
gate. Native exit animation is disabled so the existing Rokn cover alone owns
the approved transition. Home remains mounted while catalogue/session work runs.

The Android system-only wordmark frame is 180×59.1 instead of 205×68 to retain
the complete logo inside the platform's 192dp circular icon mask. Independent
source/alpha inspection found the corrected maximum visible radius 93.45dp,
inside its 96dp limit. React and the existing iOS storyboard retain 205dp.
The plugin configuration captures that Android-only size and replaces deprecated
legacy splash config; the source asset is unchanged and no AI artwork is made.

The source review accepted native ownership and the corrected mask geometry.
New native-config/readiness assertions are authored, not executed. Package
installation ran with scripts disabled and changed only the new module and
hoisted prebuild-config subtree; its local Node 24.18 warning is not a passed
release gate. The pinned final runtime, CocoaPods lock resolution on macOS,
native/JS notice regeneration, dependency fingerprint updates and signed cold
launch acceptance remain required before shipping. No Pod lock is fabricated,
and this local dependency change has not been deployed or uploaded.

The user also reported Home being revealed while its loader was still visible.
The previous eight-second escape allowed that while the transport's existing
12-second read recovery budget could still be active. The corrected condition
requires initial content readiness even after that session deadline. Both ready
and stalled-session cases are authored in the lifecycle regression case. Source
review confirmed the ordinary public load/cache/error paths settle and retained
OAuth/deep-link behavior; it did not verify completed cover-image decoding.
The approved 18/27 slogan typography is retained in the React startup surface.
Final acceptance must inspect the signed cold-launch transition, not merely
assert that launch XML and React logo files exist.

## Catalogue cover delivery — 6 October 2026

Read-only HEAD requests to the current public catalogue's first four covers
returned PNG payloads of 2,053,486 / 2,228,393 / 1,890,254 / 1,998,360 bytes.
This identifies a real payload cost, not a measured cold-start duration. The
same successful open-source reference uses a small delivery image rather than
the original in a scrolling feed: [Bluesky AutoSizedImage](https://github.com/bluesky-social/social-app/blob/main/src/components/images/AutoSizedImage.tsx)
renders `image.thumb` with `expo-image`. Its Play listing above establishes
10M+ downloads. The local change actually reuses Expo Image 55.0.11 (the
installed SDK 55 recommendation), whose native Glide/SDWebImage implementation
owns caching, resizing and WebP decoding. No Bluesky private source or SDK 57
application code is claimed or copied. There is no image readiness registry,
onLoad startup dependency, prefetch-everything promise or additional splash
deadline. The approved startup composition/animation remains unchanged.

The backend uses its existing Intervention Image 2.7.2 encoding implementation
and existing tracked upload/reference-aware cleanup services. That installed
legacy major is retained here; this is not a claim that v2 is currently
maintained or a new dependency upgrade. Course authoring stages the unchanged
original plus a separate proportional static WebP, quality 82 and maximum edge
1280px, in one Photo ownership transaction. EXIF orientation is read from the
original temporary file and the original/alpha are retained. `Photo.path`
still serves the dashboard's original. `preview_path` serves only the public
course resource through `Course.catalogue_image`, with original fallback for
unconverted covers. Existing field `image` remains a URL, including for
Android 60 with static WebP support already enabled. Draft replication retains
both paths; deletion/replay/race cleanup checks all owners of both files.

Existing featured Course Photo originals are converted outside all app HTTP
reads by `php artisan courses:generate-cover-previews --limit=100`. Its dry-run
only counts pending original paths; reruns skip converted rows, and revisions
sharing an original receive the same preview. Deploy the additive migration
before serving the new source, run the backfill and inspect actual public URLs
and payload sizes before claiming the old covers are optimized. Do not rerender
or replace approved CMS artwork. The preflight checks the column and GD WebP
encoder; normal API reads never inspect storage or encode images.

Backend owner/rollback/shared-file/backfill cases and native-cache component
contracts are authored but not executed. They deliberately do not prove native
networking or throughput. Node/npm final gates use the existing pinned runtime;
package installation used scripts disabled and is not a passing release gate.
CocoaPods lock resolution on macOS, license/fingerprint refresh, and signed
cold-launch/cache/offline/failed-cover visual acceptance remain in the single
final gate. Neither this source nor its renditions have been deployed, built or
uploaded as version 64 yet.

Independent source review accepted this unit after the cover error state was
given a source-keyed component owner. A failed A followed by B then A retries;
late A callbacks cannot clear failed B. Both cases are authored for final
execution. This acceptance is explicitly limited to source behavior and is not
measured load performance, applied backfill, native visual acceptance or release
readiness.
