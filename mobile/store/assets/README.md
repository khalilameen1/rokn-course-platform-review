# Google Play identity exports

The September versions of both PNG exports were visually reviewed and saved in
the Google Play listing draft on 2026-09-13; that historical action did not submit
a public review. The current blue app/Play icon was exported locally on
2026-10-05 and has not been uploaded by that change. See [APP_ICON_EXPORT.md](APP_ICON_EXPORT.md)
for its source, native-platform outputs and current export command. Phone
screenshots are separate and must come from the actual Play candidate, not
version 36 or a fabricated interface.

- `play-icon-512.png`: technical reduction of the approved 1024-pixel app icon.
- `play-feature-graphic-1024x500.png`: the retained September icon beside the exact
  slogan `سكرول واتعلم`, in the bundled Cairo Bold font, on brand canvas `#070A10`.
- `feature-graphic.svg`: editable code-native layout. Its image and font paths
  refer to the original repository assets; do not treat it as a new logo source.
- `asset-manifest.json`: current Play icon/font checksums and validated output
  sizes, with an explicit earlier source snapshot for the retained feature graphic.

The original identity is selected by `mobile/app.json`. There is no SVG logo
source in the repository; the original PNG is not traced, cropped, recoloured
or replaced. No screenshots, audience counts, claims, store badges or AI-made
imagery are included.

## Combined icon and feature-graphic re-export

This command intentionally re-exports the feature graphic using the current
reviewed blue icon too. It is not needed for an app-icon-only change and was not
run during the October icon update. Use the app-icon exporter linked above for
launcher/catalog updates without changing approved feature artwork.

Use Node.js with Sharp available, then run:

```sh
node mobile/store/assets/render-store-assets.cjs
```

If Sharp is supplied by a separate tool runtime, set `NODE_PATH` to that runtime's
`node_modules` directory before running. The verified export used Sharp 0.35.4.
No application dependency or package lockfile needs to change. The script
validates the approved icon checksum and the bundled font, renders Arabic with
Pango, checks the required PNG dimensions/size/opaque RGB output, and leaves the
source icon untouched. It overwrites only the two generated PNGs and their
manifest in this folder. Review the images before any upload.
