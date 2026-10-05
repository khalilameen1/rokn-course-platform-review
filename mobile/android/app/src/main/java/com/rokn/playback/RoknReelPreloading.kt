/*
 * Copyright 2023 The Android Open Source Project
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * Resource wiring adapted from the AndroidX Media3 1.4.1 shortform demo
 * ViewPagerMediaAdapter.kt.
 * Changes: RNV plugin resources, signed bounded windows, owner retirement,
 * provider factories, device pressure and no second adjacent decoder.
 */
package com.rokn.playback

import android.app.ActivityManager
import android.content.ComponentCallbacks2
import android.content.Context
import android.content.res.Configuration
import android.os.Handler
import android.os.HandlerThread
import android.os.Looper
import android.os.Process
import androidx.annotation.OptIn
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.common.util.UnstableApi
import androidx.media3.exoplayer.DefaultRendererCapabilitiesList
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.RenderersFactory
import androidx.media3.exoplayer.drm.DrmSessionManagerProvider
import androidx.media3.exoplayer.dash.DashMediaSource
import androidx.media3.exoplayer.dash.DefaultDashChunkSource
import androidx.media3.exoplayer.hls.HlsMediaSource
import androidx.media3.exoplayer.source.MediaPeriod
import androidx.media3.exoplayer.source.MediaSource
import androidx.media3.exoplayer.source.ProgressiveMediaSource
import androidx.media3.exoplayer.source.WrappingMediaSource
import androidx.media3.exoplayer.source.preload.DefaultPreloadManager
import androidx.media3.exoplayer.source.preload.TargetPreloadStatusControl
import androidx.media3.exoplayer.trackselection.DefaultTrackSelector
import androidx.media3.exoplayer.upstream.DefaultBandwidthMeter
import androidx.media3.exoplayer.upstream.DefaultAllocator
import androidx.media3.exoplayer.upstream.Allocator
import androidx.media3.exoplayer.upstream.LoadErrorHandlingPolicy
import androidx.media3.datasource.DataSource
import com.brentvatne.common.api.Source
import com.brentvatne.exoplayer.RNVExoplayerPlugin
import com.brentvatne.react.ReactNativeVideoManager
import java.util.IdentityHashMap
import kotlin.math.abs

/** Owns samples, not entitlements. All inputs are already-issued signed sources. */
@OptIn(UnstableApi::class)
object RoknReelPreloading : RNVExoplayerPlugin, ComponentCallbacks2 {
    data class Entry(val uri: String, val index: Int, val type: String, val expiresAt: Long) {
        val mediaItem: MediaItem = MediaItem.Builder().setUri(uri).setMimeType(when (type) {
            "m3u8" -> MimeTypes.APPLICATION_M3U8
            "mpd" -> MimeTypes.APPLICATION_MPD
            else -> MimeTypes.VIDEO_MP4
        }).build()
    }

    private lateinit var context: Context
    private var installed = false
    private var current: Pool? = null
    private val pools = mutableSetOf<Pool>()
    private val players = IdentityHashMap<ExoPlayer, Pool>()
    private val main = Handler(Looper.getMainLooper())
    private const val OWNER_PREFIX = "rokn-reels:"

    fun install(applicationContext: Context) {
        if (installed) return
        context = applicationContext.applicationContext
        context.registerComponentCallbacks(this)
        ReactNativeVideoManager.getInstance().registerPlugin(this)
        installed = true
    }

    fun setWindow(owner: String, playingIndex: Int, entries: List<Entry>, enabled: Boolean) {
        check(Looper.myLooper() == Looper.getMainLooper())
        require(owner.startsWith(OWNER_PREFIX) && owner.length <= 180)
        require(entries.size <= 3 && entries.map { it.uri }.distinct().size == entries.size)
        require(entries.all { it.uri.startsWith("https://") && it.type in setOf("m3u8", "mpd", "mp4") && abs(it.index - playingIndex) <= 1 })
        if (current?.owner != owner) {
            retireCurrent()
            current = Pool(owner).also { pools.add(it) }
        }
        current?.update(playingIndex, entries, enabled)
    }

    fun clear(owner: String? = null) {
        check(Looper.myLooper() == Looper.getMainLooper())
        if (owner == null || current?.owner == owner) retireCurrent()
    }

    private fun retireCurrent() {
        val pool = current ?: return
        current = null
        pool.retire()
        disposeWhenUnused(pool)
    }

    private fun disposeWhenUnused(pool: Pool) {
        // RNV releases the player before its removal callback. Dispose next turn
        // so all already-enqueued release work precedes quitting the shared looper.
        main.post {
            if (pool.retired && !players.containsValue(pool)) {
                pool.dispose()
                pools.remove(pool)
            }
        }
    }

    private fun ownerPool(source: Source): Pool? = current?.takeIf {
        source.metadata?.description == it.owner && !it.retired &&
            source.headers.isEmpty() && it.contains(source.uri?.toString())
    }

