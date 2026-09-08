package com.rokn.downloads

import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Also runs after process death: a changed host response must not remain a PDF-shaped login page. */
class AttachmentDownloadReceiver : BroadcastReceiver() {
  override fun onReceive(context: Context, intent: Intent) {
    if (intent.action != DownloadManager.ACTION_DOWNLOAD_COMPLETE) return
    val id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1L)
    val preferences = context.getSharedPreferences("rokn_downloads", Context.MODE_PRIVATE)
    if (id <= 0L || !preferences.getBoolean("external:$id", false)) return
    if (preferences.all.values.none { value -> (value as? Long) == id }) return
    try {
      val manager = context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager
      val status = manager.query(DownloadManager.Query().setFilterById(id))?.use { cursor ->
        if (!cursor.moveToFirst()) return@use null
        val column = cursor.getColumnIndex(DownloadManager.COLUMN_STATUS)
        if (column >= 0) cursor.getInt(column) else null
      }
      if (status != DownloadManager.STATUS_SUCCESSFUL) return
      if (AttachmentFileValidation.isHtml(context, manager, id)) {
        manager.remove(id)
        // Keep its stable-key record: the next tap follows the bounded refresh/retry path.
      }
      preferences.edit().remove("external:$id").apply()
    } catch (_: Exception) {
      // The next tap validates again if the system provider is temporarily unavailable.
    }
  }
}
