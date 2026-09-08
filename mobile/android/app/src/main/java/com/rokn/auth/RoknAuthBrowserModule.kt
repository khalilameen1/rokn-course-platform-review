package com.rokn.auth

import android.net.Uri
import androidx.browser.customtabs.CustomTabsClient
import androidx.browser.customtabs.CustomTabsIntent
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import kotlin.math.roundToInt

class RoknAuthBrowserModule(
  private val reactContext: ReactApplicationContext,
) : ReactContextBaseJavaModule(reactContext) {
  override fun getName(): String = "RoknAuthBrowser"

  @ReactMethod
  fun open(url: String, promise: Promise) {
    val uri = runCatching { Uri.parse(url) }.getOrNull()
    if (uri?.scheme != "https" || uri.host.isNullOrBlank()) {
      promise.reject("AUTH_BROWSER_URL_INVALID", "Only HTTPS authorization URLs are supported")
      return
    }

    val activity = reactApplicationContext.currentActivity
    if (activity == null) {
      promise.reject("AUTH_BROWSER_NO_ACTIVITY", "Authorization cannot be opened right now")
      return
    }

    activity.runOnUiThread {
      try {
        val decorHeight = activity.window.decorView.height
          .takeIf { it > 0 }
          ?: activity.resources.displayMetrics.heightPixels
        val initialHeight = (decorHeight * INITIAL_HEIGHT_RATIO).roundToInt()
        val provider = CustomTabsClient.getPackageName(activity, null)
        val customTab = CustomTabsIntent.Builder()
          .setInitialActivityHeightPx(initialHeight)
          .setToolbarCornerRadiusDp(TOOLBAR_CORNER_RADIUS_DP)
          .setShareState(CustomTabsIntent.SHARE_STATE_OFF)
          .setShowTitle(true)
          .build()
        customTab.intent.data = uri
        if (!provider.isNullOrBlank()) customTab.intent.setPackage(provider)

        // startActivityForResult is the documented no-service prerequisite for
        // a partial Custom Tab. The OAuth deep link remains owned by JavaScript.
        activity.startActivityForResult(customTab.intent, AUTH_BROWSER_REQUEST)
        promise.resolve(true)
      } catch (exception: RuntimeException) {
        promise.reject("AUTH_BROWSER_UNAVAILABLE", "Authorization browser is unavailable", exception)
      }
    }
  }

  companion object {
    private const val AUTH_BROWSER_REQUEST = 7302
    private const val INITIAL_HEIGHT_RATIO = 0.85
    private const val TOOLBAR_CORNER_RADIUS_DP = 16
  }
}
