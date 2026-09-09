package android.app

import android.net.Uri
import java.io.Closeable
import java.io.File

/** System ledger/file operations only. All validation and retirement decisions run in production Kotlin. */
class DownloadManager(private val directory: File) {
  data class Entry(var status: Int, var mime: String, val file: File) {
    var localUri: String? = file.toURI().toString()
  }
  val entries = linkedMapOf<Long, Entry>()
  val removed = mutableListOf<Long>()
  var enqueueCount = 0
  var unavailableUri = false
  private var nextId = 1L

  class Request(val uri: Uri) {
    var mime = "application/octet-stream"
    var filename = "download"
    fun setTitle(value: String) = this
    fun setDescription(value: String) = this
    fun setNotificationVisibility(value: Int) = this
    fun setAllowedOverMetered(value: Boolean) = this
    fun setAllowedOverRoaming(value: Boolean) = this
    fun setMimeType(value: String) = apply { mime = value }
    fun setDestinationInExternalPublicDir(type: String, value: String) = apply { filename = value }
    companion object { const val VISIBILITY_VISIBLE_NOTIFY_COMPLETED = 1 }
  }
  class Query {
    var id = -1L
    fun setFilterById(value: Long) = apply { id = value }
  }
  class Cursor(private val entry: Entry?) : Closeable {
    fun moveToFirst(): Boolean = entry != null
    fun getColumnIndex(name: String): Int = when (name) { COLUMN_STATUS -> 0; COLUMN_LOCAL_URI -> 1; else -> -1 }
    fun getInt(index: Int): Int = checkNotNull(entry).status
    fun getString(index: Int): String? = checkNotNull(entry).localUri
    override fun close() {}
  }
  fun enqueue(request: Request): Long {
    enqueueCount += 1
    val id = nextId++
    entries[id] = Entry(STATUS_RUNNING, request.mime, File(directory, request.filename))
    return id
  }
  fun query(query: Query): Cursor? = Cursor(entries[query.id])
  fun getUriForDownloadedFile(id: Long): Uri? = if (unavailableUri) null else entries[id]
    ?.takeIf { it.status == STATUS_SUCCESSFUL }
    ?.let { Uri.parse("content://downloads/$id") }
  fun getMimeTypeForDownloadedFile(id: Long): String? = entries[id]?.mime
  fun file(uri: Uri): File = checkNotNull(entries[uri.path.substringAfterLast('/').toLong()]).file
  fun complete(id: Long, bytes: ByteArray, mime: String = "application/pdf") {
    checkNotNull(entries[id]).apply { file.writeBytes(bytes); status = STATUS_SUCCESSFUL; this.mime = mime }
  }
  fun remove(vararg ids: Long): Int {
    ids.forEach { id -> removed.add(id); entries.remove(id)?.file?.delete() }
    return ids.size
  }
  companion object {
    const val STATUS_PENDING = 1
    const val STATUS_RUNNING = 2
    const val STATUS_PAUSED = 4
    const val STATUS_SUCCESSFUL = 8
    const val STATUS_FAILED = 16
    const val COLUMN_STATUS = "status"
    const val COLUMN_LOCAL_URI = "local_uri"
    const val ACTION_DOWNLOAD_COMPLETE = "android.intent.action.DOWNLOAD_COMPLETE"
    const val EXTRA_DOWNLOAD_ID = "extra_download_id"
    const val ACTION_VIEW_DOWNLOADS = "android.intent.action.VIEW_DOWNLOADS"
  }
}
