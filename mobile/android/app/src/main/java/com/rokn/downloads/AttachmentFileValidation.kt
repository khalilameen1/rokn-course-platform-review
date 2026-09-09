package com.rokn.downloads

import android.app.DownloadManager
import android.content.Context
import java.io.IOException

internal object AttachmentFileValidation {
  fun isHtml(context: Context, manager: DownloadManager, id: Long): Boolean {
    val mime = manager.getMimeTypeForDownloadedFile(id).orEmpty().substringBefore(';').trim().lowercase()
    if (mime == "text/html" || mime == "application/xhtml+xml") return true
    val uri = manager.getUriForDownloadedFile(id)
      ?: throw IOException("The downloaded file URI is unavailable")
    // A provider read failure is not a negative HTML check. Let the caller keep
    // the completed file and its validation receipt for a later attempt.
    val stream = context.contentResolver.openInputStream(uri)
      ?: throw IOException("The downloaded file provider is unavailable")
    val prefix = stream.use {
      val bytes = ByteArray(512)
      val count = it.read(bytes)
      if (count > 0) String(bytes, 0, count, Charsets.UTF_8) else ""
    }
    return Regex("^\\s*\\uFEFF?\\s*(<!doctype\\s+html|<html\\b|<head\\b|<body\\b)", RegexOption.IGNORE_CASE)
      .containsMatchIn(prefix)
  }
}
