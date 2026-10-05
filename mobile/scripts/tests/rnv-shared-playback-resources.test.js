'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const {
  SUPPORTED_VERSION,
  transformSources,
} = require('../rnv-shared-playback-resources');

// Rewrite-contract fixtures only. Native compilation and Media3 period reuse
// require their separate final gates; these snippets do not prove playback.
const upstreamAnchors = () => ({
  plugin: `import androidx.media3.exoplayer.ExoPlayer
interface RNVExoplayerPlugin : RNVPlugin {
}`,
  manager: `import com.brentvatne.exoplayer.RNVExoplayerPlugin
class ReactNativeVideoManager {
    // ----------------------- RNV Exoplayer plugin specific methods -----------------------
}`,
  view: `class ReactExoplayerView {
        DefaultAllocator allocator = new DefaultAllocator(true, C.DEFAULT_BUFFER_SEGMENT_SIZE);
        RNVLoadControl loadControl = new RNVLoadControl(
                allocator,
                source.getBufferConfig()
        );

        bandwidthMeter.setInitialBitrateEstimate(initialBitRate);
        DefaultMediaSourceFactory mediaSourceFactory = new DefaultMediaSourceFactory(mediaDataSourceFactory);
        player = new ExoPlayer.Builder(getContext(), renderersFactory)
                .setTrackSelector(self.trackSelector)
                .setBandwidthMeter(bandwidthMeter)
                .setLoadControl(loadControl)
                .setMediaSourceFactory(mediaSourceFactory)
                .build();
}`,
});

test('shares allocator, concrete RNV bandwidth meter and playback looper without replacing RNV load control', () => {
  assert.equal(SUPPORTED_VERSION, '6.18.0');
  const rewritten = transformSources(upstreamAnchors());
  assert.match(rewritten.plugin, /val bandwidthMeter: DefaultBandwidthMeter/);
  assert.match(rewritten.manager, /if \(resources != null\) return resources/);
  assert.match(rewritten.view, /allocator = sharedResources\.getAllocator\(\)/);
  assert.match(
    rewritten.view,
    /this\.bandwidthMeter = sharedResources\.getBandwidthMeter\(\)/,
  );
  assert.match(
    rewritten.view,
    /RNVLoadControl loadControl = new RNVLoadControl\(allocator, source\.getBufferConfig\(\)\)/,
  );
  assert.match(
    rewritten.view,
    /playerBuilder\.setPlaybackLooper\(sharedResources\.getPlaybackLooper\(\)\)/,
  );
  assert.ok(
    rewritten.view.indexOf('setInitialBitrateEstimate') <
      rewritten.view.indexOf('overridePlaybackResources'),
  );
  assert.equal(rewritten.view.match(/DefaultAllocator allocator =/g).length, 1);
});

test('postinstall rewrite is idempotent', () => {
  const rewritten = transformSources(upstreamAnchors());
  assert.deepEqual(transformSources(rewritten), rewritten);
});

test('rejects drift, duplicated anchors and an incomplete player extension', () => {
  const sources = upstreamAnchors();
  assert.throws(
    () =>
      transformSources({
        ...sources,
        plugin: sources.plugin.replace('RNVPlugin', 'ChangedPlugin'),
      }),
    /re-audit/,
  );
  assert.throws(
    () =>
      transformSources({
        ...sources,
        manager: sources.manager + sources.manager,
      }),
    /re-audit/,
  );
  const rewritten = transformSources(sources);
  assert.throws(
    () =>
      transformSources({
        ...rewritten,
        view: rewritten.view.replace(
          'playerBuilder.setPlaybackLooper(sharedResources.getPlaybackLooper());',
          '',
        ),
      }),
    /partially applied/,
  );
  assert.throws(
    () =>
      transformSources({
        ...rewritten,
        view: rewritten.view.replace(
          'allocator = sharedResources.getAllocator();',
          '',
        ),
      }),
    /partially applied/,
  );
});
