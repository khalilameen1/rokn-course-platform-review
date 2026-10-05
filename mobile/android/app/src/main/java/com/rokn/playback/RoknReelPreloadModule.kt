package com.rokn.playback

import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import com.facebook.react.bridge.ReadableArray
import com.facebook.react.bridge.UiThreadUtil

class RoknReelPreloadModule(context: ReactApplicationContext) : ReactContextBaseJavaModule(context) {
    private var lastCommand = 0L
    private var invalidated = false
    override fun getName() = "RoknReelPreload"

    @ReactMethod
    fun setWindow(owner: String, command: Double, currentIndex: Double, items: ReadableArray, enabled: Boolean, promise: Promise) {
        try {
            require(command.isFinite() && command > 0 && command % 1.0 == 0.0)
            require(currentIndex.isFinite() && currentIndex >= 0 && currentIndex % 1.0 == 0.0)
            require(items.size() <= 3)
            val entries = (0 until items.size()).map { index ->
                val item = requireNotNull(items.getMap(index))
                val ordinal = item.getDouble("index")
                val expiry = item.getDouble("expiresAt")
                require(ordinal.isFinite() && ordinal >= 0 && ordinal % 1.0 == 0.0 && expiry.isFinite())
                RoknReelPreloading.Entry(
                    requireNotNull(item.getString("uri")), ordinal.toInt(),
                    requireNotNull(item.getString("type")), expiry.toLong(),
                )
            }
            UiThreadUtil.runOnUiThread {
                if (invalidated || command.toLong() <= lastCommand) {
                    promise.resolve(false)
                    return@runOnUiThread
                }
                try {
                    RoknReelPreloading.setWindow(owner, currentIndex.toInt(), entries, enabled)
                    lastCommand = command.toLong()
                    promise.resolve(true)
                } catch (failure: Exception) {
                    promise.reject("REEL_PRELOAD_WINDOW_INVALID", failure)
                }
            }
        } catch (failure: Exception) {
            promise.reject("REEL_PRELOAD_WINDOW_INVALID", failure)
        }
    }

    @ReactMethod
    fun clear(owner: String, command: Double) {
        UiThreadUtil.runOnUiThread {
            if (!invalidated && command.isFinite() && command.toLong() > lastCommand) {
                lastCommand = command.toLong()
                RoknReelPreloading.clear(owner)
            }
        }
    }

    override fun invalidate() {
        UiThreadUtil.runOnUiThread {
            invalidated = true
            RoknReelPreloading.clear()
        }
        super.invalidate()
    }
}
