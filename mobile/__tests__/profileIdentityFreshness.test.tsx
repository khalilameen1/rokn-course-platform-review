import React from 'react';
import {Text, View} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockStoredUser: Record<string, unknown> = {};
const mockGetProfile = jest.fn();
const mockGetPortfolioProfile = jest.fn();

jest.mock('@react-navigation/native', () => {
  const ReactModule = require('react');
  return {
    useFocusEffect: (effect: () => void | (() => void)) =>
      ReactModule.useEffect(effect, [effect]),
  };
});
jest.mock('react-redux', () => ({
  useSelector: (selector: (state: unknown) => unknown) =>
    selector({auth: {userData: mockStoredUser}}),
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'user-seven',
  })),
  extractApiToken: (value: Record<string, unknown>) => value.api_token,
  extractUserProfile: (value: Record<string, unknown>) => value.user || {},
  sessionIdentityKey: () => 'account-seven',
}));
jest.mock('../src/services/roknApi', () => ({
  getPortfolioProfile: (...args: unknown[]) => mockGetPortfolioProfile(...args),
  getProfile: (...args: unknown[]) => mockGetProfile(...args),
  hasSession: jest.fn(async () => true),
}));
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
  shareOnce: jest.fn(),
}));

import {useProfileOverview} from '../src/screens/Profile/useProfileOverview';

let currentProfile!: ReturnType<typeof useProfileOverview>;
const Harness = () => {
  const profile = useProfileOverview();
  currentProfile = profile;
  return (
    <View>
      <Text testID="profile-name">{profile.displayName}</Text>
      <Text testID="profile-avatar">{profile.avatarUri}</Text>
      <Text testID="certificate-name">{profile.certificateHolderName}</Text>
      <Text testID="profile-headline">{profile.role}</Text>
    </View>
  );
};

const text = (renderer: TestRenderer.ReactTestRenderer, testID: string) =>
  String(renderer.root.findByProps({testID}).props.children);

describe('profile identity freshness', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGetPortfolioProfile
      .mockReset()
      .mockResolvedValue({publicUrl: '', slug: ''});
  });

  it.each(['saved session', 'fresh profile response'])(
    'preserves an intentionally cleared headline from the %s instead of resurrecting the old portfolio value',
    async source => {
      mockStoredUser = {
        api_token: 'token-one',
        user: {
          id: 7,
          name: 'الاسم',
          portfolio_headline: 'مصمم سابق',
          profile_revision: 2,
        },
      };
      const oldProfile = {
        id: '7',
        name: 'الاسم',
        portfolioHeadline: 'مصمم سابق',
        profileRevision: 2,
      };
      mockGetProfile.mockResolvedValue(oldProfile);
      mockGetPortfolioProfile.mockResolvedValue({
        headline: 'مصمم سابق',
        publicUrl: '',
        slug: '',
      });
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      try {
        expect(text(renderer, 'profile-headline')).toBe('مصمم سابق');
        if (source === 'saved session') {
          mockStoredUser = {
            api_token: 'token-one',
            user: {
              id: 7,
              name: 'الاسم',
              portfolio_headline: '',
              profile_revision: 3,
            },
          };
          await act(async () => renderer.update(<Harness />));
        } else {
          mockGetProfile.mockResolvedValue({
            ...oldProfile,
            portfolioHeadline: '',
            profileRevision: 3,
          });
          mockGetPortfolioProfile.mockRejectedValue(new Error('offline'));
          await act(async () => currentProfile.retry());
          expect(currentProfile.profileError).not.toBe('');
        }
        expect(text(renderer, 'profile-headline')).toBe('');
        expect(text(renderer, 'profile-name')).toBe('الاسم');
        expect(text(renderer, 'certificate-name')).toBe('الاسم');
        mockGetProfile.mockResolvedValue({
          ...oldProfile,
          portfolioHeadline: '',
          profileRevision: 3,
        });
        mockGetPortfolioProfile.mockResolvedValue({
          headline: '',
          publicUrl: '',
          slug: '',
        });
        await act(async () => currentProfile.retry());
        expect(text(renderer, 'profile-headline')).toBe('');
        expect(currentProfile.profileError).toBe('');
      } finally {
        await act(async () => renderer.unmount());
      }
    },
  );

  it('can use the portfolio headline when no authoritative account profile was loaded', async () => {
    mockStoredUser = {api_token: 'token-one', user: {id: 7, name: 'الاسم'}};
    mockGetProfile.mockRejectedValue(new Error('offline'));
    mockGetPortfolioProfile.mockResolvedValue({
      headline: 'مصمم منتجات',
      publicUrl: '',
      slug: '',
    });
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    expect(text(renderer, 'profile-headline')).toBe('مصمم منتجات');
    await act(async () => renderer.unmount());
  });

  it('shows a just-saved session identity instead of an older in-memory profile read', async () => {
    mockStoredUser = {
      api_token: 'token-one',
      user: {
        avatar: 'https://cdn.example.test/old.jpg',
        id: 7,
        name: 'الاسم القديم',
        profile_revision: 2,
      },
    };
    mockGetProfile.mockResolvedValue({
      avatar: 'https://cdn.example.test/old.jpg',
      email: 'learner@example.test',
      id: '7',
      jobTitle: '',
      marketingNotificationsEnabled: false,
      name: 'الاسم القديم',
      playbackSpeed: 1,
      portfolioHeadline: '',
      profileRevision: 2,
      videoQualityPreference: 'auto',
      watchHistoryEnabled: true,
    });

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    expect(text(renderer, 'profile-name')).toBe('الاسم القديم');

    mockStoredUser = {
      ...mockStoredUser,
      user: {
        avatar: 'https://cdn.example.test/new.jpg',
        id: 7,
        name: 'الاسم الجديد',
        profile_revision: 3,
      },
    };
    await act(async () => renderer.update(<Harness />));

    expect(text(renderer, 'profile-name')).toBe('الاسم الجديد');
    expect(text(renderer, 'profile-avatar')).toBe(
      'https://cdn.example.test/new.jpg',
    );
    expect(text(renderer, 'certificate-name')).toBe('الاسم الجديد');
    await act(async () => renderer.unmount());
  });
});
