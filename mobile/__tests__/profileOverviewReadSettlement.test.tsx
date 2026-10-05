import React from 'react';
import {Text, View} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockFocused = true;
let mockBoundary = {scope: 'user-7', epoch: 1};
let mockSessionAvailable = true;
let mockUser = {
  api_token: 'token-7',
  user: {id: 7, name: 'الاسم المحفوظ', profile_revision: 1},
};
const mockGetProfile = jest.fn();
const mockGetPortfolio = jest.fn();
const mockShare = jest.fn();

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    require('react').useEffect(
      () => (mockFocused ? effect() : undefined),
      [effect, mockFocused],
    );
  },
}));
jest.mock('react-redux', () => ({
  useSelector: (select: (state: unknown) => unknown) =>
    select({auth: {userData: mockUser}}),
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  extractApiToken: (value: typeof mockUser) => value.api_token,
  extractUserProfile: (value: typeof mockUser) => value.user,
  sessionIdentityKey: (value: typeof mockUser) => `user-${value.user.id}`,
}));
jest.mock('../src/services/roknApi', () => ({
  getProfile: (...args: unknown[]) => mockGetProfile(...args),
  getPortfolioProfile: (...args: unknown[]) => mockGetPortfolio(...args),
  hasSession: async () => mockSessionAvailable,
}));
jest.mock('../src/services/systemActions', () => ({
  shareOnce: (...args: unknown[]) => mockShare(...args),
}));

