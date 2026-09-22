package com.rokn.reminders

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Color
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.net.toUri
import com.rokn.MainActivity
import com.rokn.R
import com.rokn.media.NotificationArtworkLoader

class ReminderReceiver : BroadcastReceiver() {
  override fun onReceive(context: Context, intent: Intent) {
    val notificationId = intent.getIntExtra(EXTRA_ID, 9001)
    ReminderScheduleStore.remove(context, notificationId)
    val title = intent.getStringExtra(EXTRA_TITLE) ?: "ركن"
    val body = intent.getStringExtra(EXTRA_BODY) ?: "مقطعك التالي جاهز"
    val courseId = intent.getStringExtra(EXTRA_COURSE_ID)
    val link = intent.getStringExtra(EXTRA_LINK)
    val kind = intent.getStringExtra(EXTRA_KIND) ?: KIND_LEARNING
    val imageUrl = intent.getStringExtra(EXTRA_IMAGE_URL)
    val actionLabel = intent.getStringExtra(EXTRA_ACTION_LABEL)

    if (imageUrl.isNullOrBlank()) {
      show(context, notificationId, title, body, courseId, link, kind, null, actionLabel)
      return
    }

    // The managed image pipeline owns decoding/cache. Finish the broadcast
    // on image success, failure or deadline, with a plain-text fallback.
    val pendingResult = goAsync()
    NotificationArtworkLoader.load(context, imageUrl) { picture ->
      try {
        show(
          context,
          notificationId,
          title,
          body,
          courseId,
          link,
          kind,
          picture,
          actionLabel,
        )
      } finally {
        pendingResult.finish()
      }
    }
  }

