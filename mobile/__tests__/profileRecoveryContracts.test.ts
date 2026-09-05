import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('profile recovery contracts', () => {
  it('does not turn a saved-folder request failure into an authoritative empty list', () => {
    const savedLibrary = source('src/screens/Profile/saved/useSavedLibrary.ts');

    expect(savedLibrary).not.toContain(
      'getSavedFolderOptions().catch(() => [])',
    );
    expect(savedLibrary).toContain('if (folderResult.ok)');
    expect(savedLibrary).toContain('setFolderLoadError(');
  });

  it('keeps a pending certificate recoverable instead of presenting an empty account', () => {
    const certificates = source(
      'src/screens/Profile/certificates/useCertificatesController.ts',
    );
    const certificateView = source('src/screens/Profile/Certificates.tsx');

    expect(certificates).toContain(
      'setCertificatePending(hasPendingCertificate)',
    );
    expect(certificateView).toContain('certificatePending &&');
    expect(certificateView).toContain('!readyCourses.length &&');
    expect(certificateView).toContain('!grantCourses.length ?');
    expect(certificates).toContain('recoverPendingCertificates');
    expect(certificates).toContain('recoverCertificate(courseId, boundary)');
    expect(certificates).toContain(
      'await recoverCertificate(certificate.courseId, boundary)',
    );
    expect(certificateView).toContain(
      '? void retryPendingCertificate(certificate)',
    );
    expect(certificates).toMatch(
      /const timer = setTimeout\(\(\) => \{[\s\S]*?void loadCertificates\(\);[\s\S]*?\}, delayMs\);/,
    );
    expect(certificates).not.toMatch(
      /const timer = setTimeout\(\(\) => \{[\s\S]*?void recoverPendingCertificates\(\);[\s\S]*?\}, delayMs\);/,
    );
  });

  it('hydrates portfolio data independently and replays media as one flight', () => {
    const gallery = source(
      'src/screens/Profile/gallery/usePortfolioGalleryController.ts',
    );
    const replay = source(
      'src/screens/Profile/gallery/usePortfolioMediaReplay.ts',
    );
    const initializer = source('src/screens/appInitializer/useAppRuntime.ts');

    expect(gallery).not.toContain(
      'for (const entry of await listPortfolioMediaUploads())',
    );
    expect(replay).toContain('refreshFlightRef.current');
    expect(replay).toContain('await replayPendingPortfolioMediaUploads()');
    expect(initializer).toContain('replayPendingPortfolioMediaUploads()');
    expect(source('src/services/portfolioMediaReplay.ts')).toContain(
      'deliverPortfolioMedia(entry, boundary)',
    );
    expect(source('src/services/portfolioMediaUpload.ts')).toContain(
      'deliverPortfolioMedia(entry, boundary)',
    );
  });

  it('keeps the share action available when one profile request is stale', () => {
    const profile = source('src/screens/Profile/index.tsx');
    const overview = source('src/screens/Profile/useProfileOverview.ts');

    expect(overview).toContain('visibleRemoteProfile?.portfolioSlug');
    expect(overview).toContain(
      "trustedPortfolioShareUrl(username ? portfolioUrlFor(username) : '')",
    );
    expect(overview).toContain(
      'becameShareable && !publicPortfolioUrlRef.current',
    );
    expect(profile).toContain("activeTab === 'portfolio' && canSharePortfolio");
    expect(profile).toContain('visible={showPortfolioQr && showPortfolioActions}');
    expect(profile).toContain('value={publicPortfolioUrl}');
    expect(profile).toContain(
      'onSharePortfolio={canSharePortfolio ? sharePortfolio : undefined}',
    );
  });

  it('owns edit, finalize and media-delete mutations synchronously', () => {
    const details = source(
      'src/screens/Profile/gallery/usePortfolioProjectDetails.ts',
    );

    expect(details).toContain('mutationFlightRef.current = flight');
    expect(details).not.toContain('mediaFlightRef');
    expect(details).not.toContain('deleteFlightRef');
    expect(details.match(/beginMutation\((?:false)?\)/g)).toHaveLength(5);
    expect(details).toContain('{cancelable: true, onDismiss: release}');
    expect(details).toContain('if (!deleteStarted) finishMutation(flight)');
    expect(details).toContain(
      'projectId === openProjectId ? openProjectGeneration : undefined',
    );
    expect(details).toContain(
      'const pending = await listPortfolioMediaUploads(projectId, boundary)',
    );
  });
});
