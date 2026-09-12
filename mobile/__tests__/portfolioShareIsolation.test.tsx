import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockShareOnce = jest.fn();
const mockGetPortfolioProfile = jest.fn();

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
    scope: 'account-a',
  })),
  extractApiToken: () => 'token-a',
  extractUserProfile: () => ({id: 7, name: 'سارة'}),
  sessionIdentityKey: () => 'account-a',
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
    mockShareOnce.mockResolvedValue(undefined);
    mockGetPortfolioProfile.mockReset().mockResolvedValue({
      slug: 'rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      headline: '',
      location: '',
      skills: [],
      publicUrl: 'https://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      shareMode: 'unlisted',
      sharingSuspended: false,
    });
  });

  it('does not reconstruct a suspended public URL and restores sharing only after a fresh status', async () => {
    mockGetPortfolioProfile.mockResolvedValue({
      slug: 'rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
      publicUrl: '',
      sharingSuspended: true,
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
});
