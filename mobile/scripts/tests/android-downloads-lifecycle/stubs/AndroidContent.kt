package android.content

import android.app.DownloadManager
import android.net.Uri
import java.io.Closeable
import java.io.IOException
import java.io.FileNotFoundException
import java.io.InputStream

class SharedPreferences {
  private val values = linkedMapOf<String, Any>()
  val all: Map<String, Any> get() = values.toMap()
  fun getLong(key: String, fallback: Long): Long = values[key] as? Long ?: fallback
  fun getBoolean(key: String, fallback: Boolean): Boolean = values[key] as? Boolean ?: fallback
  fun edit(): Editor = Editor()
  inner class Editor {
    private val pending = linkedMapOf<String, Any?>()
    private var clear = false
    fun putLong(key: String, value: Long) = apply { pending[key] = value }
    fun putBoolean(key: String, value: Boolean) = apply { pending[key] = value }
    fun remove(key: String) = apply { pending[key] = null }
    fun clear() = apply { clear = true }
    fun apply() {
      if (clear) values.clear()
      pending.forEach { (key, value) -> if (value == null) values.remove(key) else values[key] = value }
    }
  }
}

class ContentResolver(private val manager: DownloadManager) {
  var inputFailure = false
  var fileNotFoundFailure = false
  var descriptorFailure = false
  var nullInput = false
  var nullDescriptor = false
  var descriptorLength: Long? = null
  fun openInputStream(uri: Uri): InputStream? {
    if (fileNotFoundFailure) throw FileNotFoundException("No content provider")
    if (inputFailure) throw IOException("File provider is temporarily unavailable")
    return if (nullInput) null else manager.file(uri).inputStream()
  }
  class AssetFileDescriptor(val length: Long) : Closeable { override fun close() {} }
  fun openAssetFileDescriptor(uri: Uri, mode: String): AssetFileDescriptor? {
    if (descriptorFailure) throw IOException("File provider is temporarily unavailable")
    return if (nullDescriptor) null else AssetFileDescriptor(descriptorLength ?: manager.file(uri).length())
  }
}

open class Context(
  private val manager: DownloadManager,
  val contentResolver: ContentResolver,
  private val preferences: SharedPreferences,
) {
  val activities = mutableListOf<Intent>()
  fun getSystemService(name: String): Any = manager
  fun getSharedPreferences(name: String, mode: Int): SharedPreferences = preferences
  fun startActivity(intent: Intent) { activities.add(intent) }
  companion object { const val DOWNLOAD_SERVICE = "download"; const val MODE_PRIVATE = 0 }
}

class Intent(val action: String) {
  private val extras = mutableMapOf<String, Long>()
  var data: Uri? = null
  var mime: String? = null
  fun setDataAndType(uri: Uri, type: String) = apply { data = uri; mime = type }
  fun addFlags(flags: Int) = this
  fun putExtra(key: String, value: Long) = apply { extras[key] = value }
  fun getLongExtra(key: String, fallback: Long): Long = extras[key] ?: fallback
  companion object {
    const val ACTION_VIEW = "android.intent.action.VIEW"
    const val FLAG_ACTIVITY_NEW_TASK = 1
    const val FLAG_GRANT_READ_URI_PERMISSION = 2
  }
}
abstract class BroadcastReceiver { abstract fun onReceive(context: Context, intent: Intent) }
