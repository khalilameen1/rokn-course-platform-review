# Home gift artwork framing — local source, 4 October 2026

## Reference and reuse

The approved `rokn-notification-patterns-oct4.html` composition intentionally
centres a 200px coin image in a 104px illustration slot. The shipped 640px PNG
has transparent padding, so this crops padding rather than visible coin artwork.
Read-only inspection of the actual PNG found all nontransparent pixels within
rows 167–470 of 640, corresponding to y=52.19–147.19 in a 200px image. The
centred 104px slot retains y=48–152. This establishes the asset's padding at the
source level, not native-device rendering or a completed visual acceptance test.
That same framing must not crop arbitrary template or design-settings uploads.
The approved card, artwork, copy, amount, navigation and launch policy are kept.

The existing OneSignal reference has [documented historical IAM adoption by over
10,000 companies in 2019](https://onesignal.com/blog/record-breaking-momentum-and-exceptional-growth-in-2019/).
This is evidence of an established in-app-message product, not a measured Rokn
result or a claim to reuse its image implementation. No matching reusable source
for Rokn's exact padded asset and Laravel authoring contract was found. Actual
reuse is the installed React Native Image/Fresco rendering path and Rokn's shared
`AppArtwork` owner, provider, settings cache and dashboard upload writers. Native
[`contain`](https://reactnative.dev/docs/image#resizemode) preserves the aspect
ratio and fits both image dimensions in the measured view. The short source
identity integration is Rokn-specific; no new loader, SDK or image editor exists.

## One source owner, two justified frames

`HomeOverlays` no longer keeps a second failed-image state. `AppArtwork` owns
template URL, global dashboard coin-stack URL, and bundled offline fallback.
Both uploaded sources fit the existing 104px-high, at-most-200px-wide slot with
`contain`. The known shipped source retains the approved 200px padded framing.
The illustration slot and card dimensions do not grow. The individual amount
icon, course campaign cover and all other artwork callers retain their styles.

`AppArtworkService` identifies only unconfigured shipped defaults. The public
settings response adds `artwork_defaults` with their exact existing URLs; it
does not change the `artwork` map, reward grants, templates or database schema.
The public settings cache namespace advances so deployed cached old payloads
cannot hide the added identity. The identity participates in the existing ETag
hash. Existing design saves already invalidate this same settings owner.

The mobile reader validates HTTPS identities and retains only identities equal
to the accompanying artwork URL. The root provider replaces URL and identity
together. `AppArtwork` does not guess by URL suffix, image dimensions or alpha
pixels, and callers do not implement another fallback chain. Without metadata,
a network image is conservatively contained; old/offline snapshots still work.
Bundled fallback retains its framing even without settings. A loading placeholder
uses the network view's frame, so its temporary padded coins can appear smaller;
this does not promise unchanged loading pixels.

Dashboard template and global coin-stack editors explain full-image containment
and the template-to-global fallback. They do not expose a padding/crop switch.
Uploaded transparent padding is retained; it is not inferred or zoomed away.
Removing a template image reveals the configured global image; removing a global
override is not an available dashboard operation in this unit. When the global
override is unconfigured, the settings reader supplies shipped default identity.
The authored model-reset case verifies that read/cache contract, not a dashboard
removal workflow; global artwork authoring remains upload/replace only.
No asset generation, file replacement, production upload or financial change ran.

## Deferred acceptance

Authored, not executed: template/global/offline priority and per-source frames,
identified shipped remote default, stale identity, changed failed URL recovery,
ordinary callers unchanged, root-provider atomic identity replacement, safe
normalization and cached offline identity, backend default/upload/model-reset identity
and revision updates. Renderer assertions prove source and style contracts only,
not native pixel containment, alpha bounds or screenshot parity.

At the final combined gate execute these cases and existing settings/artwork,
template and Home presentation suites. In the matching dashboard and signed app,
verify opaque square/landscape/portrait and transparent uploads, absent metadata,
failed template/global URLs, foreground settings changes, narrow screens and
large text, then compare the shipped default to the approved composition.
Tests, typecheck/lint, build, commit, push and deployment remain deferred.

Independent source review accepted the unit after correcting a documentation
claim about a nonexistent dashboard global-removal operation. No practical
framing/source-ownership finding remains in that review. The reviewer checked
the approved composition, per-source frames, shared fallback, atomic provider,
settings/cache/ETag and authoring boundaries without running tests or changing
files. This is source-level acceptance only; physical visual/runtime gates above
and the other active-goal units remain open.
