package com.rokn.media

import java.io.IOException
import okhttp3.ResponseBody
import okhttp3.ResponseBody.Companion.toResponseBody
import okio.Buffer
import org.junit.Assert.assertEquals
import org.junit.Test

class BoundedImageBodyTest {
  @Test fun acceptsAnImageExactlyAtTheLimit() {
    BoundedImageBody("12345".toResponseBody(), 5).use { assertEquals("12345", it.string()) }
  }

  @Test(expected = IOException::class)
  fun refusesOversizedBodiesRegardlessOfReportedLength() {
    val untrusted = object : ResponseBody() {
      private val bytes = Buffer().writeUtf8("123456")
      override fun contentType() = null
      override fun contentLength() = -1L
      override fun source() = bytes
    }
    BoundedImageBody(untrusted, 5).use { it.bytes() }
  }
}
