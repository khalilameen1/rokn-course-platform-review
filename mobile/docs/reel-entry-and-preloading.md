# Reel entry and preloading

Local source changes only. No new artifact, deploy or executed regression run
is implied by this note. Final runtime acceptance is deferred with the other
goal changes.

## Reference and reuse boundary

The Android Developers article [Instagram and Facebook deliver instant playback
with Media3 PreloadManager](https://android-developers.googleblog.com/2026/03/instagram-and-facebook-deliver-instant.html)
documents real adoption, adjacent media preparation and shared player resources.
Its closed Meta implementation was not copied. Rokn retains `react-native-video`
6.18.0 as the player. The new Android source connects the actual Media3 1.4.1
`DefaultPreloadManager`; iOS and unsigned previews retain their existing paths.
This is written local integration, not installed, compiled or measured parity.

This part reuses Rokn's fresh course-details read and its existing player-state
mapping. The navigation adapter is Rokn-specific integration, not reference source.
The resource wiring is adapted from AndroidX's Apache-2.0 shortform demo. RNV
retains its MIT license. Owner/session wiring and the factory adapter are
Rokn-specific integration, not Meta code.

### Pinned native compatibility evidence

The lockfile resolves `react-native-video` 6.18.0. Its upstream
[Android properties](https://github.com/TheWidlarzGroup/react-native-video/blob/v6.18.0/android/gradle.properties)
select Media3 1.4.1 unless the root project overrides that property. The current
root build does not supply a Media3 override. A newer Media3 Builder example must
not be pasted into this integration as if it were the pinned API.

The pinned [RNVExoplayerPlugin interface](https://github.com/TheWidlarzGroup/react-native-video/blob/v6.18.0/android/src/main/java/com/brentvatne/exoplayer/RNVExoplayerPlugin.kt)
does support `overrideMediaSourceFactory`, `overrideMediaDataSourceFactory` and
player-instance lifecycle callbacks. It does not expose a player builder or its
load-control allocator. The pinned [ReactExoplayerView](https://github.com/TheWidlarzGroup/react-native-video/blob/v6.18.0/android/src/main/java/com/brentvatne/exoplayer/ReactExoplayerView.java)
creates its allocator and memory-aware load control inside player initialization.

Media3 1.4.1's [DefaultPreloadManager](https://github.com/androidx/media/blob/1.4.1/libraries/exoplayer/src/main/java/androidx/media3/exoplayer/source/preload/DefaultPreloadManager.java)
constructor takes a source factory, initialized track selector, bandwidth meter,
matching renderer capabilities, allocator and playback looper. Its contract asks
for the player and preloader to share relevant resources. A factory-only plugin
with an unrelated resource pool is therefore not sufficient evidence of the
shared-resource design used by the reference. The local integration therefore
extends RNV's plugin API with one optional resource hook. The pinned, idempotent
postinstall transform changes three installed native files, retaining RNV's
memory-aware load control and null-hook behavior for unrelated playback. It
does not upgrade the player or use reflection. Release verification refuses
an unsupported version or an unapplied extension. Applying the installer and
compiling against the real installed dependency remain final-gate work.

### Native window and player adoption

- JS passes only the current and actual adjacent, unexpired, unlocked sources
  from issued playback manifests. The opaque route owner is retired on account,
  route or background replacement. No catalogue URL is promoted into a grant.
- The preloader shares the actual RNV allocator, bandwidth meter, renderer
  capabilities and playback looper. Android does not mount a second adjacent
  decoder; disabled preload and device memory pressure stop speculative loading.
- A promoted current source keeps its loaded target until the player's actual
  `WrappingMediaSource.createPeriod` call delegates to Media3. Only after the
  preloaded period is adopted does the main-thread callback invalidate its
  target. Generation, URI and source identity reject obsolete callbacks. This
  preserves the demo's play-before-invalidate order, without a timeout guess.
- RNV's fluent DRM/error-policy setters must return the plugin wrapper, not its
  delegate. Factory registration happens after those policies are applied.
- HLS, DASH and progressive factories reuse RNV's real DataSource factory and
  matching Media3 modules. The current factory is retained exactly; neighbours
  need not first become current to make their type available. HLS retains the
  current source's chunkless setting. No second network client is introduced.
- Public/unsigned previews retain normal player behavior. A failed native bridge
  allows the current player to start normally, not an unbounded wait or another
  speculative decoder.

## First-entry ownership

- Course details prepares only a successful fresh response, never its disk
  display fallback. Its receipt time is retained; navigation does not renew it.
- One short-lived, memory-only transition can carry that graph to Reels. The
  route contains a lookup key, not course contents, account credentials or URLs.
  The key is one-use and bound to course, account scope and session epoch.
- Metadata expires after 30 seconds. Missing, expired, consumed or mismatched
  handoffs fall back to the existing server details read. Runtime/revision/project
  reloads always fetch again. Logout quiescence drops the pending transition.
- The same access and preview gates are checked in the loader. Local completion
  hints cannot unlock server course/project gates. Protected sources still require
  the existing signed playback-manifest endpoint and its section/media checks.
- Details invalidates its prepared read when refreshing or acknowledging a change
  in ownership. A read overtaken by purchase cannot reinstate its prior entitlement.

## Optional local work

Player state is read once, concurrently with course metadata, with a 250 ms
optional-read budget. The same result supplies completion hints, resume positions
and saved lessons. A missing or stuck cache falls back to fresh server state;
its late result restores untouched positions and completion hints without moving
the feed or replacing newer playback/route positions. Server bookmarks and newer
bookmark commands take precedence. An already loaded paused decoder can adopt an
initial resume hint before playback starts, never rewind an already started reel.
In-place course refreshes retain live positions and bookmarks while device reads
are pending; server reconciliation cannot replace bookmark commands issued during
the refresh's metadata request.

Playback preferences have a separate 250 ms device-read budget. If unavailable,
current playback begins conservatively with data saving and no adjacent preload.
The ongoing read may restore actual preferences after it settles. Late device or
profile results cannot override a manual preference change or a retired account.
Quality and speed own separate revisions. Settings and playback reuse the existing
keyed async queue through one account preference writer, so an old native write
finishes before a newer manual write rather than overwriting it afterward.
Both timely and late native read results check this shared field revision, not
only the hook-local revision, before being applied to playback.
Profile/network reconciliation does not gate first source acquisition.

## Backend and dashboard contract

No duplicate learning endpoint, entitlement schema, media provider or dashboard
toggle was introduced. `PlaybackManifestService` remains authoritative for
published media, section access and course revision recovery. Dashboard publishing
and media readiness retain their existing contract, including legacy v60 support.

## Deferred acceptance

The authored JS cases cover one-use/expiry/account transitions, a fresh metadata
handoff and authoritative reload, guest-vs-owned gates, stuck optional storage,
resume/completion preservation and late preference ownership. Execute these and
the existing playback/preview/revision suites at the final goal gate. Native
bridge mocks prove ownership/window contracts only, not period/sample reuse.
Installer fixtures prove rewrite anchors/idempotence only, not Kotlin or Java
compilation. Do not present either as native performance evidence.

Final native acceptance must prove the same preloaded period is consumed after
adjacent promotion, including A-to-B-to-A paging, mixed HLS/MP4/DASH, a late old
handoff callback after URI renewal, expiry/retirement before and after adoption,
same-URI retry, nonzero resume, and data-saving/pressure changes during adoption.
Resolve the added compile-classpath declarations in the existing Gradle locks
without upgrading the Media3 runtime. No lock regeneration was run in this step.

Final acceptance must measure physical mid-range Android first-frame time,
forward/back rapid paging, buffering, memory, background/foreground recovery,
signed-source expiry and account replacement. Static review cannot establish
TikTok/Instagram parity, instantaneous start or release readiness.

Independent static review accepted the refresh-position/bookmark ownership and
shared preference-read revisions after their corrections. This is scoped to
metadata/preferences; it is not an executed test result or acceptance of native
preloading. Independent static review subsequently accepted the P1 player-adoption
ordering and fluent-factory correction, then the P2 mixed-type factory availability.
These acceptances are limited to source contracts; the native runtime cases above
remain unexecuted and neither closes the overall speed defect.

## Final gate — adjacent manifest and decoder contract, 5 October 2026

The lifecycle suite freshly failed one stale source literal describing a single
next-item declaration. The actual manifest owner now iterates both immediate
neighbours. The corrected assertion requires that bounded loop, the session and
preload guard, locked-reel exclusion and the actual manifest request. The decoder
assertion also requires the native-preload pending fence and expiry check, and
forbids an adjacent decoder in native-preload mode. No product source changed.

The complete lifecycle, actual-hook manifest and controller/paging suites pass
24/24 in `mobile/.cache/final-gate-20261005/mobile-reels-preload-contract-final.log`.
The reproduction (`mobile-reels-lifecycle-reproduction.log`) was 14 passed/1
failed. Existing paused-decoder identity, background teardown, source/buffer
identity, recovery, viewability and playback-owner assertions remain intact.
Independent read-only review accepted this source-contract alignment. Native
player/bridge/network boundaries are doubles; these results do not prove sample
adoption, speed, memory, physical-device playback or store readiness. The actual
Media3/reference/license boundary above remains unchanged, not reimplemented.
