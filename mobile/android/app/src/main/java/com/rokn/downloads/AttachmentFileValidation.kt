package com.rokn.downloads

import android.app.DownloadManager
import android.content.Context

internal object AttachmentFileValidation {
  fun isHtml(context: Context, manager: DownloadManager, id: Long): Boolean {
    val mime = manager.getMimeTypeForDownloadedFile(id).orEmpty().substringBefore(';').trim().lowercase()
    if (mime == "text/html" || mime == "application/xhtml+xml") return true
    val uri = manager.getUriForDownloadedFile(id) ?: return false
    return try {
      val prefix = context.contentResolver.openInputStream(uri)?.use { stream ->
        val bytes = ByteArray(512)
        val count = stream.read(bytes)
        if (count > 0) String(bytes, 0, count, Charsets.UTF_8) else ""
      }.orEmpty()
      Regex("^\\s*\\uFEFF?\\s*(<!doctype\\s+html|<html\\b|<head\\b|<body\\b)", RegexOption.IGNORE_CASE)
        .containsMatchIn(prefix)
    } catch (_: Exception) {
      false
    }
  }
}
