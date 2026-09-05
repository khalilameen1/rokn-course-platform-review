import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Image} from 'react-native';

const mockNavigate = jest.fn();
const mockOpenGuestLogin = jest.fn();
let mockAuthenticated = true;
let mockAvatarUri = 'https://cdn.example.test/avatar.jpg';

jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: mockNavigate}),
  useRoute: () => ({params: undefined}),
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: (...args: unknown[]) => mockOpenGuestLogin(...args),
}));
jest.mock('../src/screens/Profile/useProfileOverview', () => ({
  useProfileOverview: () => ({
    authenticatedIdentity: mockAuthenticated,
    avatarUri: mockAvatarUri,
    canSharePortfolio: false,
    certificateHolderName: 'اسم الطالب',
    displayName: 'اسم الطالب',
    identityKey: 'account-one',
    openPortfolio: jest.fn(),
    portfolioLinkLabel: '',
    profileError: '',
    publicPortfolioUrl: '',
    retry: jest.fn(),
    role: '',
    setHasShareablePortfolio: jest.fn(),
    sharePortfolio: jest.fn(),
  }),
}));
jest.mock('../src/components/containers/Containers', () => {
  const ReactModule = require('react');
  const {View} = require('react-native');
  const Wrapper = ({children}: {children?: React.ReactNode}) =>
    ReactModule.createElement(View, null, children);
  return {Container: Wrapper, Content: Wrapper};
});
jest.mock('../src/components/ui/PremiumUI', () => {
  const ReactModule = require('react');
  const {View} = require('react-native');
  const Wrapper = ({children}: {children?: React.ReactNode}) =>
    ReactModule.createElement(View, null, children);
  return {MetaPill: Wrapper, PremiumCard: Wrapper, ResponsiveFrame: Wrapper};
});
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/components/TabBar', () => () => null);
jest.mock('../src/screens/Profile/Gallery', () => () => null);
jest.mock('../src/screens/Profile/Certificates', () => () => null);
jest.mock('../src/screens/Profile/SavedVideos', () => () => null);
jest.mock('../src/components/ui/QRCode', () => () => null);
jest.mock('../src/assets/SVG', () => ({
  SettingsIcon: () => null,
  ShareProfileIcon: () => null,
}));

import Profile from '../src/screens/Profile';

describe('profile avatar editing entry', () => {
  beforeEach(() => {
    mockAuthenticated = true;
    mockAvatarUri = 'https://cdn.example.test/avatar.jpg';
    mockNavigate.mockClear();
    mockOpenGuestLogin.mockClear();
  });

  it('opens account editing when the learner taps their avatar', async () => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Profile />);
    });

    const avatar = renderer.root.findByProps({
      accessibilityLabel: 'تغيير صورة اسم الطالب',
    });
    await act(async () => avatar.props.onPress());

    expect(mockNavigate).toHaveBeenCalledWith('EditAccount');
    expect(mockOpenGuestLogin).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });

  it('keeps the same entry useful for a guest by opening login return', async () => {
    mockAuthenticated = false;
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Profile />);
    });

    const avatar = renderer.root.findByProps({
      accessibilityLabel: 'تسجيل الدخول لتغيير صورة الحساب',
    });
    await act(async () => avatar.props.onPress());

    expect(mockOpenGuestLogin).toHaveBeenCalledWith(expect.anything(), {
      name: 'EditAccount',
    });
    await act(async () => renderer.unmount());
  });

  it('does not hide the new avatar when the replaced image reports a late error', async () => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Profile />);
    });
    const oldImageFailed = renderer.root.findByType(Image).props.onError;
    mockAvatarUri = 'https://cdn.example.test/new-avatar.jpg';
    await act(async () => {
      renderer.update(<Profile />);
    });
    await act(async () => {
      oldImageFailed();
    });
    expect(renderer.root.findByType(Image).props.source.uri).toBe(
      mockAvatarUri,
    );
    await act(async () => {
      renderer.root.findByType(Image).props.onError();
    });
    expect(renderer.root.findAllByType(Image)).toHaveLength(0);
    await act(async () => renderer.unmount());
  });
});
