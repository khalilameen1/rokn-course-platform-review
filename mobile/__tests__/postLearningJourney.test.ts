import fs from 'fs';
import path from 'path';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

describe('post-learning journey contract', () => {
  it('keeps a passed project separate from an explicitly authored portfolio item', () => {
    const project = source('src/components/VideoPlayer/ProjectTransition.tsx');
    const createFlow = source(
      'src/screens/Profile/gallery/usePortfolioCreateFlow.ts',
    );

    expect(project).toContain(
      "navigation.navigate('Profile', {tab: 'portfolio'})",
    );
    expect(project).not.toContain('createPortfolioItem');
    expect(createFlow).toContain('setDraftMediaAssets([])');
    expect(createFlow).toContain('!draftMediaAssets.length');
    expect(createFlow).not.toContain('writeLocalPortfolioDrafts');
    expect(createFlow).not.toContain("source: 'local'");
  });

  it('withdraws an edited share until every new file is finalized', () => {
    const details = source(
      'src/screens/Profile/gallery/usePortfolioProjectDetails.ts',
    );
    const publication = source(
      'src/screens/Profile/gallery/usePortfolioPublication.ts',
    );
    const model = source('src/screens/Profile/gallery/portfolioModel.ts');

    expect(model).toContain('shareReady: false');
    expect(details).not.toContain('if (selected.shareReady)');
    expect(details).toMatch(
      /addSelectedMedia[\s\S]*?finalizeAfterUpload\([\s\S]*?publication === 'processing'/,
    );
    expect(publication).toContain('await finalizePortfolioItem(');
    expect(publication).toContain("return 'published'");
    expect(publication).toContain("if (result === 'processing')");
  });

  it('renders the immutable certificate wording and verification URL from the API', () => {
    const certificates = source('src/screens/Profile/Certificates.tsx');
    const officialPreview = source(
      'src/screens/Profile/certificates/CertificateArtifactPreview.tsx',
    );
    const api = source('src/services/api/certificates.ts');

    expect(api).toContain('certificate_text_template_key');
    expect(api).toContain('certificate_text');
    expect(api).toContain('CERTIFICATE_TEXT_CONTRACT_INVALID');
    expect(api).toContain('course_id');
    expect(api).not.toContain('certificate_id');
    expect(api).not.toContain('download_url');
    expect(api).not.toContain('portfolio_url');
    expect(api).not.toContain('share_url');
    expect(certificates).toContain('<QRCode value={activeCertificateLink}');
    expect(certificates).toContain(
      'certificateUrl={certificate.certificateUrl}',
    );
    expect(certificates).toContain(
      "pending={certificate.status === 'pending'}",
    );
    expect(certificates).not.toContain('certificateUrlFor');
    expect(certificates).not.toContain('أتم بنجاح كورس');
    expect(certificates).not.toContain('const CertificateArtwork');
    expect(officialPreview).not.toContain('<CertificateArtwork');
    expect(officialPreview).toContain('onLoad={() => setArtifactLoaded(true)}');
  });
});
