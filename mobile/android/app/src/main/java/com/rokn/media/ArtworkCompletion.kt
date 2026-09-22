package com.rokn.media

import java.util.concurrent.atomic.AtomicBoolean

/** A decoded result, failure and deadline may race; publish and release once. */
internal class ArtworkCompletion<T>(
  private val publish: (T?) -> Unit,
  private val release: () -> Unit,
) {
  private val completed = AtomicBoolean(false)

  fun complete(value: T?) {
    if (!completed.compareAndSet(false, true)) return
    try {
      publish(value)
    } finally {
      release()
    }
  }
}
