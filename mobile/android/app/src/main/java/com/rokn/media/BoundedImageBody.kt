package com.rokn.media

import java.io.IOException
import okhttp3.ResponseBody
import okio.Buffer
import okio.ForwardingSource
import okio.buffer

/** Bound encoded bytes even when a server omits or understates Content-Length. */
internal class BoundedImageBody(
  private val delegate: ResponseBody,
  private val maxBytes: Long,
) : ResponseBody() {
  private val boundedSource by lazy {
    object : ForwardingSource(delegate.source()) {
      private var received = 0L
      override fun read(sink: Buffer, byteCount: Long): Long {
        val read = super.read(sink, minOf(byteCount, maxBytes - received + 1))
        if (read > 0) {
          received += read
          if (received > maxBytes) throw IOException("Notification artwork exceeds byte limit")
        }
        return read
      }
    }.buffer()
  }
  override fun contentType() = delegate.contentType()
  override fun contentLength() = delegate.contentLength()
  override fun source() = boundedSource
}
