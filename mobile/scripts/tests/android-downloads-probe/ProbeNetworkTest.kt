package com.rokn.downloads

import com.rokn.BuildConfig
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.InetAddress
import java.net.ServerSocket
import java.net.Socket
import java.util.Collections
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicLong

private data class Incoming(val method: String, val path: String, val headers: Map<String, String>)

/** Real loopback HTTP. Only Android's clock, BuildConfig and RN result map are substituted. */
private class LocalHost(private val handle: (Socket, Incoming) -> Unit) : AutoCloseable {
  private val server = ServerSocket(0, 16, InetAddress.getByName("127.0.0.1"))
  private val workers = Executors.newCachedThreadPool { task -> Thread(task).apply { isDaemon = true } }
  private val sockets = Collections.synchronizedList(mutableListOf<Socket>())
  val requests = Collections.synchronizedList(mutableListOf<Incoming>())
  val url = "http://127.0.0.1:${server.localPort}"

  init {
    workers.submit {
      while (!server.isClosed) {
        val socket = try { server.accept() } catch (_: Exception) { break }
        sockets.add(socket)
        workers.submit {
          socket.use {
            try {
              socket.soTimeout = 15_000
              socket.sendBufferSize = 1024
              val reader = BufferedReader(InputStreamReader(socket.getInputStream(), Charsets.US_ASCII))
              val first = reader.readLine()?.split(' ') ?: return@use
              val headers = linkedMapOf<String, String>()
              while (true) {
                val line = reader.readLine() ?: break
                if (line.isEmpty()) break
                headers[line.substringBefore(':').lowercase()] = line.substringAfter(':').trim()
              }
              val request = Incoming(first[0], first[1], headers)
              requests.add(request)
              handle(socket, request)
            } catch (_: Exception) {
              // Cancellation/reset is expected for a metadata-only request.
            }
          }
        }
      }
    }
  }

  override fun close() {
    server.close()
    synchronized(sockets) { sockets.forEach { runCatching { it.close() } } }
    workers.shutdownNow()
    check(workers.awaitTermination(3, TimeUnit.SECONDS)) { "Local HTTP workers did not retire" }
  }
}

private fun Socket.headers(status: Int, fields: Map<String, String> = emptyMap()) {
  val values = linkedMapOf("Content-Length" to "0", "Connection" to "close") + fields
  val text = "HTTP/1.1 $status Test\r\n" + values.entries.joinToString("") { "${it.key}: ${it.value}\r\n" } + "\r\n"
  getOutputStream().write(text.toByteArray(Charsets.US_ASCII))
  getOutputStream().flush()
}

