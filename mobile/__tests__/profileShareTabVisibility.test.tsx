import React from 'react';
import {Modal, ScrollView, StyleSheet, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {execFileSync} from 'node:child_process';
import path from 'node:path';

const {execPath} = require('node:process') as {execPath: string};
const mockSharePortfolio = jest.fn();
const mockRetry = jest.fn();
const mockPublicPortfolioUrl = 'https://rokn.app/@student';
let mockCanSharePortfolio = true;
let mockProfileError = '';

jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: jest.fn()}),
  useRoute: () => ({params: undefined}),
}));
jest.mock('../src/screens/Profile/useProfileOverview', () => ({
  useProfileOverview: () => ({
    authenticatedIdentity: true,
    avatarUri: '',
    canSharePortfolio: mockCanSharePortfolio,
    certificateHolderName: 'اسم الطالب',
    displayName: 'اسم الطالب',
    identityKey: 'account-one',
    profileError: mockProfileError,
    publicPortfolioUrl: mockPublicPortfolioUrl,
    retry: mockRetry,
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
import QRCode from '../src/components/ui/QRCode';
import {Accessibility} from '../src/constants/designSystem';

describe('profile portfolio share visibility', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockCanSharePortfolio = true;
    mockProfileError = '';
  });

  it('shares and opens the QR code without displaying the raw portfolio URL', async () => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Profile />);
    });

    expect(
      renderer.root.findAllByProps({
        accessibilityLabel: 'فتح رابط مشاركة البورتفوليو',
      }),
    ).toHaveLength(0);
    expect(
      renderer.root.findAllByType(Text).some(node =>
        String(node.props.children).includes('rokn.app/@student'),
      ),
    ).toBe(false);

    await act(async () => {
      renderer.root
        .findByProps({accessibilityLabel: 'مشاركة البورتفوليو'})
        .props.onPress();
    });
    expect(mockSharePortfolio).toHaveBeenCalledTimes(1);

    await act(async () => {
      renderer.root
        .findByProps({accessibilityLabel: 'عرض رمز QR للبورتفوليو'})
        .props.onPress();
    });
    expect(renderer.root.findByType(Modal).props.visible).toBe(true);
    expect(renderer.root.findByType(QRCode).props.value).toBe(
      mockPublicPortfolioUrl,
    );
    await act(async () => renderer.unmount());
  });

  it.each([1, 2])('keeps sharing and QR out of profile tab %s', async tabIndex => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Profile />);
    });
    await act(async () => {
      renderer.root
        .findByProps({accessibilityLabel: 'عرض رمز QR للبورتفوليو'})
        .props.onPress();
    });

    await act(async () => {
      const tabs = renderer.root
        .findAllByProps({accessibilityRole: 'tab'})
        .filter(node => typeof node.props.onPress === 'function');
      tabs[tabIndex].props.onPress();
    });

    expect(
      renderer.root.findAllByProps({accessibilityLabel: 'مشاركة البورتفوليو'}),
    ).toHaveLength(0);
    expect(
      renderer.root.findAllByProps({
        accessibilityLabel: 'عرض رمز QR للبورتفوليو',
      }),
    ).toHaveLength(0);
    expect(renderer.root.findByType(Modal).props.visible).toBe(false);

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
    expect(renderer.root.findByType(Modal).props.visible).toBe(false);
    await act(async () => renderer.unmount());
  });

  it('keeps an unavailable portfolio unshareable and its error retry visible', async () => {
    mockCanSharePortfolio = false;
    mockProfileError = 'تعذّر تحديث بعض بيانات الحساب';
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Profile />);
    });

    expect(
      renderer.root.findAllByProps({accessibilityLabel: 'مشاركة البورتفوليو'}),
    ).toHaveLength(0);
    expect(
      renderer.root.findAllByProps({
        accessibilityLabel: 'عرض رمز QR للبورتفوليو',
      }),
    ).toHaveLength(0);
    expect(
      renderer.root.findAllByType(Text).some(
        node => node.props.children === mockProfileError,
      ),
    ).toBe(true);
    await act(async () => {
      renderer.root
        .findByProps({accessibilityLiveRegion: 'polite'})
        .props.onPress();
    });
    expect(mockRetry).toHaveBeenCalledTimes(1);
    await act(async () => renderer.unmount());
  });

  it.each([
    {width: 280, fontScale: 1},
    {width: 280, fontScale: 1.3},
    {width: 320, fontScale: 1.3},
    {width: 360, fontScale: 1.3},
  ])(
    'keeps full tab words at $width dp and font scale $fontScale',
    async ({width, fontScale}) => {
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<Profile />);
      });

      const tabBar = renderer.root.findByType(ScrollView);
      expect(tabBar.props.horizontal).toBe(true);
      const tabNodes = renderer.root
        .findAllByProps({accessibilityRole: 'tab'})
        .filter(node => typeof node.props.onPress === 'function');
      const measurements = tabNodes.map(tab => {
        const label = tab.findByType(Text);
        const labelStyle = StyleSheet.flatten(label.props.style);
        expect(label.props.numberOfLines).toBe(1);
        expect(label.props.allowFontScaling).not.toBe(false);
        expect(label.props.adjustsFontSizeToFit).not.toBe(true);
        expect(label.props.maxFontSizeMultiplier).toBeUndefined();
        expect(tab.props.accessibilityLabel).toBe(label.props.children);
        // Native font shaping is not available in Jest. Give real Yoga a
        // conservative one-em-per-character single-line text measurement.
        return {
          width: label.props.children.length * labelStyle.fontSize * fontScale,
          height: labelStyle.lineHeight * fontScale,
        };
      });
      const viewportWidth = width - 2 * Math.max(12, Math.min(18, width * 0.05));
      const geometry = JSON.parse(
        execFileSync(
          execPath,
          [path.join(__dirname, 'fixtures/chatYogaLayout.mjs')],
          {
            encoding: 'utf8',
            input: JSON.stringify({
              name: 'content',
              // Horizontal ScrollView measures content without a maximum
              // width; flexGrow fills the viewport when all labels fit.
              style: {
                ...StyleSheet.flatten(tabBar.props.contentContainerStyle),
                minWidth: viewportWidth,
              },
              children: tabNodes.map((tab, index) => ({
                name: `tab-${index}`,
                style: StyleSheet.flatten(tab.props.style({pressed: false})),
                children: [
                  {
                    name: `label-${index}`,
                    measure: measurements[index],
                  },
                ],
              })),
            }),
          },
        ),
      ) as Record<string, {width: number; height: number; left: number}>;

      expect(geometry.content.width).toBeGreaterThanOrEqual(viewportWidth);
      measurements.forEach((measurement, index) => {
        const tab = geometry[`tab-${index}`];
        const label = geometry[`label-${index}`];
        expect(tab.width).toBeGreaterThanOrEqual(Accessibility.minTouchTarget);
        expect(tab.height).toBeGreaterThanOrEqual(Accessibility.minTouchTarget);
        expect(label.width).toBeGreaterThanOrEqual(Math.floor(measurement.width));
        expect(label.height).toBeGreaterThanOrEqual(Math.floor(measurement.height));
        expect(label.left).toBeGreaterThanOrEqual(tab.left);
        expect(label.left + label.width).toBeLessThanOrEqual(tab.left + tab.width);
      });
      await act(async () => renderer.unmount());
    },
  );
});
