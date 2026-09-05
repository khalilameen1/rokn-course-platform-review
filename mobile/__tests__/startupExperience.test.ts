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
        splash: {image: string; backgroundColor: string};
        android: {splash: {image: string; backgroundColor: string}};
      };
    };

    expect(androidSplash).toContain('@mipmap/ic_launcher_foreground');
    expect(iosSplash).toContain('text="ROKN"');
    expect(iosSplash).toContain('text="دقيقة بدقيقة"');
    expect(`${androidSplash}\n${iosSplash}`).not.toMatch(
      /تعلّم بمقاطع|مشروعات|Rokn AI|ابدأ الآن/,
    );
    expect(appConfig.expo.splash).toEqual({
      image: './src/assets/images/logo.png',
      resizeMode: 'contain',
      backgroundColor: '#080B12',
    });
    expect(appConfig.expo.android.splash).toEqual(appConfig.expo.splash);
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
    expect(initializer).toContain('<Navigation />');
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

    expect(settings).toContain('settingsScopeWriteTails');
    expect(settings).toContain('withSettingsScopeWrite');
    expect(settings).toContain('preferenceRevisionRef');
    expect(settings).toContain("isUnchanged('VIDEO_QUALITY')");
    expect(settings).toContain('enqueuePreferenceWrite');
    expect(settings).toContain(
      'const boundaryFlight = captureAccountSessionBoundary()',
    );
  });
});
