package com.rokn

import android.app.Application
import android.content.res.Configuration
import com.facebook.react.PackageList
import com.facebook.react.ReactApplication
import com.facebook.react.ReactHost
import com.facebook.react.ReactNativeApplicationEntryPoint.loadReactNative
import com.facebook.react.common.ReleaseLevel
import com.facebook.react.defaults.DefaultNewArchitectureEntryPoint
import com.facebook.react.modules.i18nmanager.I18nUtil
import expo.modules.ApplicationLifecycleDispatcher
import expo.modules.ExpoReactHostFactory
import com.rokn.auth.RoknAuthBrowserPackage
import com.rokn.checkout.RoknCheckoutPackage
import com.rokn.downloads.RoknDownloadsPackage
import com.rokn.diagnostics.RoknDiagnosticsPackage
import com.rokn.diagnostics.RoknDiagnosticsStore
import com.rokn.media.RoknMediaInspectorPackage
import com.rokn.reminders.RoknReminderPackage
import com.rokn.orientation.RoknOrientationPackage
import com.rokn.notifications.RoknPushTokenPackage
import com.rokn.session.RoknSecureSessionPackage

class MainApplication : Application(), ReactApplication {

  override val reactHost: ReactHost by lazy {
    ExpoReactHostFactory.getDefaultReactHost(
      context = applicationContext,
      packageList =
        PackageList(this).packages.apply {
          // Packages that cannot be autolinked yet can be added manually here, for example:
          add(RoknAuthBrowserPackage())
          add(RoknCheckoutPackage())
          add(RoknDownloadsPackage())
          add(RoknDiagnosticsPackage())
          add(RoknMediaInspectorPackage())
          add(RoknReminderPackage())
          add(RoknOrientationPackage())
          add(RoknPushTokenPackage())
          add(RoknSecureSessionPackage())
        },
    )
  }

  override fun onCreate() {
    super.onCreate()
    RoknDiagnosticsStore.install(this)
    // Arabic is the shipping direction, so configure Yoga before the React
    // runtime is created. Doing this only from JavaScript applies one launch
    // too late on an existing installation and leaves native rows in LTR.
    I18nUtil.getInstance().allowRTL(this, true)
    I18nUtil.getInstance().forceRTL(this, true)
    I18nUtil.getInstance().swapLeftAndRightInRTL(this, true)
    DefaultNewArchitectureEntryPoint.releaseLevel = try {
      ReleaseLevel.valueOf(BuildConfig.REACT_NATIVE_RELEASE_LEVEL.uppercase())
    } catch (e: IllegalArgumentException) {
      ReleaseLevel.STABLE
    }
    loadReactNative(this)
    ApplicationLifecycleDispatcher.onApplicationCreate(this)
  }

  override fun onConfigurationChanged(newConfig: Configuration) {
    super.onConfigurationChanged(newConfig)
    ApplicationLifecycleDispatcher.onConfigurationChanged(this, newConfig)
  }
}
