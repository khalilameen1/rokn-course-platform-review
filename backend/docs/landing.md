# App landing page

The public `/` route remains a Laravel Blade page. It promotes the mobile app;
it is not a browser course player or a duplicate student account system.

## Content and links

- Localized saved slogans, SEO, social links and contact details retain their
  existing dashboard sources. Empty settings have concise Arabic/English defaults.
- App Store and Google Play use the unmodified official badge files. Both remain
  visible before launch without fake links. `AppReleaseChannelService` supplies
  their destinations once configured.
- The landing exposes only Google Play and App Store. Direct APK links, preview
  captions and direct-channel discount badges are deliberately excluded, including
  when an older caller supplies a direct release. The underlying release API,
  download route, dashboard records and payment-channel pricing are unchanged.
- The mobile sticky action only targets a configured release for that device.
  Download links and the entire page remain usable without JavaScript.
- The optional dashboard-controlled explainer video and legal routes remain available.
- The download landing does not display recharge or account entry points.
  `/recharge` remains available separately for targeted offers and returning students.
  The configured welcome gift remains visible as a number beside the existing
  coin artwork, without a second currency label or a hardcoded reward amount.
  The requested welcome copy explains that it is for new users and their first
  course. The offline preview proposes 600 coins; it does not update production
  reward settings. Before launch, set the canonical `welcome_bonus` rule to 600
  and check that an eligible first course is purchasable with that reward balance.
- `/contact` is an app link to the existing Feedback screen, without a case ID.
  The web fallback keeps configured contact channels and an explicit “Open in Rokn”
  action for browsers that retain same-domain links. Native association/configuration
  and navigation must ship in the next app release before installed builds can use it.

## Images and editorial direction

The hero uses a native HTML/SVG motion-design lesson inside the app-player frame.
The current Egyptian copy is “لايكات كتير مش معناها مبيعات”, with a like symbol,
a not-equal sign and a shopping bag. The five-word teaching point comes from our
media-buying script's distinction between likes and actual purchases. The English
adaptation is “More likes don't mean more sales” (six words). The ask/bookmark SVG paths, counter and
dark action treatment reuse our actual mobile `FeedActions.tsx`, adapted to a
Blade presentation with an interactive fixed chat example. It is not a live student session
or a screenshot of the sparse test-course catalog. No enrollment counts,
testimonials or fictitious instructors are added. The single-line caption reserves
space for the action rail so it does not read as another label beneath Contents.

The mobile-first hero places the portrait player, the approved promise and the
store actions in a single centered reading path. On wider screens the player
spans the headline and download rows. At 320px the official badges use their
native 40px height so both remain together without horizontal overflow. Benefits
are full-width editorial rows on phones and two columns on larger screens.
The existing page identity and download flow are preserved.

The frame remains 9:16, 230px wide on phones and 320px on larger screens. The
warm cream field, oversized live type and lavender/orange vector shapes form a
lesson styleframe rather than a photographic subtitle. The caption is now
“اللايكات ولا المبيعات؟” / “Likes or sales?”: the current reel's title, rather than
the course name. It stays small and clear of the action rail. The
photography and earlier skincare/workspace assets are no longer rendered and
remain recoverable. No additional image request or video download is needed.

The wordmark is unchanged. The app icon is a 128-pixel WebP copy of the existing
brand image rather than the 743 KB original. Store badge provenance is in
`resources/legal/frontend/STORE-BADGES.md`.

The hero displays only “سكرول واتعلّم” above the store actions. The user removed
the additional kicker and description because four stacked text lines were too much.
The saved second slogan remains a fallback search description, not extra visible hero copy.
Marketing copy uses concise, natural Egyptian Arabic rather than translated
English phrasing. Saved dashboard slogans continue
to override the defaults and should be reviewed at cutover.
The welcome amount still comes from its existing server-owned rule, with
Arabic-Indic numerals on the Arabic page and an isolated numeral/coin pair.
The four supporting benefits separate questions, project gates, improvement reports
and a portfolio alongside the certificate. The portfolio line describes practical
course outcomes, not replacement or removal of the actual certificate. Reporting,
follow-up and project export remain
subject to the selected course tier; the page does not promise human reviews,
unlimited messages, mastery or employment. The app's plan picker explains access.
The preview CI job renders the actual Blade page with empty prelaunch settings
independently from the full backend verification job.

## Reference and verification — 2026-10-10

### Motion lesson styleframe — 2026-10-15

