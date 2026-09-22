package com.rokn.media

import android.content.Context
import android.graphics.Bitmap
import android.os.Handler
import android.os.Looper
import androidx.core.net.toUri
import com.facebook.common.executors.CallerThreadExecutor
import com.facebook.common.references.CloseableReference
import com.facebook.cache.disk.DiskCacheConfig
import com.facebook.datasource.DataSource
import com.facebook.imagepipeline.backends.okhttp3.OkHttpImagePipelineConfigFactory
import com.facebook.imagepipeline.cache.MemoryCacheParams
import com.facebook.imagepipeline.common.ResizeOptions
import com.facebook.imagepipeline.core.DownsampleMode
import com.facebook.imagepipeline.core.ImagePipeline
import com.facebook.imagepipeline.core.ImagePipelineFactory
import com.facebook.imagepipeline.datasource.BaseBitmapDataSubscriber
import com.facebook.imagepipeline.image.CloseableImage
import com.facebook.imagepipeline.request.ImageRequestBuilder
import java.io.IOException
import java.util.concurrent.TimeUnit
import okhttp3.OkHttpClient

/** Managed, size-bounded artwork for both scheduled and immediate notifications.
 * An alarm can run before React initializes. This bounded public-artwork cache
 * never initializes or replaces React Native's authenticated image pipeline.
 */
internal object NotificationArtworkLoader {
  private val deadlines = Handler(Looper.getMainLooper())
  private var artworkFactory: ImagePipelineFactory? = null

  @Synchronized
  private fun pipeline(context: Context): ImagePipeline {
    val factory = artworkFactory ?: ImagePipelineFactory(
      OkHttpImagePipelineConfigFactory.newBuilder(context.applicationContext, imageClient())
        .setDownsampleMode(DownsampleMode.ALWAYS)
        .setMainDiskCacheConfig(
          DiskCacheConfig.newBuilder(context.applicationContext)
            .setBaseDirectoryName("notification_artwork")
            .setMaxCacheSize(8L * 1024 * 1024)
            .setMaxCacheSizeOnLowDiskSpace(4L * 1024 * 1024)
            .setMaxCacheSizeOnVeryLowDiskSpace(2L * 1024 * 1024)
            .build(),
        )
        .setBitmapMemoryCacheParamsSupplier {
          MemoryCacheParams(8 * 1024 * 1024, 16, 4 * 1024 * 1024, 8, 4 * 1024 * 1024)
        }
        .setEncodedMemoryCacheParamsSupplier {
          MemoryCacheParams(5 * 1024 * 1024, 16, 5 * 1024 * 1024, 16, 5 * 1024 * 1024)
        }
        .build(),
    ).also { artworkFactory = it }
    return factory.imagePipeline
  }

  private fun imageClient() = OkHttpClient.Builder()
    .connectTimeout(4, TimeUnit.SECONDS)
    .readTimeout(6, TimeUnit.SECONDS)
    .callTimeout(8, TimeUnit.SECONDS)
    .followRedirects(false)
    .followSslRedirects(false)
    .addInterceptor { chain ->
      val response = chain.proceed(chain.request())
      val body = response.body
      if (!response.isSuccessful || body == null || body.contentLength() > 5L * 1024 * 1024) {
        response.close()
        throw IOException("Notification artwork unavailable or too large")
      }
      response.newBuilder().body(BoundedImageBody(body, 5L * 1024 * 1024)).build()
    }
    .build()

  /** Consume the bitmap synchronously here; Fresco releases it after callback.
   * NotificationManager copies it into the system process during notify(). */
  fun load(context: Context, rawUrl: String?, publish: (Bitmap?) -> Unit) {
    val uri = rawUrl?.let { runCatching { it.toUri() }.getOrNull() }
    if (uri == null || !uri.scheme.equals("https", ignoreCase = true) || uri.host.isNullOrBlank()) {
      publish(null)
      return
    }
    val source = try {
      val request = ImageRequestBuilder.newBuilderWithSource(uri)
        .setResizeOptions(ResizeOptions(1024, 512, 1024f))
        .setProgressiveRenderingEnabled(false)
        .build()
      pipeline(context).fetchDecodedImage(request, NotificationArtworkLoader)
    } catch (_: Exception) {
      publish(null)
      return
    }
    lateinit var timeout: Runnable
    val completion = ArtworkCompletion<Bitmap>(publish) {
      deadlines.removeCallbacks(timeout)
      source.close()
    }
    timeout = Runnable { completion.complete(null) }
    deadlines.postDelayed(timeout, 8_000)
    try {
      source.subscribe(object : BaseBitmapDataSubscriber() {
        override fun onNewResultImpl(bitmap: Bitmap?) = completion.complete(bitmap)
        override fun onFailureImpl(dataSource: DataSource<CloseableReference<CloseableImage>>) =
          completion.complete(null)
      }, CallerThreadExecutor.getInstance())
    } catch (_: Exception) {
      completion.complete(null)
    }
  }
}
