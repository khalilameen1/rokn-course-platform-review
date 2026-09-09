package com.rokn.downloads

import android.os.SystemClock
import com.facebook.react.bridge.Arguments
import com.facebook.react.bridge.WritableMap
import com.rokn.BuildConfig
import okhttp3.Authenticator
import okhttp3.CookieJar
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit

/** Independent of the authenticated React Native HTTP client and its cookie jar. */
internal object AttachmentMetadataProbe {
  private val client = OkHttpClient.Builder()
    .cookieJar(CookieJar.NO_COOKIES)
    .authenticator(Authenticator.NONE)
    .proxyAuthenticator(Authenticator.NONE)
    .followRedirects(false)
    .followSslRedirects(false)
    .retryOnConnectionFailure(false)
    .build()

  fun inspect(input: String): WritableMap {
    var url = input.toHttpUrlOrNull() ?: error("INVALID_DOWNLOAD_URL")
    val deadline = SystemClock.elapsedRealtime() + 8_000L
    var method = "HEAD"
    var redirects = 0
    repeat(7) {
      require((url.isHttps || BuildConfig.DEBUG) && url.username.isEmpty() && url.password.isEmpty()) {
        "INVALID_DOWNLOAD_URL"
      }
      val remaining = deadline - SystemClock.elapsedRealtime()
      check(remaining > 0) { "DOWNLOAD_METADATA_TIMEOUT" }
      val request = Request.Builder().url(url)
        .header("Accept-Encoding", "identity")
        .method(method, null)
        .apply { if (method == "GET") header("Range", "bytes=0-511") }
        .build()
      val call = client.newBuilder().callTimeout(remaining, TimeUnit.MILLISECONDS).build()
        .newCall(request)
      call.execute().use { response ->
        // Closing alone may drain a large response to reuse the connection.
        // We need only headers, so stop the transfer before closing its body.
        call.cancel()
        if (response.code in listOf(301, 302, 303, 307, 308)) {
          check(++redirects <= 5) { "DOWNLOAD_REDIRECT_LIMIT" }
          url = url.resolve(response.header("Location").orEmpty())
            ?: error("INVALID_DOWNLOAD_REDIRECT")
        } else if (method == "HEAD" && response.code in listOf(401, 403, 405, 501)) {
          method = "GET"
        } else {
          return Arguments.createMap().apply {
            putString("url", url.toString())
            putInt("statusCode", response.code)
            putString("contentType", response.header("Content-Type"))
            putString("contentDisposition", response.header("Content-Disposition"))
            val total = if (response.code == 206) {
              response.header("Content-Range")?.substringAfterLast('/')?.toLongOrNull()
            } else response.header("Content-Length")?.toLongOrNull()
            if (total != null && total > 0L) putDouble("contentLength", total.toDouble())
          }
        }
      }
    }
    error("DOWNLOAD_REDIRECT_LIMIT")
  }
}
