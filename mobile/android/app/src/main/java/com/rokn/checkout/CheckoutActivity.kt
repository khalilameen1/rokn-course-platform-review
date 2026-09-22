package com.rokn.checkout

import android.app.Activity
import android.content.Intent
import android.graphics.Color
import android.graphics.Typeface
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.view.Gravity
import android.view.View
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.CookieManager
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.activity.SystemBarStyle
import androidx.activity.enableEdgeToEdge
import androidx.appcompat.app.AppCompatActivity
import androidx.core.net.toUri
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import com.rokn.BuildConfig

class CheckoutActivity : AppCompatActivity() {
  private lateinit var webView: WebView
  private lateinit var progress: ProgressBar
  private lateinit var errorMessage: TextView
  private lateinit var retryButton: TextView
  private var checkoutUrl: String = ""
  private var mainFrameFailed = false

  override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    enableEdgeToEdge(
      statusBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
      navigationBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
    )
    WindowInsetsControllerCompat(window, window.decorView).apply {
      isAppearanceLightStatusBars = false
      isAppearanceLightNavigationBars = false
    }
    setContentView(buildContent())
    onBackPressedDispatcher.addCallback(
      this,
      object : OnBackPressedCallback(true) {
        override fun handleOnBackPressed() {
          if (::webView.isInitialized && webView.canGoBack()) {
            webView.goBack()
          } else {
            finishCancelled()
          }
        }
      },
    )

