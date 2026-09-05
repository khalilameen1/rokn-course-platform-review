import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('production daily surfaces', () => {
  it('does not substitute local fixtures for account-owned data', () => {
    for (const file of [
      'src/screens/MyCorner.tsx',
      'src/screens/Profile/SavedVideos.tsx',
      'src/screens/Wallet.tsx',
    ]) {
      expect(source(file)).not.toMatch(
        /LOCAL_DEMO_ENABLED|demoExperience|createDemoCourse|DEMO_/,
      );
    }
    const saved = source('src/screens/Profile/saved/useSavedLibrary.ts');
    expect(saved).toContain('captureAccountSessionBoundary()');
    expect(saved).toContain('assertAccountSessionBoundary(boundary)');
    expect(saved).toContain('dataOwnerRef.current !== identityKey');
  });

  it('keeps chat and checkout on server-owned production paths', () => {
    for (const file of [
      'src/components/VideoPlayer/courseChat/useCourseChat.ts',
      'src/components/VideoPlayer/courseLearning/assistant.ts',
      'src/components/VideoPlayer/courseLearning/mapping.ts',
      'src/components/VideoPlayer/courseEntitlements.ts',
      'src/services/coinCheckout.ts',
      'src/services/nativeStoreBilling.ts',
    ]) {
      expect(source(file)).not.toMatch(
        /LOCAL_DEMO_ENABLED|isLocalDemoId|demoExperience|DEMO_/,
      );
    }
    const access = source('src/services/api/coursePurchase.ts');
    expect(access).not.toMatch(/remaining_balance|current_coins/);
    expect(access).not.toContain("accessPlanCode || 'default'");
    expect(access).toContain('access_plan_code: normalizedPlanCode');
  });

  it('does not let local flags grant variable-cost entitlements', () => {
    const entitlements = source(
      'src/components/VideoPlayer/courseEntitlements.ts',
    );
    expect(entitlements).not.toContain('isDemo');
    expect(entitlements).toContain('chatAvailable === true');
  });

  it('wires foreground catalogue reads separately from interactive carousel playback', () => {
    const home = source('src/screens/Home.tsx');
    const feed = source('src/screens/home/HomeCatalogueFeed.tsx');
    const carousel = source('src/components/view/CourseCarousel.tsx');

    // This is a wiring contract, not native acceptance: a modal blur must
    // pause the carousel without making catalogue reads leave foreground.
    expect(home).toContain('const appIsActive = useAppForegroundState()');
    expect(home).toContain('const appIsInteractive = useAppActiveState()');
    expect(home).toMatch(
      /useHomeCatalogue\(\{\s*active: screenFocused,\s*appIsActive,/,
    );
    expect(home).toMatch(
      /<HomeCatalogueFeed\s+active=\{screenFocused && appIsInteractive\}/,
    );
    expect(feed).toMatch(/<CourseCarousel\s+active=\{active\}/);
    expect(carousel).toContain('autoPlay={active && data.length > 1}');
  });

  it('bounds daily list image cost and coalesces wallet refreshes', () => {
    const notifications = source('src/screens/Notifications.tsx');
    const wallet = source('src/screens/wallet/useWalletData.ts');

    expect(notifications).toContain('resizeMethod="resize"');
    expect(notifications).toContain('removeClippedSubviews=');
    expect(wallet).toContain('refreshFlightRef.current');
    expect(wallet).toContain('refreshAfterCurrent');
  });
});
