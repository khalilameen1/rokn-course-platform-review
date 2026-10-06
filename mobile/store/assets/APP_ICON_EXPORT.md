# Current app icon export

The current launcher/store icon is the existing white Rokn mark on the primary
colour from `mobile/src/constants/brandTokens.ts` (`#2C69DB`). The visual reference
is Coursera's published white-mark/solid-blue app icon, not its logo or code.
Its Google Play page showed 10M+ downloads on 2026-10-05:
https://play.google.com/store/apps/details?id=org.coursera.android

`mobile/scripts/export-app-icons.js` is the current exporter for the phone icons.
It uses Sharp 0.35.4, already used by this repository's publication tooling and
available in the bundled workspace runtime. It does not install a package or
change the application dependency lock. Sharp is also used by Expo's image
tooling; the implementation here reuses Sharp directly, not Coursera code.

Run with a Node environment that has Sharp 0.35.4 (on this workstation the
bundled workspace packages can be selected with `NODE_PATH`), from `mobile`:

```powershell
node scripts/export-app-icons.js
```

The source is the existing 432px transparent Android xxxhdpi foreground. Its
SHA-256 is pinned in the exporter and remains unchanged. The new Expo foreground
file is a byte-for-byte copy. All existing Android density foregrounds are kept;
only their shared native background colour changes. The exporter resizes and
composites the original artwork rather than generating or tracing a new logo.
It writes the 1024px master, solid background, opaque 512px Play icon, all filenames
in the existing iOS catalog, and `app-icon-manifest.json` with output checksums.
The OS supplies the mask: no baked rounded corners or transparency on store/iOS
icons. Expo adaptive and monochrome settings now point at the same transparent
foreground instead of a flattened black-background image.

The combined store exporter `render-store-assets.cjs` retains its approved-source
hash guard, now pinned to the reviewed blue master. Its `asset-manifest.json`
matches the current Play icon. The already-exported feature graphic is retained
unchanged, and its output record identifies its earlier source snapshot at commit
`3fc8df3a4c21d25448540cdc266ec722d2c1d3a7`; it is not falsely described as rendered
from the new blue master. Run the combined exporter only when intentionally
re-exporting the feature graphic, not as an implicit part of an icon update.
This app-icon change does not regenerate any approved feature graphic,
screenshot, course art, in-app header logo or startup screen.

Native resource/PNG checks are not proof of the installed APK or an iOS archive.
Check the actual launcher masks after the final build. Nothing here publishes
store metadata or changes a running server.

## Store metadata handoff — 2026-10-06

The published Play listing still displayed the old black icon after internal
release 63 was made available. Uploading the AAB had not updated the separate
store listing. The default listing was last edited on September 14.

The existing blue `play-icon-512.png` was independently inspected visually and
uploaded unchanged (SHA-256 `065cce03746a3e3c01ff52d4a3d3300e83d1f26e4986390230f2876937eb4e78`).
Only the old listing icon was replaced; its library asset remains recoverable.
The five existing phone screenshots, feature graphic, name and descriptions
were retained. Publishing overview showed exactly one change: default Arabic
listing, change app icon. The review confirmation was submitted and the page
then showed changes under review, with automated checks still running.

Evidence: `E:/RoknBuild/release63-evidence-20261006/play-blue-icon-review-requested-20261006.png`
and the accompanying `.txt` accessibility capture. This proves the saved icon
and requested review, not approval or propagation to the public store. No new
binary was built or promoted; production remains version 60 and internal testing
remains version 63. Home, startup and notification fidelity are separate open
acceptance items, not covered by this icon handoff.