    checkoutUrl = intent.getStringExtra(EXTRA_URL).orEmpty()
    if (isTrustedCheckoutEntry(checkoutUrl)) {
      webView.loadUrl(checkoutUrl)
    } else {
      showError("تعذر فتح صفحة الدفع الآمنة")
    }
  }

  private fun buildContent(): View {
    val root = LinearLayout(this).apply {
      orientation = LinearLayout.VERTICAL
      setBackgroundColor(BACKGROUND)
    }
    ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
      val safeInsets = insets.getInsets(
        WindowInsetsCompat.Type.systemBars() or
          WindowInsetsCompat.Type.displayCutout() or
          WindowInsetsCompat.Type.ime(),
      )
      view.setPadding(
        safeInsets.left,
        safeInsets.top,
        safeInsets.right,
        safeInsets.bottom,
      )
      insets
    }

    val toolbar = FrameLayout(this).apply {
      setPadding(dp(12), 0, dp(12), 0)
      setBackgroundColor(BACKGROUND)
    }
    toolbar.addView(
      TextView(this).apply {
        text = "دفع آمن"
        textSize = 17f
        setTextColor(Color.WHITE)
        gravity = Gravity.CENTER
      },
      FrameLayout.LayoutParams(
        FrameLayout.LayoutParams.MATCH_PARENT,
        dp(56),
      ),
    )
    toolbar.addView(
      TextView(this).apply {
        text = "×"
        textSize = 34f
        setTextColor(Color.WHITE)
        gravity = Gravity.CENTER
        contentDescription = "إغلاق"
        setOnClickListener { finishCancelled() }
      },
      FrameLayout.LayoutParams(dp(48), dp(56), Gravity.START),
    )
    root.addView(
      toolbar,
      LinearLayout.LayoutParams(
        LinearLayout.LayoutParams.MATCH_PARENT,
        dp(56),
      ),
    )

    val content = FrameLayout(this)
    webView = WebView(this).apply {
      setBackgroundColor(BACKGROUND)
      settings.javaScriptEnabled = true
      settings.domStorageEnabled = true
      settings.cacheMode = WebSettings.LOAD_NO_CACHE
      settings.saveFormData = false
      settings.allowFileAccess = false
      settings.allowContentAccess = false
      settings.allowFileAccessFromFileURLs = false
      settings.allowUniversalAccessFromFileURLs = false
      settings.javaScriptCanOpenWindowsAutomatically = false
      settings.setSupportMultipleWindows(false)
      settings.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
      if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
        settings.safeBrowsingEnabled = true
      }
      webViewClient = object : WebViewClient() {
        override fun shouldOverrideUrlLoading(
          view: WebView?,
          request: WebResourceRequest?,
        ): Boolean = handleNavigation(request?.url)

        @Suppress("DEPRECATION")
        override fun shouldOverrideUrlLoading(view: WebView?, url: String?): Boolean =
          handleNavigation(url?.toUri())

        override fun onPageFinished(view: WebView?, url: String?) {
          if (mainFrameFailed) return
          this@CheckoutActivity.progress.visibility = View.GONE
          errorMessage.visibility = View.GONE
          retryButton.visibility = View.GONE
          webView.visibility = View.VISIBLE
        }

        override fun onReceivedError(
          view: WebView?,
          request: WebResourceRequest?,
          error: WebResourceError?,
        ) {
          if (request?.isForMainFrame == true) {
            showError("الاتصال بصفحة الدفع غير متاح الآن\nحاول مرة أخرى")
          }
        }
      }
    }
    progress = ProgressBar(this).apply {
      isIndeterminate = true
      contentDescription = "جارٍ تحميل صفحة الدفع"
    }
    errorMessage = TextView(this).apply {
      visibility = View.GONE
      gravity = Gravity.CENTER
      textSize = 16f
      setTextColor(Color.WHITE)
      setPadding(dp(28), dp(28), dp(28), dp(28))
      ViewCompat.setAccessibilityLiveRegion(
        this,
        ViewCompat.ACCESSIBILITY_LIVE_REGION_ASSERTIVE,
      )
    }
    retryButton = TextView(this).apply {
      visibility = View.GONE
      gravity = Gravity.CENTER
      text = "إعادة المحاولة"
      textSize = 16f
      setTextColor(Color.WHITE)
      setTypeface(typeface, Typeface.BOLD)
      setBackgroundColor(Color.rgb(36, 105, 255))
      isClickable = true
      isFocusable = true
      contentDescription = "إعادة تحميل صفحة الدفع"
      setOnClickListener { retryCheckout() }
    }
    content.addView(
      webView,
      FrameLayout.LayoutParams(
        FrameLayout.LayoutParams.MATCH_PARENT,
        FrameLayout.LayoutParams.MATCH_PARENT,
      ),
    )
    content.addView(
      progress,
      FrameLayout.LayoutParams(dp(44), dp(44), Gravity.CENTER),
    )
    content.addView(
      errorMessage,
      FrameLayout.LayoutParams(
        FrameLayout.LayoutParams.MATCH_PARENT,
        FrameLayout.LayoutParams.MATCH_PARENT,
      ),
    )
    content.addView(
      retryButton,
      FrameLayout.LayoutParams(dp(176), dp(48), Gravity.CENTER_HORIZONTAL or Gravity.BOTTOM).apply {
        bottomMargin = dp(32)
      },
    )
    root.addView(
      content,
      LinearLayout.LayoutParams(
        LinearLayout.LayoutParams.MATCH_PARENT,
        0,
        1f,
      ),
    )
    return root
  }

  private fun handleNavigation(uri: Uri?): Boolean {
    if (uri == null) return false
    if (uri.scheme.equals("rokn", ignoreCase = true) && uri.host == "payment-result") {
      setResult(
        Activity.RESULT_OK,
        Intent().putExtra(RESULT_URL, uri.toString()),
      )
      finish()
      return true
    }
    return !isAllowedWebNavigation(uri)
  }

  private fun isTrustedCheckoutEntry(url: String): Boolean {
    val uri = runCatching { url.toUri() }.getOrNull() ?: return false
    val trustedKashier =
      uri.scheme.equals("https", ignoreCase = true) &&
        uri.host.equals("checkout.kashier.io", ignoreCase = true)
    val localDebug =
      BuildConfig.DEBUG &&
        uri.scheme.equals("http", ignoreCase = true) &&
        (uri.host == "10.0.2.2" || uri.host == "127.0.0.1" || uri.host == "localhost")
    return trustedKashier || localDebug
  }

  /**
   * Kashier may hand the main frame to a bank-hosted 3-D Secure page. Keep
   * that HTTPS navigation inside the isolated checkout activity, while the
   * entry URL itself remains pinned to Kashier above.
   */
  private fun isAllowedWebNavigation(uri: Uri): Boolean {
    return uri.scheme.equals("https", ignoreCase = true) ||
      (BuildConfig.DEBUG && uri.scheme.equals("http", ignoreCase = true))
  }

  private fun showError(message: String) {
    mainFrameFailed = true
    progress.visibility = View.GONE
    webView.visibility = View.GONE
    errorMessage.text = message
    errorMessage.visibility = View.VISIBLE
    retryButton.visibility = View.VISIBLE
    errorMessage.announceForAccessibility(message)
  }

  private fun retryCheckout() {
    if (!isTrustedCheckoutEntry(checkoutUrl)) return
    mainFrameFailed = false
    errorMessage.visibility = View.GONE
    retryButton.visibility = View.GONE
    webView.visibility = View.VISIBLE
    progress.visibility = View.VISIBLE
    webView.loadUrl(checkoutUrl)
  }

  override fun onDestroy() {
    if (::webView.isInitialized) {
      webView.stopLoading()
      webView.clearHistory()
      webView.clearCache(true)
      webView.removeAllViews()
      webView.destroy()
    }
    // Payment providers and 3-D Secure pages may create temporary session
    // cookies. Remove only session cookies: persistent app/browser cookies
    // remain untouched.
    CookieManager.getInstance().removeSessionCookies(null)
    CookieManager.getInstance().flush()
    super.onDestroy()
  }

  private fun finishCancelled() {
    setResult(Activity.RESULT_CANCELED)
    finish()
  }

  private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

  companion object {
    const val EXTRA_URL = "checkout_url"
    const val RESULT_URL = "result_url"
    private val BACKGROUND = Color.rgb(8, 11, 18)
  }
}
