import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('daily read latency budget', () => {
  it('keeps automatic GET recovery below three seconds of backoff', () => {
    const api = source('src/constants/api.ts');
    const requestPolicy = source('src/constants/apiRequestPolicy.ts');
    expect(api).toContain('READ_RETRY_LIMIT = 2;');
    expect(api).toContain('READ_RETRY_BASE_DELAY_MS = 300;');
    // Full-jitter windows for the two retries: [0, 300) + [0, 600).
    const {READ_RETRY_LIMIT, readRetryDelayMs} = jest.requireActual(
      '../src/constants/api',
    ) as typeof import('../src/constants/api');
    let worstCase = 0;
    for (let attempt = 0; attempt < READ_RETRY_LIMIT; attempt += 1) {
      worstCase += readRetryDelayMs(attempt, () => 1);
    }
    expect(worstCase).toBeLessThan(3_000);
    expect(requestPolicy).toContain('DEFAULT_READ_RECOVERY_BUDGET_MS = 12_000');
    expect(api).not.toContain('4_500, 5_000');
  });

  it('bounds the learning entitlement overlay instead of blocking home indefinitely', () => {
    const learningCourses = source('src/services/api/learningCourses.ts');
    const homeCatalogue = source('src/screens/home/useHomeCatalogue.ts');
    const publicCatalogue = source(
      'src/screens/home/usePublishedCourseCatalogue.ts',
    );

    expect(learningCourses).toContain('signal: options.signal');
    // Abandoned catalogue reads are aborted: homeCatalogueQueryOwnership.
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
  });

  it('does not let best-effort device cache work own a daily screen spinner', () => {
    const certificates = source(
      'src/screens/Profile/certificates/useCertificatesController.ts',
    );

    // Catalogue, course details, dashboard, wallet, saved library and
    // portfolio reads start before the device snapshot settles
    // (queryPersistence restore budget): proven by
    // courseCatalogueUnavailableRecovery, myCornerStorageBoundary,
    // savedLibraryFocusLifecycle and portfolioLibraryQuery.
    expect(certificates).toMatch(
      /settleWithin\(\s*getCachedCertificates\(boundary\),\s*\[\]/,
    );
  });
});