import {useProfileOverview} from '../src/screens/Profile/useProfileOverview';
import type {PortfolioProfile, Profile} from '../src/services/roknApi';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((accept, fail) => {
    resolve = accept;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const profile = (name = 'الاسم الجديد', profileRevision = 2): Profile => ({
  id: String(mockUser.user.id),
  name,
  avatar: `https://rokn.app/${name}.jpg`,
  email: '',
  jobTitle: '',
  portfolioHeadline: 'مصمم منتجات',
  profileRevision,
  watchHistoryEnabled: true,
  marketingNotificationsEnabled: false,
  videoQualityPreference: 'auto',
  playbackSpeed: 1,
  learningReminderHour: 20,
  learningReminderTimezone: 'Africa/Cairo',
});
const portfolio = (
  status: PortfolioProfile['sharingStatus'] = 'approved',
): PortfolioProfile => ({
  slug: 'rokn-aaaaaaaaaaaaaaaaaaaaaaaa',
  headline: '',
  location: '',
  skills: [],
  publicUrl:
    status === 'approved'
      ? 'https://rokn.app/@rokn-aaaaaaaaaaaaaaaaaaaaaaaa'
      : '',
  shareMode: 'unlisted',
  sharingSuspended: status === 'suspended',
  sharingStatus: status,
  sharingRevision: 1,
  sharingRejectionReason: '',
});

describe('profile identity and reviewed portfolio settle independently', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  let overview!: ReturnType<typeof useProfileOverview>;
  const Harness = () => {
    overview = useProfileOverview();
    return (
      <View>
        <Text testID="name">{overview.displayName}</Text>
        <Text testID="avatar">{overview.avatarUri}</Text>
        <Text testID="certificate-holder">
          {overview.certificateHolderName}
        </Text>
        <Text testID="error">{overview.profileError}</Text>
        <Text testID="share-url">{overview.publicPortfolioUrl}</Text>
      </View>
    );
  };
  const rendered = (testID: string) =>
    renderer.root.findByProps({testID}).props.children;
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  beforeEach(() => {
    mockFocused = true;
    mockSessionAvailable = true;
    mockBoundary = {scope: 'user-7', epoch: 1};
    mockUser = {
      api_token: 'token-7',
      user: {id: 7, name: 'الاسم المحفوظ', profile_revision: 1},
    };
    mockGetProfile.mockReset().mockImplementation(async () => profile());
    mockGetPortfolio.mockReset().mockImplementation(async () => portfolio());
    mockShare.mockReset().mockResolvedValue(undefined);
  });
  afterEach(async () => {
    await act(async () => renderer.unmount());
  });

  it('renders fresh name, avatar and certificate identity while sharing status is still pending', async () => {
    const sharing = deferred<PortfolioProfile>();
    mockGetPortfolio.mockReturnValueOnce(sharing.promise);
    await mount();
    expect(rendered('name')).toBe('الاسم الجديد');
    expect(rendered('avatar')).toBe(profile().avatar);
    expect(rendered('certificate-holder')).toBe('الاسم الجديد');
    expect(rendered('error')).toBe('');
    expect(rendered('share-url')).toBe('');
    expect(overview.canSharePortfolio).toBe(false);
    await act(async () => sharing.resolve(portfolio()));
    expect(rendered('share-url')).toBe(portfolio().publicUrl);
    expect(rendered('name')).toBe('الاسم الجديد');
  });

  it('accepts reviewed sharing independently while the account profile is pending, without inventing permission from a slug', async () => {
    const identity = deferred<Profile>();
    mockGetProfile.mockImplementation(() => identity.promise);
    await mount();
    expect(rendered('name')).toBe('الاسم المحفوظ');
    expect(rendered('share-url')).toBe(portfolio().publicUrl);
    await act(async () => overview.setHasShareablePortfolio(true));
    expect(overview.canSharePortfolio).toBe(true);
    await act(async () => overview.sharePortfolio());
    expect(mockShare).toHaveBeenCalledTimes(1);
    await act(async () => identity.resolve(profile()));
    expect(rendered('name')).toBe('الاسم الجديد');
  });

  it('shows an identity failure immediately and does not let later portfolio success erase it', async () => {
    const sharing = deferred<PortfolioProfile>();
    mockGetProfile.mockRejectedValueOnce(new Error('identity-offline'));
    mockGetPortfolio.mockReturnValueOnce(sharing.promise);
    await mount();
    expect(rendered('error')).toBe('تعذّر تحديث بعض بيانات الحساب');
    expect(rendered('name')).toBe('الاسم المحفوظ');
    await act(async () => sharing.resolve(portfolio()));
    expect(rendered('error')).toBe('تعذّر تحديث بعض بيانات الحساب');
    expect(rendered('share-url')).toBe(portfolio().publicUrl);
  });

  it('shows a sharing read failure immediately and keeps it after the independent identity succeeds', async () => {
    const identity = deferred<Profile>();
    mockGetProfile.mockReturnValueOnce(identity.promise);
    mockGetPortfolio.mockRejectedValueOnce(new Error('portfolio-offline'));
    await mount();
    expect(rendered('error')).toBe('تعذّر تحديث بعض بيانات الحساب');
    expect(overview.canSharePortfolio).toBe(false);
    await act(async () => identity.resolve(profile()));
    expect(rendered('name')).toBe('الاسم الجديد');
    expect(rendered('error')).toBe('تعذّر تحديث بعض بيانات الحساب');
    await act(async () => overview.retry());
    expect(rendered('error')).toBe('');
  });

  it('a successful sharing recheck does not clear the independent account read error', async () => {
    mockGetProfile.mockRejectedValue(new Error('identity-offline'));
    await mount();
    await act(async () => overview.setHasShareablePortfolio(true));
    expect(overview.canSharePortfolio).toBe(true);
    expect(rendered('error')).toBe('تعذّر تحديث بعض بيانات الحساب');
    await act(async () => {
      expect(await overview.refreshPortfolioShareUrl()).toBe(
        portfolio().publicUrl,
      );
    });
    expect(rendered('error')).toBe('تعذّر تحديث بعض بيانات الحساب');
    expect(rendered('share-url')).toBe(portfolio().publicUrl);
    expect(overview.canSharePortfolio).toBe(true);
  });

  it('a retry retires both old results without waiting for either old endpoint', async () => {
    const identity = deferred<Profile>();
    const sharing = deferred<PortfolioProfile>();
    mockGetProfile.mockReturnValueOnce(identity.promise);
    mockGetPortfolio.mockReturnValueOnce(sharing.promise);
    await mount();
    mockGetProfile.mockResolvedValueOnce(profile('بعد المحاولة', 3));
    mockGetPortfolio.mockResolvedValueOnce(portfolio('suspended'));
    await act(async () => overview.retry());
    expect(rendered('name')).toBe('بعد المحاولة');
    expect(overview.portfolioSharingSuspended).toBe(true);
    await act(async () => {
      identity.resolve(profile('رد قديم', 2));
      sharing.resolve(portfolio());
    });
    expect(rendered('name')).toBe('بعد المحاولة');
    expect(rendered('share-url')).toBe('');
    expect(overview.portfolioSharingSuspended).toBe(true);
    expect(rendered('error')).toBe('');
  });

  it('gallery invalidation retires an old approval while the identity remains available', async () => {
    const oldSharing = deferred<PortfolioProfile>();
    const reviewed = deferred<PortfolioProfile>();
    mockGetPortfolio
      .mockReturnValueOnce(oldSharing.promise)
      .mockReturnValueOnce(reviewed.promise);
    await mount();
    await act(async () => overview.setHasShareablePortfolio(true));
    await act(async () => oldSharing.resolve(portfolio()));
    expect(rendered('name')).toBe('الاسم الجديد');
    expect(rendered('share-url')).toBe('');
    expect(overview.canSharePortfolio).toBe(false);
    await act(async () => reviewed.resolve(portfolio('pending')));
    expect(overview.portfolioSharingNotice).toBe(
      'أعمالك قيد المراجعة\nسيظهر رابط المشاركة بعد الموافقة',
    );
    expect(overview.canSharePortfolio).toBe(false);
  });

  it('late results cannot replace another account identity or authorize its portfolio', async () => {
    const oldIdentity = deferred<Profile>();
    const oldSharing = deferred<PortfolioProfile>();
    mockGetProfile.mockReturnValueOnce(oldIdentity.promise);
    mockGetPortfolio.mockReturnValueOnce(oldSharing.promise);
    await mount();
    mockBoundary = {scope: 'user-8', epoch: 2};
    mockUser = {
      api_token: 'token-8',
      user: {id: 8, name: 'الحساب الثاني', profile_revision: 1},
    };
    mockGetProfile.mockResolvedValueOnce(profile('الحساب الثاني', 2));
    mockGetPortfolio.mockResolvedValueOnce(portfolio('suspended'));
    await act(async () => renderer.update(<Harness />));
    await act(async () => {
      oldIdentity.resolve(profile('الحساب الأول', 4));
      oldSharing.resolve(portfolio());
    });
    expect(rendered('name')).toBe('الحساب الثاني');
    expect(rendered('share-url')).toBe('');
    expect(overview.portfolioSharingSuspended).toBe(true);
    expect(rendered('error')).toBe('');
  });

  it('a late failure after blur does not surface and a fresh focus owns new reads', async () => {
    const oldIdentity = deferred<Profile>();
    const oldSharing = deferred<PortfolioProfile>();
    mockGetProfile.mockReturnValueOnce(oldIdentity.promise);
    mockGetPortfolio.mockReturnValueOnce(oldSharing.promise);
    await mount();
    mockFocused = false;
    await act(async () => renderer.update(<Harness />));
    await act(async () => {
      oldIdentity.reject(new Error('old-identity'));
      oldSharing.reject(new Error('old-portfolio'));
    });
    expect(rendered('error')).toBe('');
    mockFocused = true;
    await act(async () => renderer.update(<Harness />));
    expect(rendered('name')).toBe('الاسم الجديد');
    expect(rendered('share-url')).toBe(portfolio().publicUrl);
  });

  it('does not request either private endpoint for a guest session', async () => {
    mockUser.api_token = '';
    mockSessionAvailable = false;
    await mount();
    expect(mockGetProfile).not.toHaveBeenCalled();
    expect(mockGetPortfolio).not.toHaveBeenCalled();
    expect(rendered('name')).toBe('ضيف ركن');
    expect(overview.canSharePortfolio).toBe(false);
  });
});