  companion object {
    const val EXTRA_ID = "notification_id"
    const val EXTRA_TITLE = "notification_title"
    const val EXTRA_BODY = "notification_body"
    const val EXTRA_COURSE_ID = "course_id"
    const val EXTRA_LINK = "notification_link"
    const val EXTRA_KIND = "notification_kind"
    const val EXTRA_IMAGE_URL = "notification_image_url"
    const val EXTRA_ACTION_LABEL = "notification_action_label"

    const val KIND_LEARNING = "learning_reminder"
    private const val CHANNEL_LEARNING = "rokn-learning"
    private const val CHANNEL_OFFERS = "rokn-offers"
    private const val CHANNEL_UPDATES = "rokn-updates"

    fun show(
      context: Context,
      id: Int,
      title: String,
      body: String,
      courseId: String?,
      link: String? = null,
      kind: String = KIND_LEARNING,
      remotePicture: Bitmap? = null,
      actionLabel: String? = null,
    ) {
      val notificationManager = NotificationManagerCompat.from(context)
      if (!notificationManager.areNotificationsEnabled()) return

      val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
      val channelId = channelFor(kind)
      createChannel(manager, channelId)

      val destination = safeDestination(link, courseId, kind)
      val contentIntent = PendingIntent.getActivity(
        context,
        id,
        Intent(Intent.ACTION_VIEW, destination, context, MainActivity::class.java).apply {
          flags = Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
        },
        PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
      )
      val notificationIcon = runCatching {
        context.resources.getResourceName(R.drawable.ic_notification)
        R.drawable.ic_notification
      }.getOrElse { context.applicationInfo.icon }

      val coinPicture = if (isCoin(kind)) {
        BitmapFactory.decodeResource(context.resources, R.mipmap.ic_launcher_foreground)
      } else {
        null
      }
      val picture = remotePicture ?: coinPicture
      val largeIcon = picture ?: BitmapFactory.decodeResource(
        context.resources,
        context.applicationInfo.icon,
      )
      val style = if (picture != null) {
        NotificationCompat.BigPictureStyle()
          .bigPicture(picture)
          .setBigContentTitle(title)
          .setSummaryText(body)
      } else {
        NotificationCompat.BigTextStyle()
          .bigText(body)
          .setBigContentTitle(title)
          .setSummaryText("Rokn")
      }

      val notification = NotificationCompat.Builder(context, channelId)
        .setSmallIcon(notificationIcon)
        .setLargeIcon(largeIcon)
        .setContentTitle(title)
        .setContentText(body)
        .setSubText("Rokn")
        .setStyle(style)
        .setColor(Color.rgb(44, 105, 219))
        .setCategory(categoryFor(kind))
        .setAutoCancel(true)
        .setOnlyAlertOnce(true)
        .setContentIntent(contentIntent)
        .addAction(0, actionLabel?.takeIf { it.isNotBlank() } ?: defaultAction(kind), contentIntent)
        .setPriority(NotificationCompat.PRIORITY_DEFAULT)
        .setSilent(true)
        .build()

      try {
        notificationManager.notify(id, notification)
      } catch (_: SecurityException) {
        // Permission can be revoked after scheduling; never crash the process.
      }
    }

    fun showAsync(
      context: Context,
      id: Int,
      title: String,
      body: String,
      courseId: String?,
      link: String?,
      kind: String,
      imageUrl: String?,
      actionLabel: String?,
    ) {
      if (imageUrl.isNullOrBlank()) {
        show(context, id, title, body, courseId, link, kind, null, actionLabel)
        return
      }
      NotificationArtworkLoader.load(context, imageUrl) { picture ->
        show(
          context,
          id,
          title,
          body,
          courseId,
          link,
          kind,
          picture,
          actionLabel,
        )
      }
    }

    private fun channelFor(kind: String) = when {
      kind == "coin_offer" || kind == "new_course" || kind == "course_recommendation" ->
        CHANNEL_OFFERS
      kind == "project_update" || kind == "certificate_ready" || kind == "coin_reward" ->
        CHANNEL_UPDATES
      else -> CHANNEL_LEARNING
    }

    private fun createChannel(manager: NotificationManager, channelId: String) {
      if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
      val (name, description) = when (channelId) {
        CHANNEL_OFFERS -> "عروض ركن" to "الكورسات الجديدة وعروض الرصيد التي اخترت استقبالها"
        CHANNEL_UPDATES -> "تحديثات الحساب" to "نتائج المشاريع والشهادات وحركة الرصيد"
        else -> "تذكيرات التعلّم" to "تذكيرات هادئة مرتبطة بمكانك داخل الكورس"
      }
      manager.createNotificationChannel(
        NotificationChannel(channelId, name, NotificationManager.IMPORTANCE_DEFAULT).apply {
          this.description = description
          enableVibration(false)
          setSound(null, null)
          lightColor = Color.rgb(44, 105, 219)
        },
      )
    }

    private fun categoryFor(kind: String) = when {
      kind == "coin_offer" || kind == "new_course" || kind == "course_recommendation" ->
        NotificationCompat.CATEGORY_PROMO
      kind == "continue_course" -> NotificationCompat.CATEGORY_RECOMMENDATION
      kind == "project_update" || kind == "certificate_ready" || kind == "coin_reward" ->
        NotificationCompat.CATEGORY_STATUS
      else -> NotificationCompat.CATEGORY_REMINDER
    }

    private fun defaultAction(kind: String) = when (kind) {
      "streak_reminder" -> "حافظ على استمراريتك"
      "continue_course" -> "أكمل الكورس"
      "course_recommendation" -> "تفاصيل الكورس"
      "new_course" -> "افتح الكورس"
      "coin_reward" -> "افتح المحفظة"
      "coin_offer" -> "افتح العرض"
      "project_update" -> "افتح النتيجة"
      "certificate_ready" -> "افتح الشهادة"
      else -> "أكمل من مكانك"
    }

    private fun isCoin(kind: String) = kind == "coin_reward" || kind == "coin_offer"

    private fun safeDestination(link: String?, courseId: String?, kind: String): Uri {
      val candidate = link?.trim()?.takeIf { it.isNotEmpty() }
      if (candidate != null) {
        val parsed = runCatching { candidate.toUri() }.getOrNull()
        val accepted = parsed != null && (
          parsed.scheme.equals("rokn", ignoreCase = true) ||
            (
              parsed.scheme.equals("https", ignoreCase = true) &&
                parsed.host?.lowercase() in setOf("rokn.app", "www.rokn.app", "rokn.com", "www.rokn.com")
              )
          )
        if (accepted) return parsed!!
      }
      if (courseId.isNullOrBlank()) {
        return (if (isCoin(kind)) "rokn://wallet" else "rokn://home").toUri()
      }
      val builder = Uri.Builder()
        .scheme("rokn")
        .authority("course")
        .appendPath(courseId)
      if (
        kind == "continue_course" ||
          kind == "learning_reminder" ||
          kind == "streak_reminder"
      ) {
        builder.appendPath("watch")
      }
      return builder.build()
    }

  }
}
