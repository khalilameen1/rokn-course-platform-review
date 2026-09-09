package com.facebook.react.bridge

/** Only the JNI-backed map seam is replaced; probe/HTTP behavior stays real. */
class WritableMap {
  private val values = linkedMapOf<String, Any?>()

  fun putString(key: String, value: String?) { values[key] = value }
  fun putInt(key: String, value: Int) { values[key] = value }
  fun putDouble(key: String, value: Double) { values[key] = value }
  fun putBoolean(key: String, value: Boolean) { values[key] = value }
  fun hasKey(key: String): Boolean = values.containsKey(key)
  fun isNull(key: String): Boolean = values[key] == null
  fun getString(key: String): String? = values[key] as String?
  fun getInt(key: String): Int = (values[key] as Number).toInt()
  fun getDouble(key: String): Double = (values[key] as Number).toDouble()
  operator fun get(key: String): Any? = values[key]
  fun toHashMap(): HashMap<String, Any?> = HashMap(values)
}

object Arguments {
  @JvmStatic fun createMap(): WritableMap = WritableMap()
}
