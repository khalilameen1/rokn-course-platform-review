import fs from 'fs';
import path from 'path';

const readSource = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('first-launch experience', () => {
  it('opens the guest home without an onboarding or marketing gate', () => {
    const navigation = readSource('src/navigation/Navigation.tsx');
    const androidApplication = readSource(
      'android/app/src/main/java/com/rokn/MainApplication.kt',
    );
    const iosApplication = readSource('ios/Rokn/AppDelegate.swift');

    expect(
      fs.existsSync(path.resolve(__dirname, '../src/screens/Onboarding.tsx')),
    ).toBe(false);
    expect(navigation).toContain('initialRouteName="Home"');
    expect(navigation).not.toContain('LanguageSelect');
    expect(navigation).not.toMatch(/Onboarding|ابدأ الآن|مزايا/);
    expect(androidApplication).toContain('forceRTL(this, true)');
    expect(iosApplication).toContain('i18n.forceRTL(true)');
  });

  it('keeps native loading limited to the Rokn brand and one slogan at most', () => {
    const androidSplash = readSource(
      'android/app/src/main/res/drawable/rokn_launch_screen.xml',
    );
    const iosSplash = readSource('ios/Rokn/LaunchScreen.storyboard');
    const appConfig = JSON.parse(readSource('app.json')) as {
      expo: {
        plugins: Array<string | [string, Record<string, unknown>]>;
        splash?: unknown;
        android: {splash?: unknown};
      };
    };

    expect(androidSplash).toContain('@drawable/rokn_wordmark');
    expect(iosSplash).toContain('image="RoknWordmark"');
    expect(iosSplash).toContain('text="كورسات هتكملها"');
    expect(`${androidSplash}\n${iosSplash}`).not.toMatch(
      /تعلّم بمقاطع|مشروعات|Rokn AI|ابدأ الآن/,
    );
    const plugin = appConfig.expo.plugins.find(
      entry => Array.isArray(entry) && entry[0] === 'expo-splash-screen',
    ) as [string, Record<string, unknown>];
    expect(plugin[1]).toEqual({
      image: './src/assets/images/logo.png',
      imageWidth: 205,
      android: {imageWidth: 180},
      resizeMode: 'contain',
      backgroundColor: '#0B1628',
    });
    expect(appConfig.expo.splash).toBeUndefined();
    expect(appConfig.expo.android.splash).toBeUndefined();
  });

  it('hands native startup to the first React frame without a second I/O gate', () => {
    const activity = readSource(
      'android/app/src/main/java/com/rokn/MainActivity.kt',
    );
    const theme = readSource('android/app/src/main/res/values/styles.xml');
    const manifest = readSource('android/app/src/main/AndroidManifest.xml');
    const entry = readSource('index.js');
    const podfile = readSource('ios/Podfile');
    expect(activity).toContain('SplashScreenManager.registerOnActivity(this)');
    expect(
      activity.indexOf('SplashScreenManager.registerOnActivity(this)'),
    ).toBeLessThan(activity.indexOf('super.onCreate(null)'));
    expect(activity).not.toContain('setTheme(R.style.AppTheme)');
    expect(theme).toContain(
      'name="RoknLaunchTheme" parent="Theme.SplashScreen"',
    );
    expect(theme).toContain('name="postSplashScreenTheme">@style/AppTheme');
    expect(manifest).toContain('android:theme="@style/RoknLaunchTheme"');
    expect(entry).toContain('setSplashOptions({duration: 0, fade: false})');
    expect(entry).not.toContain('preventAutoHideAsync');
    expect(podfile).toContain('use_expo_modules!');
  });

  it('does not hold guest Home behind session restore', () => {
    const entry = readSource('index.js');
    const initializer = readSource('src/screens/AppInitializer.tsx');
    const navigation = readSource('src/navigation/Navigation.tsx');
    const sessionBootstrap = readSource(
      'src/screens/appInitializer/useSessionBootstrap.ts',
    );
    const linking = readSource('src/navigation/roknLinking.ts');
    const journey = readSource(
      'src/navigation/useInterruptedJourneyRestore.ts',
    );

    expect(entry).not.toContain('PersistBootstrapGate');
    expect(initializer).toContain('<Navigation sessionReady={sessionReady} />');
    expect(initializer).not.toContain(
      'appLoaded && sessionReady ? <Navigation />',
    );
    expect(navigation).toContain('fallback={<NavigationFallback />}');
    expect(sessionBootstrap).toContain(
      'const quickRestore = await settleByDeadline(restoreFlight, 3_500)',
    );
    expect(sessionBootstrap).toContain(
      "if (quickRestore.status === 'fulfilled')",
    );
    expect(sessionBootstrap).toContain(
      'await applyRestore(quickRestore.value, initialUrlFlight)',
    );
    expect(sessionBootstrap).toContain('if (active) setReady(true)');
    expect(sessionBootstrap).toContain('peekSecureSession()');
    expect(linking).toContain(
      'initialAppUrlFlight = Linking.getInitialURL().catch(() => null)',
    );
    expect(sessionBootstrap).toContain(
      'const initialUrlFlight = getInitialAppUrl()',
    );
    expect(journey).toContain('getInitialAppUrl()');
    expect(sessionBootstrap).not.toContain('Linking.getInitialURL()');
    expect(journey).not.toContain('Linking.getInitialURL()');
  });

  it('keeps a pending payment recoverable while the app stays foregrounded', () => {
    const runtime = readSource('src/screens/appInitializer/useAppRuntime.ts');
    const walletCheckout = readSource(
      'src/screens/wallet/useWalletCheckout.ts',
    );

    expect(runtime).toContain(
      'const delays = [4_000, 10_000, 20_000, 40_000, 60_000]',
    );
    expect(runtime).toContain('storeAttempt >= delays.length');
    expect(runtime).toContain("AppState.currentState !== 'active'");
    expect(runtime).toContain('clearStoreTimer();');
    expect(walletCheckout).toContain(
      'subscribeCoinCheckoutCredits((_result, ownerScope) =>',
    );
    expect(walletCheckout).toContain('void handleRecoveredCredit(ownerScope)');
  });

  it('adopts an Android OAuth callback even when the Custom Tab returns first', () => {
    const sessionBootstrap = readSource(
      'src/screens/appInitializer/useSessionBootstrap.ts',
    );
    const runtime = readSource('src/screens/appInitializer/useAppRuntime.ts');
    const androidSession = readSource('src/services/androidAuthSession.ts');

    expect(runtime).toContain("Linking.addEventListener('url', ({url}) =>");
    expect(runtime).toContain('androidAuthSessionOwnsCallback(url)');
    expect(runtime).toContain('resumePendingAuthentication(url)');
    expect(runtime).not.toContain("from '../../services/socialAuth'");
    expect(sessionBootstrap).toContain('resumePendingSocialAuth(callbackUrl)');
    expect(sessionBootstrap).toContain(
      'const initialUrlFlight = getInitialAppUrl()',
    );
    expect(sessionBootstrap).toContain('void initialUrlFlight');
    expect(androidSession).toContain('recoverable: true');
    expect(androidSession).toContain("queryValue(candidate, 'attempt')");
  });

  it('has one owner for session restore and the post-login return', () => {
    const sessionBootstrap = readSource(
      'src/screens/appInitializer/useSessionBootstrap.ts',
    );
    const runtime = readSource('src/screens/appInitializer/useAppRuntime.ts');
    const login = readSource('src/components/auth/SocialAuthShell.tsx');
    const journey = readSource(
      'src/navigation/useInterruptedJourneyRestore.ts',
    );

    const applyRestoreStart = sessionBootstrap.indexOf('const applyRestore');
    const restoredSessionDecision = sessionBootstrap.slice(
      applyRestoreStart,
      sessionBootstrap.indexOf('void (async () =>', applyRestoreStart),
    );
    const guestRestoreDecision = sessionBootstrap.slice(
      sessionBootstrap.indexOf('const settleAsGuest'),
      sessionBootstrap.indexOf('const applyRestore'),
    );
    expect(
      restoredSessionDecision.indexOf('restored.isAuthenticated'),
    ).toBeLessThan(restoredSessionDecision.indexOf('settleAsGuest'));
    expect(guestRestoreDecision).toContain('peekSecureSession()');
    expect(guestRestoreDecision.indexOf('extractApiToken')).toBeLessThan(
      guestRestoreDecision.indexOf('dispatch(LogOut())'),
    );
    expect(guestRestoreDecision.indexOf('dispatch(LogOut())')).toBeLessThan(
      guestRestoreDecision.indexOf(
        'resumePendingAfterGuestRestore(initialUrlFlight)',
      ),
    );
    expect(runtime).toContain("Platform.OS === 'android' && !hasSession");
    expect(runtime).not.toMatch(
      /restoreAfterUnlock\(\);\s*void resumePendingSocialAuth\(\)/,
    );
    expect(runtime).toContain('if (!sessionReady) return undefined;');
    expect(runtime).toMatch(
      /useEffect\(\(\) => \{\s*if \(!sessionReady\) return;\s*void reconcilePushRegistration\(\);/,
    );

    const loginCommitStart = login.indexOf('const authenticatedSession');
    const committedLogin = login.slice(
      loginCommitStart,
      login.indexOf('} catch (error)', loginCommitStart),
    );
    expect(committedLogin.indexOf('peekSecureSession().session')).toBeLessThan(
      committedLogin.indexOf('dispatch(saveLoginData(committedSession));'),
    );
    expect(committedLogin).not.toContain('if (!stillOwnsIntent()) return;');
    const postCommitNavigation = login.slice(
      login.indexOf('dispatch(saveLoginData(committedSession));'),
      login.indexOf('} catch (error)'),
    );
    expect(postCommitNavigation).not.toContain('navigation.reset(');
    expect(journey).toContain(
      "loginReturnResetState(returnTo, 'authenticated')",
    );
    expect(login).toContain(
      'await settleWithin(prepareGuestJourney, undefined, 600)',
    );
    expect(login).toContain(
      'await settleWithin(cleanupAbandonedLogin, undefined, 600)',
    );
    expect(journey).toContain(
      'shouldPreserveVisibleJourneyAcrossSessionChange(',
    );
    expect(journey).toContain('if (passiveSessionReturnRef.current) {');
  });

  it('uses reset semantics when a root screen has no history', () => {
    const header = readSource('src/components/view/HeaderWithBack.tsx');

    expect(header).toContain('goBackOrHome(navigation)');
    expect(header).not.toContain("navigate('Home')");
  });

  it('serializes mutable settings so the last learner choice wins', () => {
    const settings = readSource(
      'src/screens/settings/useSettingsPreferences.ts',
    );
    const accountWrites = readSource('src/services/accountPreferenceWrites.ts');

    expect(settings).toContain('toggleUpdates = createKeyedAsyncQueue()');
    expect(settings).toContain('toggleUpdates(`${boundary.scope}:${key}`');
    expect(settings).toContain(
      'withAccountPreferenceWrite as withSettingsScopeWrite',
    );
    expect(accountWrites).toContain('const writes = createKeyedAsyncQueue()');
    expect(accountWrites).toMatch(
      /writes\(boundary\.scope, async \(\) => \{\s*assertAccountSessionBoundary\(boundary\);\s*const result = await operation\(\);\s*assertAccountSessionBoundary\(boundary\);/,
    );
    expect(settings).toContain('withSettingsScopeWrite');
    expect(settings).toContain('preferenceRevisionRef');
    expect(settings).toContain("isUnchanged('VIDEO_QUALITY')");
    expect(settings).toContain('enqueuePreferenceWrite');
    expect(settings).toMatch(
      /const boundaryFlight = ownerBoundary\s*\? Promise\.resolve\(ownerBoundary\)\s*: captureAccountSessionBoundary\(\)/,
    );
  });
});
