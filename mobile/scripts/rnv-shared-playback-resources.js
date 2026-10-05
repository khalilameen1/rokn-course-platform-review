'use strict';

// One version-locked extension to RNV's existing plugin API. No player fork or
// reflective access. Upstream RNV retains its MIT license and load control.
const fs = require('node:fs');
const path = require('node:path');
const SUPPORTED_VERSION = '6.18.0';

function replaceOnce(source, before, after) {
  if (after && source.includes(after) && source.split(after).length === 2)
    return source;
  if (source.split(before).length !== 2 || (after && source.includes(after))) {
    throw new Error('RNV resource extension needs re-audit against upstream.');
  }
  return source.replace(before, after);
}

function transformSources(sources) {
  const pluginImports = 'import androidx.media3.exoplayer.ExoPlayer';
  const pluginHeader = 'interface RNVExoplayerPlugin : RNVPlugin {';
  const managerImports = 'import com.brentvatne.exoplayer.RNVExoplayerPlugin';
  const managerHeader =
    '    // ----------------------- RNV Exoplayer plugin specific methods -----------------------';
  const allocatorBlock = `        DefaultAllocator allocator = new DefaultAllocator(true, C.DEFAULT_BUFFER_SEGMENT_SIZE);
        RNVLoadControl loadControl = new RNVLoadControl(
                allocator,
                source.getBufferConfig()
        );

`;
  const mediaFactory =
    '        DefaultMediaSourceFactory mediaSourceFactory = new DefaultMediaSourceFactory(mediaDataSourceFactory);';
  const playerBlock = `        player = new ExoPlayer.Builder(getContext(), renderersFactory)
                .setTrackSelector(self.trackSelector)
                .setBandwidthMeter(bandwidthMeter)
                .setLoadControl(loadControl)
                .setMediaSourceFactory(mediaSourceFactory)
                .build();`;
  let plugin = replaceOnce(
    sources.plugin,
    pluginImports,
    `${pluginImports}
import android.os.Looper
import androidx.media3.exoplayer.RenderersFactory
import androidx.media3.exoplayer.upstream.DefaultBandwidthMeter
import androidx.media3.exoplayer.upstream.DefaultAllocator`,
  );
  plugin = replaceOnce(
    plugin,
    pluginHeader,
    `${pluginHeader}
    /** Rokn extension: optional shared resources; null preserves upstream behavior. */
    data class PlaybackResources(
        val allocator: DefaultAllocator,
        val bandwidthMeter: DefaultBandwidthMeter,
        val playbackLooper: Looper,
    )
    fun overridePlaybackResources(
        source: Source,
        allocator: DefaultAllocator,
        bandwidthMeter: DefaultBandwidthMeter,
        renderersFactory: RenderersFactory,
    ): PlaybackResources? = null
`,
  );
  let manager = replaceOnce(
    sources.manager,
    managerImports,
    `${managerImports}
import androidx.media3.exoplayer.RenderersFactory
import androidx.media3.exoplayer.upstream.DefaultBandwidthMeter
import androidx.media3.exoplayer.upstream.DefaultAllocator`,
  );
  manager = replaceOnce(
    manager,
    managerHeader,
    `${managerHeader}
    fun overridePlaybackResources(
        source: Source,
        allocator: DefaultAllocator,
        bandwidthMeter: DefaultBandwidthMeter,
        renderersFactory: RenderersFactory,
    ): RNVExoplayerPlugin.PlaybackResources? {
        for (plugin in pluginList) {
            if (plugin !is RNVExoplayerPlugin) continue
            val resources = plugin.overridePlaybackResources(source, allocator, bandwidthMeter, renderersFactory)
            if (resources != null) return resources
        }
        return null
    }
`,
  );
  const viewMarker =
    '        // Rokn shared resource extension: preserve RNVLoadControl with the selected allocator.';
  let view = sources.view;
  if (!view.includes(viewMarker)) {
    view = replaceOnce(view, allocatorBlock, '');
    view = replaceOnce(
      view,
      mediaFactory,
      `${viewMarker}
        DefaultAllocator allocator = new DefaultAllocator(true, C.DEFAULT_BUFFER_SEGMENT_SIZE);
        com.brentvatne.exoplayer.RNVExoplayerPlugin.PlaybackResources sharedResources =
                ReactNativeVideoManager.Companion.getInstance().overridePlaybackResources(
                        source, allocator, bandwidthMeter, renderersFactory);
        if (sharedResources != null) {
            allocator = sharedResources.getAllocator();
            this.bandwidthMeter = sharedResources.getBandwidthMeter();
        }
        RNVLoadControl loadControl = new RNVLoadControl(allocator, source.getBufferConfig());

${mediaFactory}`,
    );
    view = replaceOnce(
      view,
      playerBlock,
      `        ExoPlayer.Builder playerBuilder = new ExoPlayer.Builder(getContext(), renderersFactory)
                .setTrackSelector(self.trackSelector)
                .setBandwidthMeter(bandwidthMeter)
                .setLoadControl(loadControl)
                .setMediaSourceFactory(mediaSourceFactory);
        if (sharedResources != null) {
            playerBuilder.setPlaybackLooper(sharedResources.getPlaybackLooper());
        }
        player = playerBuilder.build();`,
    );
  } else if (
    [
      'allocator = sharedResources.getAllocator();',
      'this.bandwidthMeter = sharedResources.getBandwidthMeter();',
      'RNVLoadControl loadControl = new RNVLoadControl(allocator, source.getBufferConfig());',
      'playerBuilder.setPlaybackLooper(sharedResources.getPlaybackLooper());',
      'player = playerBuilder.build();',
    ].some(required => view.split(required).length !== 2) ||
    view.includes(allocatorBlock) ||
    view.includes(playerBlock)
  ) {
    throw new Error('RNV resource extension is only partially applied.');
  }
  return {plugin, manager, view};
}

function applyExtension({
  root = path.resolve(__dirname, '..'),
  check = false,
} = {}) {
  const packageRoot = path.join(root, 'node_modules', 'react-native-video');
  const version = JSON.parse(
    fs.readFileSync(path.join(packageRoot, 'package.json'), 'utf8'),
  ).version;
  if (version !== SUPPORTED_VERSION)
    throw new Error(`RNV ${version} is not audited ${SUPPORTED_VERSION}.`);
  const names = {
    plugin:
      'android/src/main/java/com/brentvatne/exoplayer/RNVExoplayerPlugin.kt',
    manager:
      'android/src/main/java/com/brentvatne/react/ReactNativeVideoManager.kt',
    view: 'android/src/main/java/com/brentvatne/exoplayer/ReactExoplayerView.java',
  };
  const sources = Object.fromEntries(
    Object.entries(names).map(([key, name]) => [
      key,
      fs
        .readFileSync(path.join(packageRoot, name), 'utf8')
        .replace(/\r\n/g, '\n'),
    ]),
  );
  const transformed = transformSources(sources);
  // Validate the complete extension before performing any mechanical rewrite.
  if (
    check &&
    Object.keys(names).some(key => transformed[key] !== sources[key])
  ) {
    throw new Error('RNV shared resources extension is not installed.');
  }
  for (const [key, name] of Object.entries(names)) {
    if (transformed[key] !== sources[key])
      fs.writeFileSync(path.join(packageRoot, name), transformed[key], 'utf8');
  }
  return {version};
}
if (require.main === module)
  applyExtension({check: process.argv.includes('--check')});
module.exports = {SUPPORTED_VERSION, transformSources, applyExtension};
