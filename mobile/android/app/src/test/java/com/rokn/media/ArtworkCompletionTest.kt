package com.rokn.media

import org.junit.Assert.assertEquals
import org.junit.Test
import java.util.concurrent.CountDownLatch
import java.util.concurrent.atomic.AtomicInteger

class ArtworkCompletionTest {
  @Test fun successAndDeadlinePublishOnlyOnce() {
    val delivered = mutableListOf<String?>()
    var released = 0
    val completion = ArtworkCompletion<String>({ delivered.add(it) }, { released++ })
    completion.complete("image")
    completion.complete(null)
    completion.complete("late image")
    assertEquals(listOf("image"), delivered)
    assertEquals(1, released)
  }

  @Test fun timeoutCannotBeReplacedByALateImage() {
    val delivered = mutableListOf<String?>()
    val completion = ArtworkCompletion<String>({ delivered.add(it) }, {})
    completion.complete(null)
    completion.complete("late")
    assertEquals(listOf<String?>(null), delivered)
  }

  @Test fun publisherFailureStillReleasesTheRequest() {
    var released = 0
    val completion = ArtworkCompletion<String>({ throw IllegalStateException("notify failed") }, { released++ })
    runCatching { completion.complete("image") }
    completion.complete(null)
    assertEquals(1, released)
  }

  @Test fun concurrentCallbacksCannotDuplicateNotificationsOrCleanup() {
    val published = AtomicInteger()
    val released = AtomicInteger()
    val start = CountDownLatch(1)
    val completion = ArtworkCompletion<String>({ published.incrementAndGet() }, { released.incrementAndGet() })
    val workers = (1..16).map { Thread { start.await(); completion.complete("image") }.apply { start() } }
    start.countDown()
    workers.forEach { it.join() }
    assertEquals(1, published.get())
    assertEquals(1, released.get())
  }
}
