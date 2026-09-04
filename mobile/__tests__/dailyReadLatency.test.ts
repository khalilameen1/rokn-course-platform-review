import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('daily read latency budget', () => {
  it('keeps automatic GET recovery below three seconds of backoff', () => {
    const api = source('src/constants/api.ts');
    const requestPolicy = source('src/constants/apiRequestPolicy.ts');
    expect(api).toContain(
      'READ_RECOVERY_DELAYS_MS = [300, 700, 1_500] as const',
    );
    expect(requestPolicy).toContain('DEFAULT_READ_RECOVERY_BUDGET_MS = 12_000');
    expect(api).not.toContain('4_500, 5_000');
  });

  it('bounds the learning entitlement overlay instead of blocking home indefinitely', () => {
    const learningCourses = source('src/services/api/learningCourses.ts');
    const homeCatalogue = source('src/screens/home/useHomeCatalogue.ts');
    const publicCatalogue = source(
      'src/screens/home/usePublishedCourseCatalogue.ts',
    );
    const accessOverlay = source('src/screens/home/useCourseAccessOverlay.ts');

    expect(learningCourses).toContain('signal: options.signal');
    expect(publicCatalogue).toContain('signal: controller.signal');
    expect(accessOverlay).toContain('signal: controller.signal');
    expect(publicCatalogue).not.toContain('hasSession');
    expect(homeCatalogue).toContain('usePublishedCourseCatalogue({');
    expect(homeCatalogue).toContain('useCourseAccessOverlay({');
  });

  it('does not hide writes inside automatic screen refreshes', () => {
    const home = source('src/screens/Home.tsx');
    const homeEngagement = source('src/screens/home/useHomeEngagement.ts');
    const wallet = source('src/screens/wallet/useWalletData.ts');

    expect(homeEngagement).toContain(
      '.then(boundary => claimDailyReward(boundary))',
    );
    expect(home).not.toContain('const retryDelays = [5_000, 20_000, 60_000]');
    expect(wallet).not.toContain('claimDailyReward');
    expect(wallet).toContain('const walletRequest = getWallet().then(');
  });

  it('does not let best-effort device cache work own a daily screen spinner', () => {
    const home = source('src/screens/home/usePublishedCourseCatalogue.ts');
    const courseDetails = source('src/services/api/courseDetails.ts');
    const wallet = source('src/screens/wallet/useWalletData.ts');
    const portfolio = source(
      'src/screens/Profile/gallery/usePortfolioLibrary.ts',
    );
    const certificates = source(
      'src/screens/Profile/certificates/useCertificatesController.ts',
    );

    expect(home).toContain('settleWithin(getCachedPublishedCourses(), [])');
    expect(courseDetails).toContain('await settleWithin(');
    expect(wallet).toContain('settleWithin(readWalletCache(boundary), null)');
    expect(portfolio).toContain(
      'settleWithin(getCachedPortfolio(boundary), [])',
    );
    expect(certificates).toContain(
      'settleWithin(\n          getCachedCertificates(boundary),',
    );
  });
});
