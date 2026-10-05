package com.rokn.checkout

import android.app.Activity
import android.content.Intent
import android.content.ActivityNotFoundException
import android.net.Uri
import com.facebook.react.bridge.ActivityEventListener
import com.facebook.react.bridge.BaseActivityEventListener
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod

class RoknCheckoutModule(
  private val reactContext: ReactApplicationContext,
) : ReactContextBaseJavaModule(reactContext) {
  private var checkoutPromise: Promise? = null

  private val activityListener: ActivityEventListener =
    object : BaseActivityEventListener() {
      override fun onActivityResult(
        activity: Activity,
        requestCode: Int,
        resultCode: Int,
        data: Intent?,
      ) {
        if (requestCode != CHECKOUT_REQUEST) return

        val promise = checkoutPromise ?: return
        checkoutPromise = null
        if (resultCode == Activity.RESULT_OK) {
          promise.resolve(data?.getStringExtra(CheckoutActivity.RESULT_URL) ?: "")
        } else {
          promise.reject("CHECKOUT_CANCELLED", "Checkout was closed before completion")
        }
      }
    }

  init {
    reactContext.addActivityEventListener(activityListener)
  }

  override fun getName(): String = "RoknCheckout"

  @ReactMethod
  fun openBrowser(url: String, promise: Promise) {
    val uri = Uri.parse(url)
    if (uri.scheme != "https" || uri.host.isNullOrBlank() || uri.userInfo != null) {
      promise.reject("PAYMENT_URL_INVALID", "Invalid payment URL")
      return
    }
    val intent = Intent(Intent.ACTION_VIEW, uri).apply {
      addCategory(Intent.CATEGORY_BROWSABLE)
      addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
      setPackage("com.android.chrome")
    }
    try {
      try {
        reactContext.startActivity(intent)
      } catch (_: ActivityNotFoundException) {
        // Devices without Chrome may use their installed browser, never WebView.
        intent.setPackage(null)
        reactContext.startActivity(intent)
      }
      promise.resolve(null)
    } catch (exception: Exception) {
      promise.reject("CHECKOUT_BROWSER_UNAVAILABLE", "Cannot open browser", exception)
    }
  }

  @ReactMethod
  fun open(url: String, promise: Promise) {
    val activity = reactApplicationContext.currentActivity
    if (activity == null) {
      promise.reject("NO_ACTIVITY", "The checkout cannot be opened right now")
      return
    }
    if (checkoutPromise != null) {
      promise.reject("CHECKOUT_ACTIVE", "Another checkout is already open")
      return
    }

    checkoutPromise = promise
    val intent = Intent(activity, CheckoutActivity::class.java).apply {
      putExtra(CheckoutActivity.EXTRA_URL, url)
    }
    activity.startActivityForResult(intent, CHECKOUT_REQUEST)
  }

  companion object {
    private const val CHECKOUT_REQUEST = 7301
  }
}
