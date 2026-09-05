import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockSharePortfolio = jest.fn();

jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: jest.fn()}),
  useRoute: () => ({params: undefined}),
}));
jest.mock('../src/screens/Profile/useProfileOverview', () => ({
  useProfileOverview: () => ({
    authenticatedIdentity: true,
    avatarUri: '',
    canSharePortfolio: true,
    certificateHolderName: 'اسم الطالب',
    displayName: 'اسم الطالب',
    identityKey: 'account-one',
    openPortfolio: jest.fn(),
    portfolioLinkLabel: 'rokn.app/@student',
    profileError: '',
    publicPortfolioUrl: 'https://rokn.app/@student',
    retry: jest.fn(),
    role: '',
    setHasShareablePortfolio: jest.fn(),
    sharePortfolio: mockSharePortfolio,
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

describe('profile portfolio share visibility', () => {
  it('keeps portfolio sharing inside the works tab', async () => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Profile />);
    });

    expect(
      renderer.root.findAllByProps({accessibilityLabel: 'مشاركة البورتفوليو'})
        .length,
    ).toBeGreaterThan(0);
    expect(
      renderer.root.findAllByProps({
        accessibilityLabel: 'فتح رابط مشاركة البورتفوليو',
      }).length,
    ).toBeGreaterThan(0);

    await act(async () => {
      const tabs = renderer.root
        .findAllByProps({accessibilityRole: 'tab'})
        .filter(node => typeof node.props.onPress === 'function');
      tabs[1].props.onPress();
    });

    expect(
      renderer.root.findAllByProps({accessibilityLabel: 'مشاركة البورتفوليو'}),
    ).toHaveLength(0);
    expect(
      renderer.root.findAllByProps({
        accessibilityLabel: 'فتح رابط مشاركة البورتفوليو',
      }),
    ).toHaveLength(0);

    await act(async () => {
      const tabs = renderer.root
        .findAllByProps({accessibilityRole: 'tab'})
        .filter(node => typeof node.props.onPress === 'function');
      tabs[0].props.onPress();
    });

    expect(
      renderer.root.findAllByProps({accessibilityLabel: 'مشاركة البورتفوليو'})
        .length,
    ).toBeGreaterThan(0);
    await act(async () => renderer.unmount());
  });
});
