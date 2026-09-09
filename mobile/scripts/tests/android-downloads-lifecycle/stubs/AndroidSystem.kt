package android.system

import java.net.URI
import java.nio.file.Files
import java.nio.file.NoSuchFileException
import java.nio.file.Paths
import java.nio.file.attribute.BasicFileAttributes

object OsConstants { const val ENOENT = 2; const val EACCES = 13; const val EIO = 5 }
class ErrnoException(functionName: String, val errno: Int) : Exception("$functionName errno $errno")
object Os {
  var forcedErrno: Int? = null
  fun stat(path: String) {
    forcedErrno?.let { throw ErrnoException("stat", it) }
    // Translate the host's real filesystem result at the Android syscall seam.
    // The production caller alone decides which errno permits a fresh download.
    try { Files.readAttributes(Paths.get(URI("file", "", path, null)), BasicFileAttributes::class.java) }
    catch (_: NoSuchFileException) { throw ErrnoException("stat", OsConstants.ENOENT) }
  }
}
