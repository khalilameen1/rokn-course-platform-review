package com.rokn.downloads

import android.app.DownloadManager
import android.content.ContentResolver
import android.content.Intent
import android.content.SharedPreferences
import android.os.Environment
import android.system.Os
import android.system.OsConstants
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.WritableMap
import java.io.Closeable
import java.io.File
import java.nio.file.Files

private class Result : Promise {
  var terminals = 0
  var value: Any? = null
  var code: String? = null
  override fun resolve(value: Any?) { terminals += 1; this.value = value }
  override fun reject(code: String, message: String, error: Throwable?) { terminals += 1; this.code = code }
  fun map(): Map<String, Any?> {
    check(terminals == 1 && code == null) { "Expected one resolution, got $terminals / $code" }
    return (value as WritableMap).toHashMap()
  }
  fun rejected(expected: String) { check(terminals == 1 && code == expected) { "Expected $expected once, got $terminals / $code / $value" } }
}

private class Fixture : Closeable {
  val directory = Files.createTempDirectory("rokn-download-lifecycle-").toFile()
  val manager = DownloadManager(directory)
  val preferences = SharedPreferences()
  val resolver = ContentResolver(manager)
  val context = ReactApplicationContext(manager, resolver, preferences)
  val module = RoknDownloadsModule(context)
  val pdf = "%PDF-1.4\nA valid completed document".toByteArray()
  init { Environment.downloads = directory; Os.forcedErrno = null }
  fun tap(key: String = "account-1:attachment-7:v1", bytes: Long = pdf.size.toLong(), external: Boolean = true): Result = Result().also {
    if (external) module.enqueueExternal("https://files.example.test/a.pdf", "Course file", "a.pdf", "application/pdf", key, bytes.toDouble(), it)
    else module.enqueue("https://files.example.test/a.pdf", "Course file", "a.pdf", "application/pdf", key, bytes.toDouble(), it)
  }
  fun complete(key: String = "account-1:attachment-7:v1"): Long {
    val id = (tap(key).map()["id"] as Double).toLong()
    manager.complete(id, pdf)
    return id
  }
  fun received(id: Long) = AttachmentDownloadReceiver().onReceive(context,
    Intent(DownloadManager.ACTION_DOWNLOAD_COMPLETE).putExtra(DownloadManager.EXTRA_DOWNLOAD_ID, id))
  fun tracked(id: Long): Boolean = preferences.all.any { (key, value) -> key.startsWith("download:") && value == id }
  override fun close() {
    val target = directory.canonicalFile
    check(target.parentFile == File(System.getProperty("java.io.tmpdir")).canonicalFile)
    check(target.name.startsWith("rokn-download-lifecycle-"))
    target.deleteRecursively()
  }
}