    override fun overridePlaybackResources(
        source: Source,
        allocator: DefaultAllocator,
        bandwidthMeter: DefaultBandwidthMeter,
        renderersFactory: RenderersFactory,
    ): RNVExoplayerPlugin.PlaybackResources? =
        ownerPool(source)?.resources(allocator, bandwidthMeter, renderersFactory)

    override fun overrideMediaSourceFactory(source: Source, mediaSourceFactory: MediaSource.Factory, mediaDataSourceFactory: DataSource.Factory): MediaSource.Factory? {
        val pool = ownerPool(source) ?: return null
        val uri = source.uri?.toString() ?: return null
        val entry = pool.entry(uri) ?: return null
        // The same mature factories used by RNV's buildMediaSource switch, fed
        // by its actual DataSource factory. Neighbours must not wait for their
        // MIME type to have been played once. Keep RNV's exact current factory.
        val factories = mutableMapOf<String, MediaSource.Factory>(
            "m3u8" to HlsMediaSource.Factory(mediaDataSourceFactory)
                .setAllowChunklessPreparation(source.textTracksAllowChunklessPreparation),
            "mpd" to DashMediaSource.Factory(
                DefaultDashChunkSource.Factory(mediaDataSourceFactory), mediaDataSourceFactory,
            ),
            "mp4" to ProgressiveMediaSource.Factory(mediaDataSourceFactory),
        ).also { it[entry.type] = mediaSourceFactory }
        return object : MediaSource.Factory by mediaSourceFactory {
            // RNV configures these in a fluent chain after the plugin callback.
            // Returning the delegate would bypass this wrapper's create method.
            override fun setDrmSessionManagerProvider(provider: DrmSessionManagerProvider): MediaSource.Factory {
                factories.replaceAll { _, factory -> factory.setDrmSessionManagerProvider(provider) }
                return this
            }
            override fun setLoadErrorHandlingPolicy(policy: LoadErrorHandlingPolicy): MediaSource.Factory {
                factories.replaceAll { _, factory -> factory.setLoadErrorHandlingPolicy(policy) }
                return this
            }
            override fun createMediaSource(mediaItem: MediaItem): MediaSource {
                // Only publish a factory after RNV has applied its actual policy.
                pool.registerFactories(factories)
                return pool.playerSource(entry) ?: mediaSourceFactory.createMediaSource(mediaItem)
            }
        }
    }

    override fun onInstanceCreated(id: String, player: ExoPlayer) {
        val pool = pools.firstOrNull { it.playbackLooper == player.playbackLooper } ?: return
        players[player] = pool
    }

    override fun onInstanceRemoved(id: String, player: ExoPlayer) {
        val pool = players.remove(player) ?: return
        disposeWhenUnused(pool)
    }

    override fun onTrimMemory(level: Int) {
        if (level >= ComponentCallbacks2.TRIM_MEMORY_RUNNING_LOW) main.post { current?.suspendPreload() }
    }
    override fun onLowMemory() { main.post { current?.suspendPreload() } }
    override fun onConfigurationChanged(newConfig: Configuration) = Unit

    private class Pool(val owner: String) {
        private var entries = emptyList<Entry>()
        private var currentIndex = 0
        private var currentUri: String? = null
        private var handoffGeneration = 0L
        private var currentAdopted = false
        private var enabled = false
        private var pressureSuspended = false
        var retired = false
            private set
        private var thread: HandlerThread? = null
        val playbackLooper: Looper? get() = thread?.looper
        private var resources: RNVExoplayerPlugin.PlaybackResources? = null
        private var selector: DefaultTrackSelector? = null
        private var manager: DefaultPreloadManager? = null
        private val factories = mutableMapOf<String, MediaSource.Factory>()
        private val managed = mutableMapOf<String, Entry>()
        private val expiryTasks = mutableListOf<Runnable>()

        fun entry(uri: String): Entry? = entries.firstOrNull { it.uri == uri && it.expiresAt > System.currentTimeMillis() }
        fun contains(uri: String?): Boolean = uri != null && entry(uri) != null

