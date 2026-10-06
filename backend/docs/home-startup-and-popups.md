# Approved Home startup and popups

The approved composition is `rokn-notification-patterns-oct4.html` in the existing local visualization handoff. Startup follows the Cake-inspired single-colour composition already approved, with Rokn's complete bundled wordmark and Arabic slogan. This is adaptation of the approved visible experience, not reuse of Cake's private application code. Implementation uses the existing React Native Modal and Animated APIs; no additional popup framework or navigation stack is introduced.

Reference provenance: [Cake's published Android listing](https://play.google.com/store/apps/details?hl=en-US&id=me.mycake) showed 100M+ downloads when checked on 2026-10-04; [Cake's own site](https://cake.day/) also reports 100M users. These establish real-world adoption of the reference app, not measured performance of Rokn or access to Cake's implementation. The popup composition is the user's approved local preview; existing Rokn artwork, dashboard-owned values and campaign targeting are reused rather than replaced by a competitor's code.

`StartupExperience` keeps navigation/Home mounted during loading. Home signals that its catalogue request has settled, including its ordinary error/offline state. A non-Home initial destination signals readiness through the existing navigation owner. Session readiness also gates exit, with an eight-second upper bound against an unavailable dependency. There is no minimum presentation delay. Reduced-motion users get no fade. Native launch artwork and the navigation fallback use the same wordmark and background.

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
This is a verified reusable library candidate, not an implemented fix. Use the
SDK 55-compatible version rather than copying Bluesky's SDK 57 dependencies.
Do not hide native coverage before Rokn's own startup frame has painted, add a
minimum marketing delay, or hold Home mounting behind catalogue/session I/O.
Final acceptance must inspect the signed cold-launch transition, not merely
assert that launch XML and React logo files exist.
