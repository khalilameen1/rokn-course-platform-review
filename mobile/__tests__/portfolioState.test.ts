import {
  isShareablePortfolioItem,
  portfolioLifecycleAfterMediaRemoval,
  portfolioMediaSlots,
  portfolioNeedsPublicationRecovery,
  portfolioAction,
  portfolioPublicationDisposition,
} from '../src/screens/Profile/portfolioState';

describe('portfolio local lifecycle mirror', () => {
  it('retries publication only while uploaded media is genuinely processing', () => {
    expect(
      portfolioPublicationDisposition({
        media: [{id: '7', status: 'processing'}],
        shareReady: false,
      }),
    ).toBe('retry');
    expect(
      portfolioPublicationDisposition({
        media: [{id: '7', status: 'failed'}],
        shareReady: false,
      }),
    ).toBe('incomplete');
    expect(
      portfolioPublicationDisposition({
        media: [{id: '7', status: 'ready'}],
        shareReady: true,
      }),
    ).toBe('published');
  });

  it('shares only a completed item that contains uploaded work', () => {
    expect(
      isShareablePortfolioItem({
        media: [],
        uploadedMediaCount: 0,
        shareReady: true,
      }),
    ).toBe(false);
    expect(
      isShareablePortfolioItem({
        media: [],
        uploadedMediaCount: 1,
        shareReady: false,
      }),
    ).toBe(false);
    expect(
      isShareablePortfolioItem({
        media: [],
        uploadedMediaCount: 1,
        shareReady: true,
      }),
    ).toBe(true);
  });

  it('gives each lifecycle one unambiguous next action', () => {
    expect(
      portfolioAction({
        media: [],
        uploadedMediaCount: 0,
        shareReady: false,
      }),
    ).toBe('upload');
    expect(
      portfolioAction({
        media: [{id: 'one'}],
        uploadedMediaCount: 1,
        shareReady: false,
      }),
    ).toBe('complete');
    expect(
      portfolioAction({
        media: [],
        uploadedMediaCount: 1,
        shareReady: true,
      }),
    ).toBe('share');
  });

  it('recovers an uploaded server draft after the app closed before finalize', () => {
    expect(
      portfolioNeedsPublicationRecovery({
        media: [{id: 'ready', status: 'ready'}],
        uploadedMediaCount: 1,
        shareReady: false,
      }),
    ).toBe(true);
    expect(
      portfolioNeedsPublicationRecovery({
        media: [{id: 'published', status: 'ready'}],
        uploadedMediaCount: 1,
        shareReady: true,
      }),
    ).toBe(false);
  });

  it('uses the authoritative uploaded count when a summary contains one cover', () => {
    expect(
      portfolioMediaSlots({
        media: [{id: 'cover'}],
        uploadedMediaCount: 12,
      }),
    ).toBe(0);
  });

  it('stops sharing after the last expected file is removed', () => {
    expect(
      portfolioLifecycleAfterMediaRemoval(
        {
          media: [{id: 'last'}],
          uploadedMediaCount: 1,
          expectedMediaCount: 1,
          shareReady: true,
        },
        0,
      ),
    ).toEqual({uploadedMediaCount: 0, shareReady: false});

    expect(
      portfolioLifecycleAfterMediaRemoval(
        {
          media: [{id: 'legacy-last'}],
          uploadedMediaCount: 1,
          expectedMediaCount: 0,
          shareReady: true,
        },
        0,
      ),
    ).toEqual({uploadedMediaCount: 0, shareReady: false});
  });

  it('keeps a completed item ready while other server media remain', () => {
    expect(
      portfolioLifecycleAfterMediaRemoval(
        {
          media: [{id: 'cover'}],
          uploadedMediaCount: 5,
          expectedMediaCount: 5,
          shareReady: true,
        },
        0,
      ),
    ).toEqual({uploadedMediaCount: 4, shareReady: true});
  });
});