fun main() {
  val tests = linkedMapOf<String, () -> Unit>(
    "temporary descriptor failure keeps completed file and retry identity" to {
      Fixture().use { f ->
        val id = f.complete()
        val file = checkNotNull(f.manager.entries[id]).file
        f.resolver.descriptorFailure = true
        val retry = f.tap()
        check(file.exists() && f.manager.removed.isEmpty() && f.tracked(id)) { "Transient descriptor failure deleted a completed public file or its receipt" }
        retry.rejected("DOWNLOAD_FAILED")
        check(f.manager.enqueueCount == 1 && f.context.activities.isEmpty())
        f.resolver.descriptorFailure = false
        check(f.tap().map()["status"] == "opened")
        check(f.manager.enqueueCount == 1)
      }
    },
    "temporary prefix failure neither opens nor deletes completed file" to {
      Fixture().use { f ->
        val id = f.complete()
        f.resolver.inputFailure = true
        val retry = f.tap()
        check(f.context.activities.isEmpty()) { "A failed prefix inspection opened an unvalidated completed file" }
        check(f.manager.removed.isEmpty() && f.tracked(id))
        retry.rejected("DOWNLOAD_FAILED")
        f.resolver.inputFailure = false
        check(f.tap().map()["status"] == "opened")
      }
    },
    "completion receiver retains validation marker while provider is unavailable" to {
      Fixture().use { f ->
        val id = f.complete()
        f.resolver.inputFailure = true
        f.received(id)
        check(f.preferences.getBoolean("external:$id", false)) { "Receiver retired the validation marker without reading the file" }
        check(f.manager.removed.isEmpty() && f.tracked(id))
        f.resolver.inputFailure = false
        f.received(id)
        check(!f.preferences.getBoolean("external:$id", false))
        check(f.tap().map()["status"] == "opened")
      }
    },
    "valid completion reopens once without another transfer" to {
      Fixture().use { f ->
        val id = f.complete()
        f.received(id)
        val result = f.tap().map()
        check(result["id"] == id.toDouble() && result["status"] == "opened" && result["existing"] == true)
        check(f.manager.enqueueCount == 1 && f.context.activities.size == 1)
      }
    },
    "nullable provider reads retain file and receipt until the provider returns" to {
      listOf("input", "descriptor").forEach { kind ->
        Fixture().use { f ->
          val id = f.complete()
          f.resolver.nullInput = kind == "input"
          f.resolver.nullDescriptor = kind == "descriptor"
          f.tap().rejected("DOWNLOAD_FAILED")
          check(f.manager.removed.isEmpty() && f.tracked(id) && f.context.activities.isEmpty())
          if (kind == "input") {
            f.received(id)
            check(f.preferences.getBoolean("external:$id", false))
          }
          f.resolver.nullInput = false
          f.resolver.nullDescriptor = false
          check(f.tap().map()["status"] == "opened")
        }
      }
    },
    "a temporarily unavailable completed URI does not delete its file or finish validation" to {
      Fixture().use { f ->
        val id = f.complete()
        f.manager.unavailableUri = true // getUriForDownloadedFile returns null if its provider query returns null.
        f.received(id)
        check(f.preferences.getBoolean("external:$id", false)) { "Receiver retired validation without a usable URI" }
        val retry = f.tap()
        check(f.tracked(id) && f.manager.removed.isEmpty()) { "Unavailable URI deleted the completed file" }
        retry.rejected("DOWNLOAD_FAILED")
        f.manager.unavailableUri = false
        check(f.tap().map()["status"] == "opened" && f.manager.enqueueCount == 1)
      }
    },
    "deferred HTML validation still removes a login page when the provider recovers" to {
      Fixture().use { f ->
        val id = f.complete()
        f.manager.complete(id, "<html>Login</html>".toByteArray())
        f.resolver.inputFailure = true
        f.received(id)
        check(f.preferences.getBoolean("external:$id", false) && f.manager.removed.isEmpty())
        f.resolver.inputFailure = false
        f.received(id)
        check(f.manager.removed == listOf(id) && !f.preferences.getBoolean("external:$id", false))
        f.tap().rejected("DOWNLOAD_RETRY_REQUIRES_REFRESH")
        check(f.context.activities.isEmpty() && f.manager.enqueueCount == 1)
      }
    },
    "receiver removes proven HTML but next tap requires fresh link before transfer" to {
      Fixture().use { f ->
        val id = f.complete()
        f.manager.complete(id, "<!doctype html><html>Login</html>".toByteArray())
        f.received(id)
        check(f.manager.removed == listOf(id) && f.tracked(id))
        f.tap().rejected("DOWNLOAD_RETRY_REQUIRES_REFRESH")
        check(f.manager.enqueueCount == 1 && f.context.activities.isEmpty())
        check(f.tap().map()["status"] == "started")
      }
    },
    "known size mismatch does not open or automatically reenqueue" to {
      Fixture().use { f ->
        val id = f.complete()
        f.tap(bytes = f.pdf.size + 1L).rejected("DOWNLOAD_RETRY_REQUIRES_REFRESH")
        check(f.manager.removed == listOf(id) && f.manager.enqueueCount == 1 && f.context.activities.isEmpty())
      }
    },
    "unknown provider length is not proof that a completed file is invalid" to {
      Fixture().use { f ->
        val id = f.complete()
        f.resolver.descriptorLength = -1L // Android AssetFileDescriptor.UNKNOWN_LENGTH.
        val retry = f.tap()
        check(f.manager.removed.isEmpty() && f.tracked(id)) { "UNKNOWN_LENGTH deleted a completed file" }
        retry.rejected("DOWNLOAD_FAILED")
        check(f.context.activities.isEmpty() && f.manager.enqueueCount == 1)
        f.resolver.descriptorLength = null
        check(f.tap().map()["status"] == "opened")
      }
    },
    "a proven empty file remains invalid" to {
      Fixture().use { f ->
        val id = f.complete()
        f.manager.complete(id, byteArrayOf())
        f.tap(bytes = 0).rejected("DOWNLOAD_RETRY_REQUIRES_REFRESH")
        check(f.manager.removed == listOf(id) && f.context.activities.isEmpty() && f.manager.enqueueCount == 1)
      }
    },
    "a genuinely deleted completed file still requires fresh metadata before another transfer" to {
      Fixture().use { f ->
        val id = f.complete()
        check(checkNotNull(f.manager.entries[id]).file.delete())
        f.tap().rejected("DOWNLOAD_RETRY_REQUIRES_REFRESH")
        check(!f.tracked(id) && f.manager.enqueueCount == 1 && f.context.activities.isEmpty())
        check(f.tap().map()["status"] == "started" && f.manager.enqueueCount == 2)
      }
    },
    "a provider FileNotFoundException cannot retire a file that still exists" to {
      Fixture().use { f ->
        val id = f.complete()
        f.resolver.fileNotFoundFailure = true
        f.tap().rejected("DOWNLOAD_FAILED")
        check(f.tracked(id) && f.manager.removed.isEmpty() && f.context.activities.isEmpty())
        f.resolver.fileNotFoundFailure = false
        check(f.tap().map()["status"] == "opened" && f.manager.enqueueCount == 1)
      }
    },
    "inaccessible or unknown local paths are not authoritative missing files" to {
      listOf("denied", "io", "no-uri", "content-uri").forEach { kind ->
        Fixture().use { f ->
          val id = f.complete()
          f.resolver.fileNotFoundFailure = true
          when (kind) {
            "denied" -> Os.forcedErrno = OsConstants.EACCES
            "io" -> Os.forcedErrno = OsConstants.EIO
            "no-uri" -> checkNotNull(f.manager.entries[id]).localUri = null
            "content-uri" -> checkNotNull(f.manager.entries[id]).localUri = "content://downloads/$id"
          }
          f.tap().rejected("DOWNLOAD_FAILED")
          check(f.tracked(id) && f.manager.removed.isEmpty() && f.manager.enqueueCount == 1 && f.context.activities.isEmpty())
        }
      }
    },
    "pending running and paused downloads retain their durable job" to {
      listOf(DownloadManager.STATUS_PENDING, DownloadManager.STATUS_RUNNING, DownloadManager.STATUS_PAUSED).forEach { status ->
        Fixture().use { f ->
          val id = (f.tap().map()["id"] as Double).toLong()
          checkNotNull(f.manager.entries[id]).status = status
          check(f.tap().map()["status"] == "running")
          f.received(id)
          check(f.tracked(id) && f.preferences.getBoolean("external:$id", false))
          check(f.manager.enqueueCount == 1 && f.manager.removed.isEmpty())
        }
      }
    },
    "account retirement cancels tracked active jobs not completed public files or unrelated jobs" to {
      Fixture().use { f ->
        val completed = f.complete()
        val file = checkNotNull(f.manager.entries[completed]).file
        val active = (f.tap(key = "account-1:attachment-8:v1").map()["id"] as Double).toLong()
        val unrelated = f.manager.enqueue(DownloadManager.Request(android.net.Uri.parse("https://example.test/other")))
        val result = Result()
        f.module.cancelAllActive(result)
        check(result.terminals == 1 && result.value == true)
        check(f.manager.removed == listOf(active) && file.exists() && f.manager.entries.containsKey(unrelated))
        check(f.preferences.all.isEmpty())
        f.received(completed)
        check(file.exists() && f.manager.removed == listOf(active))
      }
    },
    "an unavailable status query cannot forget a running download and start another" to {
      Fixture().use { f ->
        val id = (f.tap().map()["id"] as Double).toLong()
        f.manager.unavailableQuery = true
        val result = f.tap()
        check(f.tracked(id)) { "A null status cursor forgot a durable running job" }
        result.rejected("DOWNLOAD_FAILED")
        check(f.manager.enqueueCount == 1 && f.manager.removed.isEmpty())
        f.manager.unavailableQuery = false
        val resumed = f.tap().map()
        check(resumed["id"] == id.toDouble() && resumed["status"] == "running" && f.manager.enqueueCount == 1)
      }
    },
    "account retirement retains unreadable job identities for a later cancellation attempt" to {
      Fixture().use { f ->
        val id = (f.tap().map()["id"] as Double).toLong()
        f.manager.unavailableQuery = true
        val result = Result()
        f.module.cancelAllActive(result)
        check(f.tracked(id)) { "Retirement acknowledged success and erased a still-running job it could not inspect" }
        result.rejected("DOWNLOAD_CANCEL_FAILED")
        check(f.manager.entries[id]?.status == DownloadManager.STATUS_RUNNING && f.manager.removed.isEmpty())
        f.manager.unavailableQuery = false
        val retried = Result()
        f.module.cancelAllActive(retried)
        check(retried.terminals == 1 && retried.value == true && f.manager.removed == listOf(id))
        check(f.preferences.all.isEmpty())
      }
    },
    "individual cancellation reports unavailable status rather than a completed no-op" to {
      Fixture().use { f ->
        val id = (f.tap().map()["id"] as Double).toLong()
        f.manager.unavailableQuery = true
        val result = Result()
        f.module.cancelIfActive(id.toDouble(), result)
        result.rejected("DOWNLOAD_CANCEL_FAILED")
        check(f.tracked(id) && f.manager.removed.isEmpty())
        f.manager.unavailableQuery = false
        val retried = Result()
        f.module.cancelIfActive(id.toDouble(), retried)
        check(retried.terminals == 1 && retried.value == true && f.manager.removed == listOf(id))
      }
    },
    "partial account retirement can finish later without losing the unreadable job" to {
      Fixture().use { f ->
        val first = (f.tap().map()["id"] as Double).toLong()
        val second = (f.tap(key = "account-1:attachment-8:v1").map()["id"] as Double).toLong()
        f.manager.unavailableQueryIds.add(second)
        val result = Result()
        f.module.cancelAllActive(result)
        result.rejected("DOWNLOAD_CANCEL_FAILED")
        check(f.manager.removed == listOf(first) && f.tracked(second))
        f.manager.unavailableQueryIds.clear()
        val retried = Result()
        f.module.cancelAllActive(retried)
        check(retried.terminals == 1 && retried.value == true)
        check(f.manager.removed == listOf(first, second) && f.preferences.all.isEmpty())
      }
    },
    "a real empty status cursor or known failed job still requires fresh metadata" to {
      listOf("removed", "failed").forEach { kind ->
        Fixture().use { f ->
          val id = (f.tap().map()["id"] as Double).toLong()
          if (kind == "removed") f.manager.remove(id)
          else checkNotNull(f.manager.entries[id]).status = DownloadManager.STATUS_FAILED
          f.tap().rejected("DOWNLOAD_RETRY_REQUIRES_REFRESH")
          check(!f.tracked(id) && f.manager.enqueueCount == 1)
          check(f.tap().map()["status"] == "started" && f.manager.enqueueCount == 2)
        }
      }
    },
    "late user cancellation leaves completed public file available" to {
      Fixture().use { f ->
        val id = f.complete()
        val result = Result()
        f.module.cancelIfActive(id.toDouble(), result)
        check(result.terminals == 1 && result.value == false && f.manager.removed.isEmpty() && f.tracked(id))
        check(f.tap().map()["status"] == "opened")
      }
    },
    "active cancellation retires exactly its job and permits an explicit later download" to {
      Fixture().use { f ->
        val id = (f.tap().map()["id"] as Double).toLong()
        val result = Result()
        f.module.cancelIfActive(id.toDouble(), result)
        check(result.terminals == 1 && result.value == true && f.manager.removed == listOf(id))
        check(!f.tracked(id) && !f.preferences.getBoolean("external:$id", false))
        f.received(id)
        check(f.tap().map()["status"] == "started" && f.manager.enqueueCount == 2)
      }
    },
  )
  var failures = 0
  tests.forEach { (name, body) ->
    try { body(); println("PASS $name") }
    catch (error: Throwable) { failures += 1; System.err.println("FAIL $name: ${error.message}") }
  }
  println("Android download lifecycle: ${tests.size - failures} passed, $failures failed")
  check(failures == 0) { "Lifecycle regressions failed" }
}
