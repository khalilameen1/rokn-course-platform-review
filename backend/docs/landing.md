# App landing page

The public `/` route remains a Laravel Blade page. It promotes the mobile app;
it is not a browser course player or a duplicate student account system.

## Content and links

- Localized saved slogans, SEO, social links and contact details retain their
  existing dashboard sources. Empty settings have concise Arabic/English defaults.
- App Store and Google Play use the unmodified official badge files. Both remain
  visible before launch without fake links. `AppReleaseChannelService` supplies
  their destinations once configured.
- A configured direct Android release is a secondary download option. Its existing
  channel discount is used; this page does not introduce another pricing rule.
- The mobile sticky action only targets a configured release for that device.
  Download links and the entire page remain usable without JavaScript.
- The optional dashboard-controlled explainer video remains available. Legal and
  contact pages keep the same routes and content.

## Images and editorial direction

The hero uses three commissioned vertical subject illustrations rather than a
screenshot of the sparse test-course catalog. These depict photography, visual
design and digital drawing; they do not claim to show actual instructors,
published courses or app screens. No enrollment counts or testimonials are added.
The captions and alt text identify the subject, not a fictitious course.

The original built-in image-generation outputs were converted to 432 × 768 WebP
assets, approximately 206 KB combined. Exact prompts and final asset paths are in
`resources/design/landing-image-prompts.md`. The removed test screenshots remain
recoverable from Git and the original mobile artifacts.

The wordmark is unchanged. The app icon is a 128-pixel WebP copy of the existing
brand image rather than the 743 KB original. Store badge provenance is in
`resources/legal/frontend/STORE-BADGES.md`.

The page defaults use one plain Modern Standard Arabic voice. Saved dashboard
slogans continue to override the defaults and should be reviewed at cutover.
The preview CI job renders the actual Blade page with empty prelaunch settings
independently from the full backend verification job.

No domain or production deployment is changed by this implementation. The planned
`rokn.app/recharge` cutover remains a separate deployment operation.
