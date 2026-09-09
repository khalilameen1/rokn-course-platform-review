package com.rokn.downloads

import android.app.DownloadManager
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Environment
import android.os.StatFs
import android.system.ErrnoException
import android.system.Os
import android.system.OsConstants
import android.webkit.MimeTypeMap
import androidx.core.net.toUri
import com.facebook.react.bridge.Arguments
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import com.facebook.react.common.LifecycleState
import com.rokn.BuildConfig
import java.io.FileNotFoundException
import java.io.IOException
import java.security.MessageDigest

class RoknDownloadsModule(
  private val reactContext: ReactApplicationContext,
) : ReactContextBaseJavaModule(reactContext) {
  override fun getName(): String = "RoknDownloads"

  @ReactMethod
  fun inspectMetadata(url: String, promise: Promise) {
    Thread({
      try {
        promise.resolve(AttachmentMetadataProbe.inspect(url))
      } catch (error: Exception) {
        promise.reject("DOWNLOAD_METADATA_FAILED", "The host could not provide file metadata", error)
      }
    }, "rokn-attachment-metadata").start()
  }

  @ReactMethod
  fun enqueue(
    url: String,
    title: String,
    fileName: String,
    mimeType: String,
    stableKey: String,
    expectedBytes: Double,
    promise: Promise,
  ) = enqueueFile(url, title, fileName, mimeType, stableKey, expectedBytes, false, promise)

  @ReactMethod
  fun enqueueExternal(
    url: String,
    title: String,
    fileName: String,
    mimeType: String,
    stableKey: String,
    expectedBytes: Double,
    promise: Promise,
  ) = enqueueFile(url, title, fileName, mimeType, stableKey, expectedBytes, true, promise)

  private fun enqueueFile(
    url: String,
    title: String,
    fileName: String,
    mimeType: String,
    stableKey: String,
    expectedBytes: Double,
    external: Boolean,
    promise: Promise,
  ) {
    try {
      val uri = url.toUri()
      val scheme = uri.scheme.orEmpty()
      val allowed = scheme.equals("https", ignoreCase = true) ||
        (BuildConfig.DEBUG && scheme.equals("http", ignoreCase = true))
      if (!allowed || uri.host.isNullOrBlank() || !uri.userInfo.isNullOrEmpty()) {
        promise.reject("INVALID_DOWNLOAD_URL", "Only secure download links are supported")
        return
      }

      val safeName = sanitizeFileName(fileName, stableKey)
      val expectedSize = expectedBytes.toLong().coerceAtLeast(0L)
      val manager = reactContext.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
      val preferenceKey = "download:${sha256(stableKey)}"
      val preferences = reactContext.getSharedPreferences("rokn_downloads", Context.MODE_PRIVATE)
      val existingId = preferences.getLong(preferenceKey, -1L)
      if (existingId > 0) {
        val existingStatus = queryStatus(manager, existingId)
        if (existingStatus == DownloadManager.STATUS_SUCCESSFUL) {
          if (downloadedFileExists(manager, existingId, expectedSize)) {
            val opened = openDownloadedFile(manager, existingId)
            promise.resolve(downloadResult(existingId, if (opened) "opened" else "completed", true))
            return
          }
          manager.remove(existingId)
          preferences.edit().remove(preferenceKey).apply()
        }
        if (
          existingStatus == DownloadManager.STATUS_PENDING ||
          existingStatus == DownloadManager.STATUS_RUNNING ||
          existingStatus == DownloadManager.STATUS_PAUSED
        ) {
          promise.resolve(downloadResult(existingId, "running", true))
          return
        }
        preferences.edit().remove(preferenceKey).apply()
        promise.reject(
          "DOWNLOAD_RETRY_REQUIRES_REFRESH",
          "The previous system download failed and its link must be refreshed",
        )
        return
      }

      if (expectedSize > 0 && !hasDownloadSpace(expectedSize)) {
        promise.reject("INSUFFICIENT_STORAGE", "There is not enough free storage for this download")
        return
      }

      val request = DownloadManager.Request(uri)
        .setTitle(title.trim().take(80).ifBlank { safeName })
        .setDescription("جاري تنزيل مرفق الكورس")
        .setNotificationVisibility(
          DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED,
        )
        .setAllowedOverMetered(true)
        .setAllowedOverRoaming(true)

      val explicitMime = mimeType.trim().lowercase().takeIf { it.contains('/') }
      val urlExtension = MimeTypeMap.getFileExtensionFromUrl(uri.toString()).lowercase()
      val fileExtension = safeName.substringAfterLast('.', "").lowercase()
      val resolvedMime = explicitMime
        ?: MimeTypeMap.getSingleton().getMimeTypeFromExtension(urlExtension)
        ?: MimeTypeMap.getSingleton().getMimeTypeFromExtension(fileExtension)
        ?: "application/octet-stream"
      request.setMimeType(resolvedMime)

      // The JS boundary asks for the legacy permission only on Android 7–9.
      // Keeping the destination public on every supported version makes a
      // downloaded course attachment a user-owned file that survives uninstall.
      request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, safeName)

      val downloadId = manager.enqueue(request)
      preferences.edit().putLong(preferenceKey, downloadId)
        .putBoolean("external:$downloadId", external).apply()
      promise.resolve(downloadResult(downloadId, "started", false))
    } catch (error: Exception) {
      promise.reject("DOWNLOAD_FAILED", "The download could not be started", error)
    }
  }

  @ReactMethod
  fun cancelIfActive(downloadId: Double, promise: Promise) {
    try {
      val id = downloadId.toLong()
      val manager = reactContext.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
      val status = queryStatus(manager, id)
      val cancelled =
        status == DownloadManager.STATUS_PENDING ||
          status == DownloadManager.STATUS_RUNNING ||
          status == DownloadManager.STATUS_PAUSED
      if (cancelled) {
        manager.remove(id)
        val preferences = reactContext.getSharedPreferences("rokn_downloads", Context.MODE_PRIVATE)
        val editor = preferences.edit()
        editor.remove("external:$id")
        preferences.all
          .filterValues { value -> (value as? Long) == id }
          .keys
          .forEach(editor::remove)
        editor.apply()
      }
      promise.resolve(cancelled)
    } catch (error: Exception) {
      promise.reject("DOWNLOAD_CANCEL_FAILED", "The download could not be cancelled", error)
    }
  }

  /** Cancel transfers restored by DownloadManager after a previous process died. */
  @ReactMethod
  fun cancelAllActive(promise: Promise) {
    try {
      val manager = reactContext.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
      val preferences = reactContext.getSharedPreferences("rokn_downloads", Context.MODE_PRIVATE)
      val ids = preferences.all.values.mapNotNull { value -> value as? Long }.distinct()
      ids.forEach { id ->
        val status = queryStatus(manager, id)
        if (
          status == DownloadManager.STATUS_PENDING ||
          status == DownloadManager.STATUS_RUNNING ||
          status == DownloadManager.STATUS_PAUSED
        ) {
          manager.remove(id)
        }
      }
      preferences.edit().clear().apply()
      promise.resolve(true)
    } catch (error: Exception) {
      promise.reject("DOWNLOAD_CANCEL_FAILED", "Active downloads could not be cancelled", error)
    }
  }

  private fun queryStatus(manager: DownloadManager, downloadId: Long): Int? {
    // A null provider result is unavailable, not proof that its job is gone.
    // Preserve the ledger so retry/cancellation can resume when it recovers.
    val cursor = manager.query(DownloadManager.Query().setFilterById(downloadId))
      ?: throw IOException("The download status provider is unavailable")
    return cursor.use {
      if (!cursor.moveToFirst()) return@use null
      val column = cursor.getColumnIndex(DownloadManager.COLUMN_STATUS)
      if (column < 0) throw IOException("The download status is unavailable")
      cursor.getInt(column)
    }
  }

  private fun openDownloadedFile(manager: DownloadManager, downloadId: Long): Boolean {
    val uri = manager.getUriForDownloadedFile(downloadId) ?: return false
    val mime = manager.getMimeTypeForDownloadedFile(downloadId) ?: "application/octet-stream"
    // Android may silently abort a background activity launch. Keep a completed
    // receipt instead of claiming the viewer opened, and never defer an auto-open.
    val activity = reactContext.currentActivity
    if (
      reactContext.lifecycleState != LifecycleState.RESUMED || activity == null ||
      activity.isFinishing || activity.isDestroyed
    ) return false
    return try {
      reactContext.startActivity(
        Intent(Intent.ACTION_VIEW)
          .setDataAndType(uri, mime)
          .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_GRANT_READ_URI_PERMISSION),
      )
      true
    } catch (_: Exception) {
      try {
        reactContext.startActivity(
          Intent(DownloadManager.ACTION_VIEW_DOWNLOADS)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
        )
      } catch (_: Exception) {
        // The completed notification remains the final system-owned fallback.
      }
      false
    }
  }

  private fun downloadedFileExists(
    manager: DownloadManager,
    downloadId: Long,
    expectedBytes: Long,
  ): Boolean {
    val uri = manager.getUriForDownloadedFile(downloadId)
      ?: throw IOException("The downloaded file URI is unavailable")
    try {
      if (AttachmentFileValidation.isHtml(reactContext, manager, downloadId)) return false
      // Do not delete a user-owned completed file merely because its provider
      // cannot be read right now. Only a successful inspection can invalidate it.
      val descriptor = reactContext.contentResolver.openAssetFileDescriptor(uri, "r")
        ?: throw IOException("The downloaded file provider is unavailable")
      return descriptor.use {
        val actualBytes = it.length
        if (actualBytes < 0L) throw IOException("The downloaded file length is unavailable")
        actualBytes > 0L && (expectedBytes <= 0L || actualBytes == expectedBytes)
      }
    } catch (error: FileNotFoundException) {
      // ContentResolver also uses this exception for provider failures. Confirm
      // actual absence before retiring the completed job and allowing a retry.
      if (downloadedFileIsMissing(manager, downloadId)) return false
      throw error
    }
  }

  private fun downloadedFileIsMissing(manager: DownloadManager, downloadId: Long): Boolean {
    return try {
      val localUri = manager.query(DownloadManager.Query().setFilterById(downloadId))?.use { cursor ->
        if (!cursor.moveToFirst()) return@use null
        val column = cursor.getColumnIndex(DownloadManager.COLUMN_LOCAL_URI)
        if (column >= 0) cursor.getString(column)?.toUri() else null
      }
      val path = localUri?.takeIf { it.scheme == "file" }?.path ?: return false
      try {
        Os.stat(path)
        false
      } catch (error: ErrnoException) {
        error.errno == OsConstants.ENOENT
      }
    } catch (_: Exception) {
      false
    }
  }

  private fun downloadResult(downloadId: Long, status: String, existing: Boolean) =
    Arguments.createMap().apply {
      putDouble("id", downloadId.toDouble())
      putString("status", status)
      putBoolean("existing", existing)
    }

  private fun hasDownloadSpace(expectedBytes: Long): Boolean {
    return try {
      val downloads = Environment.getExternalStoragePublicDirectory(Environment.DIRECTORY_DOWNLOADS)
      if (!downloads.exists()) downloads.mkdirs()
      val available = StatFs(downloads.absolutePath).availableBytes
      val reserve = 32L * 1024L * 1024L
      expectedBytes <= (available - reserve).coerceAtLeast(0L)
    } catch (_: Exception) {
      true
    }
  }

  private fun sha256(value: String): String = MessageDigest
    .getInstance("SHA-256")
    .digest(value.toByteArray(Charsets.UTF_8))
    .joinToString("") { "%02x".format(it) }

  private fun sanitizeFileName(value: String, stableKey: String): String {
    val normalized = java.text.Normalizer.normalize(value, java.text.Normalizer.Form.NFC)
    val cleaned = normalized
      .substringAfterLast('/')
      .substringAfterLast('\\')
      .replace(Regex("[^\\p{L}\\p{N}\\p{M}._\\-]"), "-")
      .trim('.', '-', ' ')
    val fallback = cleaned.ifBlank { "rokn-attachment" }
    val extension = fallback.substringAfterLast('.', "").takeIf {
      it.length in 1..8 && it.all { character -> character.isLetterOrDigit() }
    }
    val stem = if (extension == null) fallback else fallback.dropLast(extension.length + 1)
    val safeStem = stem.codePoints().limit(90).toArray()
      .let { String(it, 0, it.size) }
      .trim('.', '-', ' ')
      .ifBlank { "rokn-attachment" }
    val suffix = sha256(stableKey).take(8)
    return "$safeStem-$suffix${extension?.let { ".$it" }.orEmpty()}"
  }
}
