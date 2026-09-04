import {
  portfolioMediaFailureDisposition,
  usablePortfolioMediaUrl,
} from '../src/services/portfolioMediaPolicy';

describe('portfolio media cache policy', () => {
  it('does not render an expired signed URL from account cache', () => {
    expect(
      usablePortfolioMediaUrl(
        'https://cdn.example/private-image.jpg?token=expired',
        '2000-01-01T00:00:00.000Z',
      ),
    ).toBeUndefined();
  });

  it('keeps a fresh signed URL and a permanent URL', () => {
    const fresh = new Date(Date.now() + 60_000).toISOString();
    expect(usablePortfolioMediaUrl('https://cdn.example/image.jpg', fresh)).toBe(
      'https://cdn.example/image.jpg',
    );
    expect(usablePortfolioMediaUrl('https://rokn.app/media/image.jpg')).toBe(
      'https://rokn.app/media/image.jpg',
    );
  });

  it('drops one permanently rejected file without trapping later uploads', () => {
    expect(portfolioMediaFailureDisposition(422)).toBe('discard_file');
    expect(portfolioMediaFailureDisposition(404)).toBe('discard_project');
    expect(portfolioMediaFailureDisposition(503)).toBe('retry_project');
  });
});
