import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockShareOnce = jest.fn();
const mockGetPortfolioProfile = jest.fn();
let mockIdentity = 'account-a';

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    const ReactModule = require('react') as typeof React;
    ReactModule.useEffect(effect, [effect]);
  },
}));

jest.mock('react-redux', () => ({
  useSelector: () => ({api_token: 'token-a', user: {id: 7, name: 'سارة'}}),
}));

jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: mockIdentity,
  })),
  extractApiToken: () => 'token-a',
  extractUserProfile: () => ({id: 7, name: 'سارة'}),
  sessionIdentityKey: () => mockIdentity,
}));

jest.mock('../src/services/roknApi', () => ({
  getProfile: jest.fn(async () => ({name: 'سارة'})),
  getPortfolioProfile: (...args: unknown[]) => mockGetPortfolioProfile(...args),
  hasSession: jest.fn(async () => true),
}));

jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
  shareOnce: (...args: unknown[]) => mockShareOnce(...args),
}));

import {useProfileOverview} from '../src/screens/Profile/useProfileOverview';

describe('portfolio share isolation', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockIdentity = 'account-a';
    mockShareOnce.mockResolvedValue(undefined);
    mockGetPortfolioProfile.mockReset().mockResolvedValue({
      slug: 'rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      headline: '',
      location: '',
      skills: [],
      publicUrl: 'https://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      shareMode: 'unlisted',
      sharingSuspended: false,
      sharingStatus: 'approved',
    });
  });

  it('does not reconstruct a suspended public URL and restores sharing only after a fresh status', async () => {
    mockGetPortfolioProfile.mockResolvedValue({
      slug: 'rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      publicUrl: '',
      sharingSuspended: true,
      sharingStatus: 'suspended',
    });
    let overview!: ReturnType<typeof useProfileOverview>;
    const Harness = () => {
      overview = useProfileOverview();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      overview.setHasShareablePortfolio(true);
    });
    expect(overview.portfolioSharingSuspended).toBe(true);
    expect(overview.publicPortfolioUrl).toBe('');
    expect(overview.canSharePortfolio).toBe(false);
    await act(async () => {
      await overview.sharePortfolio();
    });
    expect(mockShareOnce).not.toHaveBeenCalled();
    mockGetPortfolioProfile.mockResolvedValue({
      slug: 'rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      publicUrl: 'https://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      sharingSuspended: false,
      sharingStatus: 'approved',
    });
    await act(async () => {
      overview.retry();
    });
    expect(overview.canSharePortfolio).toBe(true);
    await act(async () => renderer.unmount());
  });

  it('rechecks a suspension issued after the profile loaded before opening the share sheet', async () => {
    let overview!: ReturnType<typeof useProfileOverview>;
    const Harness = () => {
      overview = useProfileOverview();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      overview.setHasShareablePortfolio(true);
    });
    expect(overview.canSharePortfolio).toBe(true);
    mockGetPortfolioProfile.mockResolvedValue({
      sharingSuspended: true,
      sharingStatus: 'suspended',
      publicUrl: '',
    });
    await act(async () => {
      await overview.sharePortfolio();
    });
    expect(mockShareOnce).not.toHaveBeenCalled();
    expect(overview.portfolioSharingSuspended).toBe(true);
    expect(overview.canSharePortfolio).toBe(false);
    await act(async () => renderer.unmount());
  });

  it('shares the unlisted works URL without presenting certificates as part of it', async () => {
    let overview!: ReturnType<typeof useProfileOverview>;
    const Harness = () => {
      overview = useProfileOverview();
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await Promise.resolve();
      await Promise.resolve();
      await Promise.resolve();
    });
    await act(async () => {
      overview.setHasShareablePortfolio(true);
    });

    expect(overview.canSharePortfolio).toBe(true);
    await act(async () => {
      await overview.sharePortfolio();
    });

    expect(mockShareOnce).toHaveBeenCalledWith('portfolio', {
      title: 'بورتفوليو سارة على ركن',
      message:
        'شاهد أعمالي على ركن\nhttps://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      url: 'https://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
    });
    expect(mockShareOnce.mock.calls[0][1].message).not.toContain('شهاد');

    await act(async () => renderer.unmount());
  });

  it.each(['pending', 'rejected', 'unavailable', undefined])(
    'does not share or reconstruct a link for status %s',
    async sharingStatus => {
      mockGetPortfolioProfile.mockResolvedValue({
        slug: 'rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
        publicUrl: 'https://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
        sharingStatus,
        sharingRejectionReason:
          sharingStatus === 'rejected'
            ? 'أزل بيانات التواصل الخاصة بالآخرين'
            : '',
      });
      let overview!: ReturnType<typeof useProfileOverview>;
      const Harness = () => {
        overview = useProfileOverview();
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      await act(async () => {
        overview.setHasShareablePortfolio(true);
      });
      expect(overview.canSharePortfolio).toBe(false);
      expect(overview.publicPortfolioUrl).toBe('');
      if (sharingStatus === 'pending')
        expect(overview.portfolioSharingNotice).toContain('قيد المراجعة');
      if (sharingStatus === 'rejected')
        expect(overview.portfolioSharingNotice).toContain('أزل بيانات التواصل');
      await act(async () => {
        await overview.sharePortfolio();
      });
      expect(mockShareOnce).not.toHaveBeenCalled();
      await act(async () => renderer.unmount());
    },
  );

  it('withdraws a previous approval when a completed project is edited without changing the item count', async () => {
    let overview!: ReturnType<typeof useProfileOverview>;
    const Harness = () => {
      overview = useProfileOverview();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      overview.setHasShareablePortfolio(true);
    });
    expect(overview.canSharePortfolio).toBe(true);
    mockGetPortfolioProfile.mockResolvedValue({
      sharingStatus: 'pending',
      publicUrl: '',
    });
    await act(async () => {
      overview.setHasShareablePortfolio(true);
    });
    expect(overview.canSharePortfolio).toBe(false);
    expect(overview.portfolioSharingNotice).toContain('قيد المراجعة');
    expect(await overview.refreshPortfolioShareUrl()).toBeUndefined();
    await act(async () => renderer.unmount());
  });

  it('does not offer a cached QR URL when the status refresh fails', async () => {
    let overview!: ReturnType<typeof useProfileOverview>;
    const Harness = () => {
      overview = useProfileOverview();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      overview.setHasShareablePortfolio(true);
    });
    mockGetPortfolioProfile.mockRejectedValue(new Error('offline'));
    await act(async () => {
      expect(await overview.refreshPortfolioShareUrl()).toBeUndefined();
    });
    expect(overview.canSharePortfolio).toBe(false);
    expect(overview.publicPortfolioUrl).toBe('');
    expect(overview.profileError).toBe('تعذّر تحديث حالة المشاركة');
    await act(async () => renderer.unmount());
  });

  it('does not let a failed share check for the previous account erase the current approval', async () => {
    let overview!: ReturnType<typeof useProfileOverview>;
    const Harness = () => {
      overview = useProfileOverview();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      overview.setHasShareablePortfolio(true);
    });
    let rejectPrevious!: (error: Error) => void;
    mockGetPortfolioProfile.mockImplementationOnce(
      () =>
        new Promise((_, reject) => {
          rejectPrevious = reject;
        }),
    );
    let previous!: Promise<string | undefined>;
    await act(async () => {
      previous = overview.refreshPortfolioShareUrl();
    });
    mockIdentity = 'account-b';
    const currentUrl = 'https://rokn.app/@rokn-bbbbbbbbbbbbbbbbbbbbbbbb';
    mockGetPortfolioProfile.mockResolvedValue({
      sharingStatus: 'approved',
      publicUrl: currentUrl,
    });
    await act(async () => {
      renderer.update(<Harness />);
    });
    await act(async () => {
      overview.setHasShareablePortfolio(true);
    });
    expect(overview.canSharePortfolio).toBe(true);
    await act(async () => {
      rejectPrevious(new Error('ACCOUNT_CHANGED_DURING_REQUEST'));
      await previous;
    });
    expect(overview.publicPortfolioUrl).toBe(currentUrl);
    expect(overview.canSharePortfolio).toBe(true);
    expect(overview.profileError).toBe('');
    await act(async () => {
      await overview.sharePortfolio();
    });
    expect(mockShareOnce).toHaveBeenCalledWith(
      'portfolio',
      expect.objectContaining({url: currentUrl}),
    );
    await act(async () => renderer.unmount());
  });

  it('does not let a slower focus read restore approval after a newer sharing check returns pending', async () => {
    let overview!: ReturnType<typeof useProfileOverview>;
    const Harness = () => {
      overview = useProfileOverview();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => {
      overview.setHasShareablePortfolio(true);
    });
    let resolveFocus!: (value: unknown) => void;
    mockGetPortfolioProfile.mockImplementationOnce(
      () =>
        new Promise(resolve => {
          resolveFocus = resolve;
        }),
    );
    await act(async () => {
      overview.retry();
    });
    mockGetPortfolioProfile.mockResolvedValue({
      sharingStatus: 'pending',
      sharingRevision: 2,
      publicUrl: '',
    });
    await act(async () => {
      await overview.refreshPortfolioShareUrl();
    });
    expect(overview.portfolioSharingNotice).toContain('قيد المراجعة');
    await act(async () => {
      resolveFocus({
        sharingStatus: 'approved',
        sharingRevision: 1,
        publicUrl: 'https://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      });
    });
    expect(overview.canSharePortfolio).toBe(false);
    expect(overview.publicPortfolioUrl).toBe('');
    expect(overview.portfolioSharingNotice).toContain('قيد المراجعة');
    await act(async () => renderer.unmount());
  });
});
