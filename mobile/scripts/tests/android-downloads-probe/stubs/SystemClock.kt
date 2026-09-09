package android.os

/** JVM replacement for Android's monotonic clock; no simulated network time. */
object SystemClock {
  @JvmStatic fun elapsedRealtime(): Long = System.nanoTime() / 1_000_000L
}
