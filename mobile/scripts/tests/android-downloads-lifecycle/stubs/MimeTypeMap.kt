package android.webkit

class MimeTypeMap {
  fun getMimeTypeFromExtension(extension: String): String? =
    if (extension == "pdf") "application/pdf" else null
  companion object {
    fun getSingleton(): MimeTypeMap = MimeTypeMap()
    fun getFileExtensionFromUrl(url: String): String = url.substringBefore('?').substringAfterLast('.', "")
  }
}