        fun resources(allocator: DefaultAllocator, bandwidth: DefaultBandwidthMeter, renderers: RenderersFactory): RNVExoplayerPlugin.PlaybackResources {
            resources?.let { return it }
            val playbackThread = HandlerThread("rokn-reel-samples", Process.THREAD_PRIORITY_AUDIO).also { it.start() }
            thread = playbackThread
            val shared = RNVExoplayerPlugin.PlaybackResources(allocator, bandwidth, playbackThread.looper)
            resources = shared
            val trackSelector = DefaultTrackSelector(context).also {
                it.init({}, bandwidth)
            }
            selector = trackSelector
            val sourceFactory = object : MediaSource.Factory {
                override fun getSupportedTypes(): IntArray = intArrayOf(androidx.media3.common.C.CONTENT_TYPE_HLS, androidx.media3.common.C.CONTENT_TYPE_DASH, androidx.media3.common.C.CONTENT_TYPE_OTHER)
                override fun setDrmSessionManagerProvider(provider: androidx.media3.exoplayer.drm.DrmSessionManagerProvider): MediaSource.Factory = this
                override fun setLoadErrorHandlingPolicy(policy: androidx.media3.exoplayer.upstream.LoadErrorHandlingPolicy): MediaSource.Factory = this
                override fun createMediaSource(mediaItem: MediaItem): MediaSource {
                    val uri = mediaItem.localConfiguration?.uri?.toString()
                    val entry = entries.firstOrNull { it.uri == uri } ?: error("REEL_SOURCE_RETIRED")
                    return requireNotNull(factories[entry.type]).createMediaSource(mediaItem)
                }
            }
            // This is the actual Media3 preloader and the demo's shared wiring,
            // not a separate downloader or a paused adjacent ExoPlayer.
            manager = DefaultPreloadManager(
                TargetPreloadStatusControl<Int> { index ->
                    // A null target clears unused preloaded samples. Preserve
                    // the promoted current period until createPeriod transfers
                    // it to the player, as the demo plays before invalidate().
                    if (retired || !enabled || pressureSuspended || abs(index - currentIndex) > 1 || (index == currentIndex && currentAdopted)) null
                    else DefaultPreloadManager.Status(DefaultPreloadManager.Status.STAGE_LOADED_TO_POSITION_MS, 1500L)
                },
                sourceFactory, trackSelector, bandwidth,
                DefaultRendererCapabilitiesList.Factory(renderers), allocator, playbackThread.looper,
            )
            return shared
        }

        fun update(index: Int, next: List<Entry>, allowPreload: Boolean) {
            entries = next.filter { it.expiresAt > System.currentTimeMillis() }
            val playingUri = entries.firstOrNull { it.index == index }?.uri
            if (index != currentIndex || playingUri != currentUri) {
                handoffGeneration += 1
                currentAdopted = false
            }
            currentIndex = index
            currentUri = playingUri
            enabled = allowPreload
            val memory = ActivityManager.MemoryInfo()
            (context.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager).getMemoryInfo(memory)
            pressureSuspended = memory.lowMemory || memory.availMem < memory.threshold
            expiryTasks.forEach { main.removeCallbacks(it) }
            expiryTasks.clear()
            entries.forEach { item ->
                val expiry = Runnable {
                    if (!retired) {
                        entries = entries.filter { it.uri != item.uri }
                        reconcile()
                    }
                }
                expiryTasks.add(expiry)
                main.postDelayed(expiry, (item.expiresAt - System.currentTimeMillis()).coerceAtLeast(1))
            }
            reconcile()
        }

        fun registerFactories(configured: Map<String, MediaSource.Factory>) {
            if (retired || resources == null) return
            factories.putAll(configured)
            reconcile()
        }

        private fun reconcile() {
            val preload = manager ?: return
            val expected = entries.associateBy { it.uri }
            managed.toMap().forEach { (uri, old) ->
                val replacement = expected[uri]
                if (replacement == null || replacement.index != old.index) {
                    preload.remove(old.mediaItem)
                    managed.remove(uri)
                }
            }
            entries.forEach { item ->
                if (!managed.containsKey(item.uri) && factories.containsKey(item.type)) {
                    preload.add(item.mediaItem, item.index)
                    managed[item.uri] = item
                }
            }
            preload.setCurrentPlayingIndex(currentIndex)
            preload.invalidate()
        }

        fun playerSource(entry: Entry): MediaSource? {
            if (retired || !contains(entry.uri)) return null
            val preloaded = manager?.getMediaSource(entry.mediaItem) ?: return null
            val generation = handoffGeneration
            return object : WrappingMediaSource(preloaded) {
                override fun createPeriod(id: MediaSource.MediaPeriodId, allocator: Allocator, startPositionUs: Long): MediaPeriod {
                    // Media3 itself transfers preloadingMediaPeriodAndKey to
                    // playingPreloadedMediaPeriodAndId inside this call. No
                    // timer, JS acknowledgement or first-frame guess owns it.
                    val period = super.createPeriod(id, allocator, startPositionUs)
                    main.post {
                        if (!retired && generation == handoffGeneration &&
                            currentUri == entry.uri &&
                            manager?.getMediaSource(entry.mediaItem) === preloaded) {
                            currentAdopted = true
                            manager?.invalidate()
                        }
                    }
                    return period
                }
            }
        }

        fun suspendPreload() { pressureSuspended = true; manager?.invalidate() }

        fun retire() {
            if (retired) return
            retired = true
            expiryTasks.forEach { main.removeCallbacks(it) }
            expiryTasks.clear()
            entries = emptyList()
            manager?.release()
            manager = null
            managed.clear()
            factories.clear()
            selector?.release()
            selector = null
        }

        fun dispose() { retire(); thread?.quitSafely(); thread = null; resources = null }
    }
}
