# App landing page

The public `/` route remains a Laravel Blade page. It promotes the mobile app;
it is not a browser course player or a duplicate student account system.

## Content and links

- Localized saved slogans, SEO, social links and contact details retain their
  existing dashboard sources. Empty settings have concise Arabic/English defaults.
- App Store and Google Play use the unmodified official badge files. Both remain
  visible before launch without fake links. `AppReleaseChannelService` supplies
  their destinations once configured.
- A configured direct Android release appears alongside the store options. Its existing
  channel discount is used; this page does not introduce another pricing rule.
- The mobile sticky action only targets a configured release for that device.
  Download links and the entire page remain usable without JavaScript.
- The optional dashboard-controlled explainer video and legal routes remain available.
- The header account icon links to the existing `/recharge` student session:
  social sign-in for a guest and the account/balance for a signed-in student.
  It does not use the staff guard or personalize the publicly cacheable landing.
- `/contact` is an app link to the existing Feedback screen, without a case ID.
  The web fallback keeps configured contact channels and an explicit “Open in Rokn”
  action for browsers that retain same-domain links. Native association/configuration
  and navigation must ship in the next app release before installed builds can use it.

## Images and editorial direction

The hero uses one commissioned vertical photography illustration inside a
code-rendered representation of the app player. It is not a live student session
or a screenshot of the sparse test-course catalog. No enrollment counts,
testimonials or fictitious instructors are added. The caption is a single line.

The mobile hero places the headline beside the portrait player and the complete
download group directly underneath. On wider screens the player spans both the
headline and download rows. Very narrow viewports stack them without shrinking
the controls. Benefits are readable full-width rows on mobile, not narrow columns.
Colours and surface radii follow `mobile/src/constants/brandTokens.ts` and the
mobile design system. The offer badge stays in document flow when its text wraps.

The original built-in image-generation outputs were converted to 432 × 768 WebP
assets. Only the photography asset is used on the current landing page.
Exact prompts and final asset paths are in
`resources/design/landing-image-prompts.md`. The removed test screenshots remain
recoverable from Git and the original mobile artifacts.

The wordmark is unchanged. The app icon is a 128-pixel WebP copy of the existing
brand image rather than the 743 KB original. Store badge provenance is in
`resources/legal/frontend/STORE-BADGES.md`.

The page pairs “سكرول واتعلّم” with “كورسات متخصصة من غير حشو”.
Marketing copy uses concise, natural Egyptian Arabic rather than translated
English phrasing. The direct-download badge says only “خصم 10%” with the actual
configured percentage. Saved dashboard slogans continue
to override the defaults and should be reviewed at cutover.
The welcome gift applies to all download channels; the direct-download discount
has its own badge. Both amounts come from their existing server-owned rules.
The three supporting benefits describe contextual explanations, project feedback
and presenting completed work. Reporting, follow-up and project export remain
subject to the selected course tier; the page does not promise human reviews,
unlimited messages, mastery or employment. The app's plan picker explains access.
The preview CI job renders the actual Blade page with empty prelaunch settings
independently from the full backend verification job.

No domain or production deployment is changed by this implementation. The planned
`rokn.app/recharge` cutover remains a separate deployment operation.
