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

## Images

The two product images are real Rokn app captures, not simulated interfaces:

- `rokn-home.webp`: mobile artifact `rokn-1.0.53-startup.png` from 10 September 2026
- `rokn-lesson.webp`: mobile artifact `rokn-preview-current.png` from 5 September 2026

They were resized to 540 pixels wide and encoded as WebP without changing their
contents. Together they are approximately 73 KB. Brand wordmark and app icon are
the existing assets. Store badge provenance is in
`resources/legal/frontend/STORE-BADGES.md`.

No domain or production deployment is changed by this implementation. The planned
`rokn.app/recharge` cutover remains a separate deployment operation.