fun main() {
  val failures = mutableListOf<String>()
  fun scenario(name: String, body: () -> Unit) {
    try { body(); println("PASS $name") }
    catch (error: Throwable) { failures.add(name); println("FAIL $name: ${error.message}") }
  }

  scenario("HEAD metadata preserves a large length without downloading a body") {
    LocalHost { socket, _ ->
      socket.headers(200, mapOf("Content-Type" to "application/pdf", "Content-Length" to "3221225472",
        "Content-Disposition" to "attachment; filename=course.pdf"))
    }.use { host ->
      val result = AttachmentMetadataProbe.inspect("${host.url}/large.pdf").toHashMap()
      check(result["contentLength"] == 3221225472.0)
      check(result["contentType"] == "application/pdf")
      check(result["contentDisposition"] == "attachment; filename=course.pdf")
      check(result["statusCode"] == 200 && result["url"] == "${host.url}/large.pdf")
      check(host.requests.single().method == "HEAD")
    }
  }

  scenario("HEAD fallback uses a bounded Range and retains the full resource length") {
    LocalHost { socket, request ->
      if (request.method == "HEAD") socket.headers(405)
      else {
        socket.headers(206, mapOf("Content-Type" to "application/zip", "Content-Length" to "512",
          "Content-Range" to "bytes 0-511/3221225472"))
        socket.getOutputStream().write(ByteArray(512))
      }
    }.use { host ->
      val result = AttachmentMetadataProbe.inspect("${host.url}/archive").toHashMap()
      check(result["contentLength"] == 3221225472.0 && result["statusCode"] == 206)
      check(host.requests.map { it.method } == listOf("HEAD", "GET"))
      check(host.requests.last().headers["range"] == "bytes=0-511")
    }
  }

  scenario("redirects do not forward cookies or invent authentication") {
    LocalHost { socket, request ->
      if (request.path == "/start") socket.headers(302, mapOf("Location" to "/file", "Set-Cookie" to "probe=secret"))
      else socket.headers(200, mapOf("Content-Type" to "application/pdf", "Content-Length" to "50"))
    }.use { host ->
      val result = AttachmentMetadataProbe.inspect("${host.url}/start").toHashMap()
      check(result["url"] == "${host.url}/file")
      check(host.requests.size == 2)
      check(host.requests.all { it.headers["cookie"] == null && it.headers["authorization"] == null })
    }
  }

  scenario("redirect cycles stop at the production hop limit") {
    LocalHost { socket, _ -> socket.headers(302, mapOf("Location" to "/again")) }.use { host ->
      val error = runCatching { AttachmentMetadataProbe.inspect("${host.url}/again") }.exceptionOrNull()
      check(error?.message == "DOWNLOAD_REDIRECT_LIMIT")
      check(host.requests.size == 6)
    }
  }

  scenario("release and credential URL guards run before any request") {
    LocalHost { socket, _ -> socket.headers(200) }.use { host ->
      try {
        BuildConfig.DEBUG = false
        check(runCatching { AttachmentMetadataProbe.inspect(host.url) }.exceptionOrNull()?.message == "INVALID_DOWNLOAD_URL")
      } finally { BuildConfig.DEBUG = true }
      val withCredentials = host.url.replace("http://", "http://name:password@")
      check(runCatching { AttachmentMetadataProbe.inspect(withCredentials) }.exceptionOrNull()?.message == "INVALID_DOWNLOAD_URL")
      check(host.requests.isEmpty())
    }
  }

  scenario("a host ignoring Range cannot drain its large body during response cleanup") {
    val sent = AtomicLong()
    val finished = CountDownLatch(1)
    LocalHost { socket, request ->
      if (request.method == "HEAD") socket.headers(405)
      else {
        try {
          socket.headers(200, mapOf("Content-Type" to "application/zip", "Content-Length" to "268435456"))
          val block = ByteArray(8192)
          repeat(32768) {
            socket.getOutputStream().write(block)
            sent.addAndGet(block.size.toLong())
          }
        } finally { finished.countDown() }
      }
    }.use { host ->
      val result = AttachmentMetadataProbe.inspect("${host.url}/ignores-range").toHashMap()
      check(result["contentLength"] == 268435456.0)
      check(finished.await(3, TimeUnit.SECONDS)) { "Metadata request left a body stream running" }
      println("  response bytes accepted before close: ${sent.get()}")
      // Allow socket/header buffering, not an exact byte guarantee at TCP level.
      check(sent.get() <= 262144) { "Headers-only probe consumed ${sent.get()} response bytes" }
    }
  }

  scenario("a host that never sends headers respects the real eight second deadline") {
    LocalHost { _, _ -> Thread.sleep(20_000) }.use { host ->
      val start = System.nanoTime()
      val error = runCatching { AttachmentMetadataProbe.inspect("${host.url}/silent") }.exceptionOrNull()
      val elapsed = TimeUnit.NANOSECONDS.toMillis(System.nanoTime() - start)
      check(error != null && elapsed in 7000..11000) { "Deadline result $error after ${elapsed}ms" }
    }
  }
  check(failures.isEmpty()) { "${failures.size} probe scenarios failed: ${failures.joinToString()}" }
}