The user rejected keeping the photograph with a subtitle: the brief is a
professional vector/typographic teaching reel. The motion craft reference is the
published geometric lesson at https://imgur.com/QOKGyDP, linked from Ben Marriott's
Motion Foundation course (https://www.benmarriott.com/motion-foundation). It showed
389,317 views during review, and the course site includes named student projects
and testimonials. This supports actual publication/use, not a conversion claim.
We adapted flat geometric construction, restrained rotation and staged reveals;
none of Marriott's art, course branding or code is copied. The page's established
Locket reading path remains intact.

The two actual Lucide icon paths are reused from the upstream thumbs-up.svg and
shopping-bag.svg, scaled within our custom SVG illustration. Source snapshots are
in resources/design/references/lucide; the full ISC/MIT license is in
resources/legal/frontend/LUCIDE-ICONS.txt. No new runtime/library is installed.
Typography reuses Cairo Bold: mobile lead/bridge/result 26/19/36px (English result
28px); larger frames 36/26/48px (English result 40px). The title remains 11/13px.
The complete sentence fits the upper third and reserves the physical right rail.
The preview controls reuse the native FeedActions paths and 390px reference
geometry: 28px icon, 48px icon box, 70px rail, 17px group gap, 10px right offset
and 56px bottom offset, scaled uniformly with the frame through container units.
This gives a 16.4px drawn icon in the 230px bordered frame and 22.9px in the
320px frame. Following the user's correction, the light demo uses plain dark
icons/labels without the native dark discs/pills, and omits the decorative back
arrow. That plain appearance is a landing-only adaptation; mobile code is unchanged.
The demo counter is reel 1 of 60. Arabic renders isolated numeral runs as current
reel on the right and total on the left; English uses the opposite layout. Each
number itself remains LTR, so ٦٠ cannot become ٠٦ through bidirectional reordering.

CSS reveals finish within 1.55 seconds and do not loop. With reduced-motion
preference, all type and art are immediately visible without animation; the same
complete composition works without JavaScript. This is a demo lesson, not a live
student session or native Illustrator project.

Browser checks cover both locales at 320/390/430/768/1440px, headline fit,
upper-third placement, rail clearance and page overflow. The six existing landing
tests pass with 195 assertions; PHPUnit reports three existing deprecations.

### Course-chat demo — 2026-10-15

The Ask action now opens a native HTML dialog. The first-party implementation
reference is `mobile/src/components/VideoPlayer/CourseChatOverlay.tsx`,
`courseChat/CourseChatConversation.tsx` and `courseChat/styles.ts`.
The web adaptation reuses the native SendIcon SVG paths, brand tokens, 26px top
corners, header, close button, right-aligned user bubble, unboxed assistant answer
and 48px composer controls. React Native components cannot run directly in Blade;
this is a translation of our existing app UI, not an imported chat library.
The existing plain, proportionally scaled reel actions are preserved. Ask has a
transparent 48px hit area, without enlarging its visible icon.

The question is “يعني إيه Attribution”, a term actually used in the media-buying
script's reel 64. The answer explains assigning a sale to an ad, gives the script's
Saturday-click / Monday-purchase example, and distinguishes attribution from proof
of causation. The definition was cross-checked against Google's primary attribution
guide: https://support.google.com/analytics/answer/12958241?hl=en . Following the user's editorial direction, the
exchange uses brief Egyptian classroom language without punctuation, canned
assistant phrasing or visible production notes. English follows the same concise
style. The example subtitle has been removed. There is no chat API request, live
presence claim or browser text capture.
The composer and send buttons reveal a download invitation. Its action closes the
dialog, scrolls to existing store badges and focuses a configured store link (or
the availability section before launch). Native dialog behavior supports Escape,
modal focus and focus restoration; the page scroll lock clears on close. Each
opening resets the download gate. Without dialog support or JavaScript, the Ask
button is hidden and the regular download options remain available.

Arabic and English were exercised at 320px, Arabic additionally at 390px and
1440px: opening, input/send gate, keyboard activation, Escape, reopening and
returning to downloads. Seven landing tests pass with 214 assertions; the same
three PHPUnit deprecations remain. Production rewards and store destinations
were not changed by this preview work.

The player frame now uses `overflow: clip` instead of `hidden`. Oversized decorative
shapes had made it an invisible scroll container; after focus/selection its internal
scrollTop reached 73px, cutting off the headline and leaving a dark gap below the art.
Clipping keeps the 9:16 frame fixed without changing the approved visual dimensions.
Chat paragraphs preserve intentional line breaks for the longer term explanation.

The user replaced the initially considered Headway reference with a youth-facing,
mobile-first app reference. The adopted visual/interaction reference is the actual
390px homepage at https://locket.camera/ : app experience first, one short promise,
nearby download action, then supporting information. Locket's primary Google Play
listing (https://play.google.com/store/apps/details?id=com.locket.Locket) showed
10M+ downloads on 2026-10-10; its site explicitly addresses teens/Gen Alpha.
This supports adoption and audience fit, not a proven conversion rate for its site.

No Locket code, illustrations, claims or proprietary assets are copied. The
observable reading path is adapted using the existing Laravel Blade templates,
official store badge files, Cairo Regular/Bold (400/700, existing OFL notice) and
Rokn identity. There is no new frontend framework or dependency.

Offline rendering uses the production Blade templates. Run
`scripts/render-landing-preview.php` in local/testing, then
`php -S 127.0.0.1:4178 -t public scripts/serve-landing-preview.php`.
The preview router serves only these offline pages and public non-PHP assets;
it does not execute account/payment endpoints or touch production data.

Local browser review covers Arabic/English, widths 320/390/430/768/1440, store
destinations, absent APK links, legal navigation and keyboard focus. Tests cover
store/prelaunch/direct-only states, localized rewards and preserved dashboard
copy/SEO/video. No domain, app binary or production deployment is changed yet.
