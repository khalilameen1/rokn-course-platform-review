# Home course copy — approved reduction

User decision on 2026-10-04: the single featured course shows its title and
details action without classification or author text. Home discovery and search
cards omit the instructor, including the reserved blank instructor slot. Public
availability badges on the smaller cards remain unchanged.

This preserves `CourseCard`'s equal scaled title slots, artwork ratio, rail
widths, full accessible title and original course destination. The shared cards
are currently used only by the Home catalogue/search surface. No instructor or
category fields are deleted from course models, API responses, dashboard forms
or course details. Authorship remains editable and available on course details.

The separate full-hero preview follows Netflix's published mobile featured
content composition with Rokn branding and one details action. Its official
[Android listing](https://play.google.com/store/apps/details?id=com.netflix.mediaclient)
shows 1B+ downloads. This is an adaptation of visible UI, not copied Netflix
source. The user approved the complete preview on 2026-10-04. `CarouselItem`
now composes the existing dashboard-authored artwork, a bottom gradient, the
centered title and one full-width blue details action in a single clipped card.
The arrow and old side-by-side layout are removed. `CourseCarousel` still selects
only `data[0]`; no pagination or carousel behavior is introduced.

The existing `CourseArtwork` and installed `react-native-linear-gradient`
integration are reused, not Netflix source. The same responsive hero metrics
drive the card and loading placeholder. Width remains inside the existing feed
gutters and tablet content cap. Card height is a minimum, not fixed; copy stays
in normal flow and allows up to four lines at large font settings. Large-text
rendering strengthens the artwork overlay at every position so the
first title line remains legible when the card grows upward. The ordinary fade
is unchanged. Ordinary phone title type follows the approved 27px/24px hierarchy
using the bundled Cairo family.
The backend/dashboard continue to own featured selection, title and artwork;
no new fields, endpoints or copied preview assets are required.

Regression cases were adjusted in `homeFeaturedPresentation.test.tsx`,
`courseCardTitleLayout.test.tsx`, `courseCardLabels.test.tsx`,
`catalogueCardMetadataPlacement.test.ts` and `responsiveLayout.test.tsx`. Execution,
native visual verification and build remain deferred to the final combined gate.

## Native fidelity correction — 6 October 2026

The signed version 63 Home capture confirmed that its composition was present,
but the source had substituted scaled shared typography, spacing and radii for
the approved preview values. This was not proof of visual parity. The local
correction restores the approved 27/40.5 and compact 24/36 Cairo ExtraBold title,
15px Cairo Bold action, 8px action radius, 18px gap, 22px bottom padding, 16px
phone gutters and 438/408px minimum frame. The existing responsive owner also
supplies the loading frame, and tablet/large-text growth remains supported.
The narrow-phone copy uses the approved 16px inset instead of 20px. Existing
native accessible touch targets remain at least 48dp rather than reproducing
the browser preview's smaller 44px icon targets.
Home's header restores the approved 104×35 wordmark and header spacing. Small
card badges use logical start instead of a physical right value mirrored by
native RTL. Course names, covers, featured selection and navigation remain
dashboard/API-owned; the preview's illustrative course is not installed as
fictional catalogue content. Image focal cropping and actual corrected-device
appearance still require acceptance. No new build or test run is claimed here.

Independent source review on 2026-10-04 accepted this unit after the large-text
contrast correction. It found no remaining everyday source blocker in this
scope. This is not runtime acceptance or proof of native visual parity.

## Final gate — course-card control selection, 5 October 2026

The current React Native memoized `Pressable` was not found by the exported
type identity, so the fresh suite passed four font-size cases but failed before
pressing either card. Role-only discovery then found six nodes because the
role is forwarded through native wrappers. The final selector requires the
existing button role and a callable `onPress`, retaining the exact count of
two actual card controls. It executes the original long-title card handler and
asserts the unchanged course object is delivered exactly once.

All four equal-slot/font-size cases and complete accessible-title assertions
remain unchanged. No production card, data, styling or handler was modified.
The complete suite passes 5/5. Independent read-only review accepted the binding
and unchanged coverage without rerunning tests. Evidence in
`mobile/.cache/final-gate-20261005/`: `mobile-course-card-controls-reproduction.log`,
the intermediate `mobile-course-card-controls-final.log`, and the successful
`mobile-course-card-controls-final-accepted.log`. This proves the controlled
renderer assertions, not native layout, deployed catalogue or release readiness.

## Final gate — featured-card control selection, 5 October 2026

The fresh featured suite passed seven cases and failed six layout cases because
two exported-type selectors could not find the memoized `Pressable`. Those two
selectors now use the same existing role/callable-handler discovery already
used by the suite's action cases. The card, callback, exact one-action/first-course
assertions, all five widths, large-text normal flow and full-overlay contrast,
artwork containment and shared loading-frame assertions are unchanged.

The complete suite passes 13/13. Independent read-only review accepted the
selectors and unchanged assertions without rerunning tests. Evidence in
`mobile/.cache/final-gate-20261005/`:
`mobile-featured-card-controls-reproduction-correct-cwd.log` and
`mobile-featured-card-controls-final.log`. The earlier `-reproduction.log`
records a Jest launch from the repository root that failed config discovery
before tests ran; it is not a product failure. No production code or approved
design changed. Native pixels and full release readiness remain unverified.
