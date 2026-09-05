import React from 'react';
import {Text, View} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockStoredUser: Record<string, unknown> = {};
const mockGetProfile = jest.fn();

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
  getPortfolioProfile: jest.fn(async () => ({
    publicUrl: '',
    slug: '',
  })),
  getProfile: (...args: unknown[]) => mockGetProfile(...args),
  hasSession: jest.fn(async () => true),
}));
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
  shareOnce: jest.fn(),
}));

import {useProfileOverview} from '../src/screens/Profile/useProfileOverview';

const Harness = () => {
  const profile = useProfileOverview();
  return (
    <View>
      <Text testID="profile-name">{profile.displayName}</Text>
      <Text testID="profile-avatar">{profile.avatarUri}</Text>
      <Text testID="certificate-name">{profile.certificateHolderName}</Text>
    </View>
  );
};

const text = (renderer: TestRenderer.ReactTestRenderer, testID: string) =>
  String(renderer.root.findByProps({testID}).props.children);

describe('profile identity freshness', () => {
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
