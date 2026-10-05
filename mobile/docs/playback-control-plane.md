# Rokn playback control plane

This layer keeps Bunny as the media provider and makes Rokn the authority that
decides whether, how, and for whom a lesson plays.

## Runtime contract

1. An authenticated learner opens an accessible lesson.
2. `POST /api/v1/lessons/{lesson}/playback-manifest` checks the existing course
   and module entitlement policy.
3. The backend returns a short-lived signed HLS URL, only qualities known to
   exist, media readiness, and a UUID playback session.
4. The app keeps that session in memory and attaches an increasing `sequence`
   to watch-history heartbeats.
5. The backend ignores duplicate or out-of-order samples before they reach
   learning evidence or rewards. Resume position is monotonic across devices.

The existing course video URL remains a temporary compatibility fallback if
the manifest control plane is unreachable. It must not be treated as the
source of truth by new clients.

## Media lifecycle

`lesson_media_states.status` is one of:

- `unknown`: legacy media has not been probed yet.
- `processing`: upload exists but usable renditions are not confirmed.
- `ready`: Bunny reports a playable rendition.
- `failed`: source is missing or the provider reports a failed encode.

New and replaced uploads enter `processing`. Media Health can probe an item
without publishing or changing the lesson pointer. Explicit course publishing
is blocked when a known media state is not `ready`; already-published legacy
courses are not silently unpublished.

## Compatibility and rollback

- No payment, wallet, grant, chat, certificate, project, or portfolio schema is
  changed.
- Legacy clients may continue sending watch history without a session; they
  retain the previous server-qualified evidence rules.
- Removing the manifest request from the app restores the former playback path.
- The migration rollback drops only playback sessions and media-health state;
  lesson and progress records remain intact.
- P2P distribution, federation, server-side transcoding, downloads, and
  subtitles are intentionally outside this layer.

## Deployment order

1. Deploy backend code.
2. Run the new migration.
3. Probe the media items shown in Product Operations and resolve failures.
4. Publish the mobile build.
5. Watch `media_attention`, playback sessions, provider errors, and duplicate
   sequence rates during rollout.

## Playback preferences when offline

Quality and speed changes take effect locally without waiting for the profile
API. Signed-in accounts first persist a coalescing command in
`@rokn/pending-playback-preferences/v1` under the existing account scope.
Guest preferences remain local. This is preference synchronization, not an
offline entitlement, purchase, or media download mechanism.

