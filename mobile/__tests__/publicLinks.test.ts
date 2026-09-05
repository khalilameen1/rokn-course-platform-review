import {roknApiUrl} from '../src/constants/apiBaseUrl';
import {
  trustedCertificateFileUrl,
  trustedCertificateVerificationUrl,
  trustedPortfolioShareUrl,
} from '../src/services/publicLinks';

describe('public share link trust boundary', () => {
  const deployment = new URL(roknApiUrl).origin;
  const slug = 'rokn-aaaaaaaaaaaaaaaaaaaaaaaa';
  const credential = '11111111-1111-4111-8111-111111111111';

  it('accepts canonical and configured API-origin portfolio links', () => {
    expect(trustedPortfolioShareUrl(`https://rokn.app/@${slug}`)).toBeTruthy();
    expect(trustedPortfolioShareUrl(`${deployment}/@${slug}`)).toBeTruthy();
  });

  it('keeps lookalike hosts and malformed capabilities out', () => {
    expect(
      trustedPortfolioShareUrl(`https://rokn.app.evil.example/@${slug}`),
    ).toBeNull();
    expect(trustedPortfolioShareUrl(`${deployment}/@student-6`)).toBeNull();
    expect(
      trustedPortfolioShareUrl(`https://unconfigured-rokn.laravel.cloud/@${slug}`),
    ).toBeNull();
  });

  it('uses the same deployment trust boundary for certificate verification', () => {
    expect(
      trustedCertificateVerificationUrl(
        `${deployment}/c/${credential}`,
        credential,
      ),
    ).toBeTruthy();
    expect(
      trustedCertificateVerificationUrl(
        `https://example.com/c/${credential}`,
        credential,
      ),
    ).toBeNull();
    expect(
      trustedCertificateVerificationUrl(
        `https://rokn.app/c/${credential}?download=1`,
        credential,
      ),
    ).toBeNull();
    expect(
      trustedCertificateVerificationUrl(
        'https://rokn.app/c/not-a-credential',
        'not-a-credential',
      ),
    ).toBeNull();
  });

  it('accepts only the matching credential artifact and PDF routes', () => {
    expect(
      trustedCertificateFileUrl(
        `${deployment}/c/${credential}/artifact`,
        credential,
        'artifact',
      ),
    ).toBeTruthy();
    expect(
      trustedCertificateFileUrl(
        `https://rokn.app/c/${credential}/download`,
        credential,
        'download',
      ),
    ).toBeTruthy();
    expect(
      trustedCertificateFileUrl(
        `https://example.com/c/${credential}/download`,
        credential,
        'download',
      ),
    ).toBeNull();
    expect(
      trustedCertificateFileUrl(
        `${deployment}/c/${credential}/artifact`,
        credential,
        'download',
      ),
    ).toBeNull();
  });
});
