# Store listing copy

Prepared 2026-09-12 from source commit `902fe80` and the current public About API. These are local copy drafts ready for metadata entry, not confirmed or uploaded console values. No existing listing equivalent was found.

## Field mapping and limits

Use `title` for Play app name and Apple name, `short_description` for Play short description, and `full_description` for Play full description and Apple description. `apple_optional` contains only Apple's optional subtitle and promotional text. Descriptions are plain text with newline paragraphs, not HTML. `locale` identifies the file language; select the intended English regional localization in each console rather than treating this JSON as an API upload payload.

Verified official limits: Play name 30 characters, short description 80, full description 4000; Apple name 2–30, subtitle 30, promotional text 170, description 4000. Sources checked 2026-09-12:

- Google Play field limits: https://support.google.com/googleplay/android-developer/answer/9859152?hl=en
- Apple name/subtitle: https://developer.apple.com/help/app-store-connect/reference/app-information/app-information/
- Apple description/promotional text: https://developer.apple.com/help/app-store-connect/reference/app-information/platform-version-information/

## Claim basis

- Live public copy: https://rokn.app/api/v1/content/pages/about and `backend/resources/lang/ar/about.php` describe connected short videos, the course map, conditional projects/reports, portfolio sharing and conditional certificates
- `backend/resources/lang/ar/landing.php` establishes the scroll-and-learn voice; the requested slogan spelling is retained as `سكرول واتعلم`
- `mobile/src/screens/reels/ReelsSurface.tsx` implements vertical paging and `backend/app/Services/CourseAccessPlanService.php` makes questions, project reports and certificates plan-dependent
- `mobile/src/localization/i18n.config.ts` selects Arabic; an English listing does not claim an English app interface
- `mobile/src/screens/Profile/SavedVideos.tsx` supports saving videos for later; functional question/review names make no promise of human trainers or unlimited access

No careers, accreditation, results, audience counts, prices, discounts or specific subject availability are promised. The older English landing's career/competence claims were not reused.

Before console entry confirm display-name availability, the intended English localization, and screenshots against the release catalog and deployed features. This folder does not supply privacy declarations, reviewer access, product configuration or release approval.