The architectural reference is Expensify's
[Offline UX Pattern A](https://github.com/Expensify/App/blob/main/contributingGuides/philosophies/OFFLINE.md).
Its [published app](https://play.google.com/store/apps/details?id=org.me.mobiexpensifyg&hl=en-US)
lists more than one million downloads. Rokn does not copy Expensify API or Onyx
source code: this implementation uses Rokn's existing account boundaries,
AsyncStorage, keyed native write queue, and profile endpoint.

Actual library reuse is `@react-native-community/netinfo` 11.5.2 for connectivity
events. Expensify's [package manifest](https://github.com/Expensify/App/blob/main/package.json)
uses the same library at 11.4.1; Rokn uses the version listed for
[Expo SDK 55](https://docs.expo.dev/versions/v55.0.0/sdk/netinfo/), not Expensify's
exact version. The upstream MIT license is preserved in
`scripts/licenses/upstream/react-native-netinfo-11.5.2-LICENSE`.

The local write queue never owns a network request. Settings toggle transactions
are ordered independently by account and field; their native writes alone use
the shared preference queue. A slow privacy or notification request therefore
cannot prevent a playback change from reaching durable storage.

Playback synchronization has one HTTP worker per account. An acknowledgement
retires only its persisted revision after the local cache is valid. A newer
edit is drained by the worker rather than erased by an older response. Failed
HTTP, cache repair, or journal deletion leaves the command for recovery at app
entry, foreground return, or connectivity restoration. Reads that started
while a command was pending cannot overwrite it with an older profile response.
Session epoch checks prevent old responses from touching a replacement session.

No backend or dashboard schema change is required. The existing profile update
accepts partial quality and speed fields, including the `data_saver` to `360p`
API mapping. Backend profile tests have been authored for persistence, repeated
partial updates, and rejected values; this is not a claim that they have run.

## Final preference verification

Source changes and regression cases are local. Runtime acceptance is deferred
to the requested combined final gate. It must include:

- Installing the locked dependency and checking Android and iOS native linking,
  including the iOS lockfile.
- Regenerating and verifying the package and native third-party notices. The
  preserved upstream license does not replace the generated app inventory.
- Running playback journal, settings ordering, readiness, and backend profile
  tests, plus the release type, lint, and compatibility checks.
- On a device, editing quality and speed offline, terminating and reopening the
  app, reconnecting while an old request is pending, and switching accounts.
- Verifying that a pending notification or privacy request does not delay the
  durable playback write, and that failed notification activation retains the
  existing permission retry behavior.

## Final gate — shared settings writer contract, 5 October 2026

The startup suite freshly passed seven cases and failed an old source literal
for the previously inline `serializeSettingsWrites`. The actual native writer
now lives in `accountPreferenceWrites`. The corrected source contract requires
its account-keyed queue and boundary checks before and after the operation, the
settings import/binding, the independent account/field toggle queue and reuse
of an explicitly supplied account owner before attempting fresh capture.
Revision, hydration and enqueue assertions remain intact. No production source,
approved splash/UI, backend field, dashboard or dependency changed.

The full startup and actual settings-ordering suites pass 94/94 in
`mobile/.cache/final-gate-20261005/mobile-startup-preference-owner-final.log`.
The latter retains storage/HTTP/permission failures, stale account/choice
callbacks, rollback and playback writes while privacy or notification HTTP is
pending. Independent read-only review accepted this narrow contract alignment.
Controlled native/storage/network boundaries do not prove physical-device
behavior or release readiness. The reproduction is retained in
`mobile-startup-contract-reproduction.log`; reference/reuse limits above apply.

### NetInfo package notices — final gate, 5 October 2026

The release-script gate found that the generated package legal inventory still
represented 734 coordinates/817 lock paths, while the current production
closure has 735/818. The only added coordinate is the already-approved actual
NetInfo 11.5.2 dependency above; no coordinate was removed. The existing notices
generator downloaded the exact lockfile tarballs and verified their integrity,
then regenerated its legal snapshot, app data, Markdown and Android/iOS npm
notice copies. It retained 610 package-root records and the same 125 reviewed
absence records. No new library, exception, license selector or generator
behavior was introduced.

The regression's exact counts now describe that closure. An added assertion
requires NetInfo's published MIT LICENSE to match both the installed source and
the retained upstream copy; two missing blank paragraph lines in the latter
were restored to match upstream, without changing legal wording. All five
package-notice cases and the actual generator `--check` pass in
`mobile/.cache/final-gate-20261005/mobile-third-party-license-final.log` and
`mobile-license-check-final.log`. Independent read-only review accepted this
limited derived-artifact update, including unchanged fail-closed inventory and
published Apache NOTICE checks.

This is npm-source attribution, not native inventory acceptance. The separate
current iOS lock check reports missing autolinked `react-native-netinfo`; its
Podfile.lock must be regenerated through the real macOS/Bundler/CocoaPods flow,
not edited by hand. Native linking and physical-device verification remain
open. No build, push or store upload occurred for this notice update.

### Android attribution after actual Gradle resolution

The Android-only final gate initially found that the retained release inventory
had 23 npm-source projects while the actual release configuration now resolves
24. Regenerating with the existing native notices tool adds NetInfo alone and
keeps all 241 Maven coordinates. Its exact npm integrity, MIT legal text hash
and source record are bound to the component and included in the shipped
Android notice and app inventory. The actual `--check --android-only` passes in
`mobile-native-android-notices-accepted.log`. All 12 native-notice cases pass in
`mobile-native-notices-regression-final.log`, including added NetInfo lock/
license-source/app-inventory assertions and unchanged omission/unknown-license
rejection. The four former exact 23 counts now require 24, not an unbounded
minimum. Independent read-only review accepted this Android attribution unit.

These files are under the same final-gate log directory. The existing retained
iOS snapshot was not updated by this Android-only operation. The missing iOS
autolinked pod above and device integration are still unresolved; no native
binary, push, deployment or store upload was performed for this update.
