package com.facebook.react.bridge

import android.app.DownloadManager
import android.app.Activity
import android.content.ContentResolver
import android.content.Context
import android.content.SharedPreferences
import com.facebook.react.common.LifecycleState

interface Promise {
  fun resolve(value: Any?)
  fun reject(code: String, message: String, error: Throwable? = null)
}
class ReactApplicationContext(manager: DownloadManager, resolver: ContentResolver, preferences: SharedPreferences) :
  Context(manager, resolver, preferences) {
  var lifecycleState = LifecycleState.RESUMED
  var currentActivity: Activity? = Activity()
}
abstract class ReactContextBaseJavaModule(context: ReactApplicationContext) { abstract fun getName(): String }
annotation class ReactMethod
