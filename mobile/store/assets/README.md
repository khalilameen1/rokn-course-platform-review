# Google Play identity exports

Both PNG exports were visually reviewed and saved in the Google Play listing
draft on 2026-09-13. No public review was submitted. Phone screenshots are separate and must come from
the actual Play candidate, not version 36 or a fabricated interface.

- `play-icon-512.png`: technical reduction of the approved 1024-pixel app icon.
- `play-feature-graphic-1024x500.png`: the same intact icon beside the exact
  slogan `سكرول واتعلم`, in the bundled Cairo Bold font, on brand canvas `#070A10`.
- `feature-graphic.svg`: editable code-native layout. Its image and font paths
  refer to the original repository assets; do not treat it as a new logo source.
- `asset-manifest.json`: original/font checksums and validated output sizes.

The original identity is selected by `mobile/app.json`. There is no SVG logo
source in the repository; the original PNG is not traced, cropped, recoloured
or replaced. No screenshots, audience counts, claims, store badges or AI-made
imagery are included.

## Re-export

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
