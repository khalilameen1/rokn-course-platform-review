package com.rokn.reminders

import android.Manifest
import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.content.ContextCompat
import androidx.core.app.NotificationManagerCompat
import com.facebook.react.bridge.Promise
import com.facebook.react.bridge.ReactApplicationContext
import com.facebook.react.bridge.ReactContextBaseJavaModule
import com.facebook.react.bridge.ReactMethod
import com.facebook.react.modules.core.PermissionAwareActivity
import com.facebook.react.modules.core.PermissionListener

class RoknReminderModule(
  private val reactContext: ReactApplicationContext,
) : ReactContextBaseJavaModule(reactContext) {
  override fun getName(): String = "RoknReminders"

  @ReactMethod
  fun requestPermission(promise: Promise) {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) {
      promise.resolve(notificationsEnabled())
      return
    }
    if (
      ContextCompat.checkSelfPermission(
        reactContext,
        Manifest.permission.POST_NOTIFICATIONS,
      ) == PackageManager.PERMISSION_GRANTED
    ) {
      promise.resolve(notificationsEnabled())
      return
    }
    val activity = reactContext.currentActivity as? PermissionAwareActivity
    if (activity == null) {
      promise.resolve(false)
      return
    }
    val listener = PermissionListener { requestCode, _, grantResults ->
      if (requestCode != PERMISSION_REQUEST) return@PermissionListener false
      promise.resolve(
        grantResults.firstOrNull() == PackageManager.PERMISSION_GRANTED &&
          notificationsEnabled(),
      )
      true
    }
    activity.requestPermissions(
      arrayOf(Manifest.permission.POST_NOTIFICATIONS),
      PERMISSION_REQUEST,
      listener,
    )
  }

  @ReactMethod
  fun schedule(
    id: Double,
    title: String,
    body: String,
    triggerAt: Double,
    courseId: String?,
    link: String?,
    kind: String?,
    imageUrl: String?,
    actionLabel: String?,
    promise: Promise,
  ) {
    if (!canNotify()) {
      promise.resolve(false)
      return
    }
    val alarmManager =
      reactContext.getSystemService(Context.ALARM_SERVICE) as AlarmManager
    val notificationId = id.toInt()
    val pendingIntent = PendingIntent.getBroadcast(
      reactContext,
      notificationId,
      Intent(reactContext, ReminderReceiver::class.java).apply {
        putExtra(ReminderReceiver.EXTRA_ID, notificationId)
        putExtra(ReminderReceiver.EXTRA_TITLE, title)
        putExtra(ReminderReceiver.EXTRA_BODY, body)
        putExtra(ReminderReceiver.EXTRA_COURSE_ID, courseId)
        putExtra(ReminderReceiver.EXTRA_LINK, link)
        putExtra(ReminderReceiver.EXTRA_KIND, kind ?: ReminderReceiver.KIND_LEARNING)
        putExtra(ReminderReceiver.EXTRA_IMAGE_URL, imageUrl)
        putExtra(ReminderReceiver.EXTRA_ACTION_LABEL, actionLabel)
      },
      PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
    )
    val at = triggerAt.toLong().coerceAtLeast(System.currentTimeMillis() + 1_000)
    alarmManager.setWindow(
      AlarmManager.RTC_WAKEUP,
      at,
      10 * 60 * 1_000L,
      pendingIntent,
    )
    ReminderScheduleStore.save(
      reactContext,
      StoredReminder(
        notificationId,
        title,
        body,
        at,
        courseId,
        link,
        kind ?: ReminderReceiver.KIND_LEARNING,
        imageUrl,
        actionLabel,
      ),
    )
    promise.resolve(true)
  }

  @ReactMethod
  fun preview(
    title: String,
    body: String,
    link: String?,
    kind: String?,
    imageUrl: String?,
    actionLabel: String?,
    promise: Promise,
  ) {
    if (!canNotify()) {
      promise.resolve(false)
      return
    }
    ReminderReceiver.showAsync(
      reactContext,
      (System.currentTimeMillis() % Int.MAX_VALUE).toInt(),
      title,
      body,
      null,
      link,
      kind ?: ReminderReceiver.KIND_LEARNING,
      imageUrl,
      actionLabel,
    )
    promise.resolve(true)
  }

  @ReactMethod
  fun cancel(id: Double, promise: Promise) {
    try {
      val notificationId = id.toInt()
      ReminderScheduleStore.remove(reactContext, notificationId)
      val pendingIntent = PendingIntent.getBroadcast(
        reactContext,
        notificationId,
        Intent(reactContext, ReminderReceiver::class.java),
        PendingIntent.FLAG_NO_CREATE or PendingIntent.FLAG_IMMUTABLE,
      )
      if (pendingIntent != null) {
        val alarmManager =
          reactContext.getSystemService(Context.ALARM_SERVICE) as AlarmManager
        alarmManager.cancel(pendingIntent)
        pendingIntent.cancel()
      }
      promise.resolve(null)
    } catch (error: Exception) {
      promise.reject("REMINDER_CANCEL_FAILED", error)
    }
  }

  private fun canNotify(): Boolean =
    notificationsEnabled() &&
      (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
        ContextCompat.checkSelfPermission(
          reactContext,
          Manifest.permission.POST_NOTIFICATIONS,
        ) == PackageManager.PERMISSION_GRANTED)

  private fun notificationsEnabled(): Boolean =
    NotificationManagerCompat.from(reactContext).areNotificationsEnabled()

  companion object {
    private const val PERMISSION_REQUEST = 7302
  }
}
