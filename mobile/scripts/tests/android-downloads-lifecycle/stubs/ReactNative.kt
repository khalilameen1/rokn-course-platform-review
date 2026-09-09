package com.facebook.react.bridge

import android.app.DownloadManager
import android.content.ContentResolver
import android.content.Context
import android.content.SharedPreferences

interface Promise {
  fun resolve(value: Any?)
  fun reject(code: String, message: String, error: Throwable? = null)
}
class ReactApplicationContext(manager: DownloadManager, resolver: ContentResolver, preferences: SharedPreferences) :
  Context(manager, resolver, preferences)
abstract class ReactContextBaseJavaModule(context: ReactApplicationContext) { abstract fun getName(): String }
annotation class ReactMethod
