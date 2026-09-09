package android.os

import java.io.File

object Build
object Environment {
  const val DIRECTORY_DOWNLOADS = "Download"
  lateinit var downloads: File
  fun getExternalStoragePublicDirectory(type: String): File = downloads
}
class StatFs(path: String) { val availableBytes: Long = File(path).usableSpace }
