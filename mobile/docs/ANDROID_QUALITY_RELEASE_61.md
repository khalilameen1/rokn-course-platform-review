# Android quality follow-up for internal build 61

Scope: local mobile changes only. No backend deployment, Play upload, or change
to the build 60 review. Build 61 remains version 1.0.60 because it has not been
uploaded to Play.

## Changes

- Enable React Native's supported edge-to-edge mode across Android versions.
  Native navigation owns status-bar appearance; screens no longer issue old
  StatusBar color/translucency commands. Checkout uses AndroidX edge-to-edge
  with its existing system-bar, cutout and keyboard insets. Android 30+ selects
  the ALWAYS cutout resource; Android 28/29 retain their compatible resource.
- Remove the unused portrait-lock bridge and registration, rather than making
  it a no-op. Keep the manifest and Expo orientation unrestricted. Remove dead
  fixed-screen/status-bar geometry helpers; test mounted layout changes through
  rotation, split-screen and unfolding.
- All raster-image surfaces share RasterImage, backed by React Native/Fresco,
  requesting view-size decoding while preserving source headers, fallbacks,
  accessibility and callbacks. SVG course art keeps its vector renderer.
- Replace the handwritten notification bitmap downloader with a managed Fresco
  pipeline. It works without a React runtime and does not replace React's image
  pipeline. Bound encoded bytes, decoded dimensions, memory/disk cache and time;
  retain HTTPS-only/no-redirect behavior and plain-text notification fallback.
  Completion races, cleanup and oversized/unknown-length bodies have native tests.
- Enable AGP 8.12 integrated resource shrinking through
  `android.r8.optimizedResourceShrinking=true`. Preserve full R8 optimization,
  existing release signing, pinned dependencies and every release gate.

## Verification and limits

Run the complete production release pipeline, native tests, bundle validation,
manifest/signature checks and runtime layout checks for every final artifact.
Evidence belongs to its source commit and SHA-256, not just versionCode 61.

Google's original findings also identify dependency implementation details:
React Native StatusBarModule/WindowUtil, Material/Media3/video compatibility
paths, Fresco ArtDecoder/SimpleImageTranscoder, and expo-notifications' image
builder. Their compatibility code is still present in upstream dependencies.
Do not claim all Play Console recommendations have disappeared based on local
source checks. Do not hide diagnostics, rewrite dependency bytecode, add broad
R8 exclusions, or hand-edit node_modules to make the report look clean.

The definitive Console result requires scanning the new signed AAB. A successful
build is not evidence of completed purchase, account, or submission journeys.
