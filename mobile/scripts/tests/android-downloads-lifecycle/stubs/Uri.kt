package android.net

class Uri private constructor(private val raw: String) {
  private val parsed = java.net.URI(raw)
  val scheme: String? get() = parsed.scheme
  val host: String? get() = parsed.host
  val userInfo: String? get() = parsed.userInfo
  val path: String get() = parsed.path
  override fun toString(): String = raw
  companion object { fun parse(raw: String): Uri = Uri(raw) }
}
