import React from 'react';
import {ActivityIndicator, ScrollView, StyleSheet, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {WalletView} from '../src/screens/wallet/WalletView';
import {WalletPackageRail} from '../src/screens/wallet/WalletPackageRail';
import {walletStyles} from '../src/screens/wallet/walletStyles';
import type {WalletController} from '../src/screens/wallet/useWalletController';
import TaskBrandIcon from '../src/components/ui/TaskBrandIcon';
import {CoinAmount} from '../src/components/ui/RoknCoin';
import {Accessibility, Palette, Spacing} from '../src/constants/designSystem';
import {
  formatArabicDisplayText,
  formatArabicNumber,
} from '../src/constants/arabicFormatting';

let mockDimensions = {width: 390, height: 844, scale: 2, fontScale: 1};
jest.mock('react-native/Libraries/Utilities/useWindowDimensions', () => ({
  __esModule: true,
  default: () => mockDimensions,
}));
jest.mock('@react-navigation/native', () => ({useNavigation: () => ({})}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_COIN_CHECKOUT: true,
}));
jest.mock('../src/components/TabBar', () => () => null);
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/components/ui/TaskBrandIcon', () => () => null);
jest.mock('../src/components/containers/Containers', () => {
  const {View} = require('react-native');
  return {Container: View, Content: View};
});
jest.mock('../src/components/ui/PremiumUI', () => {
  const {Text: MockText, View} = require('react-native');
  return {
    PremiumCard: View,
    ResponsiveFrame: View,
    SectionHeading: ({title}: {title: string}) => <MockText>{title}</MockText>,
    StatusView: () => null,
  };
});

const packages = [
  {id: '1', coins: 150, price: 75, label: 'باقة البداية'},
  {
    id: '2',
    coins: 1000,
    price: 400,
    label: 'باقة التعلّم',
    displayPrice: '٤٠٠ ج.م.',
  },
  {id: '3', coins: 2500, price: 900, label: 'باقة أكبر'},
];

const makeController = (
  overrides: Partial<WalletController> = {},
): WalletController => ({
  checkoutLoading: null,
  displayedBalance: 1250,
  displayedCoinRules: [],
  displayedPackages: packages,
  displayedPaidBalance: 1000,
  displayedRewardBalance: 250,
  displayedRewardContributionCap: 100,
  displayedSpendableBalance: 1100,
  displayedTasks: [
    {
      id: 'youtube',
      serverId: '1',
      title: 'تابع ركن على يوتيوب',
      description: 'شاهد دروسنا الجديدة',
      reward: 50,
      actionKey: 'follow_youtube',
      status: 'available',
      requiresExternalVisit: true,
    },
  ],
  displayedTransactions: [],
  handleTask: jest.fn(),
  manualRefreshing: false,
  ownerReady: true,
  packagesStatus: 'ready',
  refreshWallet: jest.fn(),
  refreshWalletManually: jest.fn(),
  serverSession: true,
  setWalletModal: jest.fn(),
  startCheckout: jest.fn(),
  taskActionLabel: () => 'تابع',
  taskLoadingIds: [],
  tasksStatus: 'ready',
  usingRemoteWallet: true,
  walletModal: null,
  walletStatus: 'ready',
  ...overrides,
});

describe('wallet presentation', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const render = async (controller = makeController()) => {
    await act(async () => {
      renderer = TestRenderer.create(<WalletView controller={controller} />);
    });
    return renderer.root;
  };
  const packageButtons = () =>
    renderer.root
      .findAll(node => typeof node.props.style === 'function')
      .filter(node => node.props.accessibilityLabel?.includes('مقابل'));
  const taskButton = () =>
    renderer.root
      .findAll(node => typeof node.props.style === 'function')
      .find(node =>
        node.findAllByType(Text).some(text => text.props.children === 'تابع'),
      )!;

  afterEach(async () => {
    await act(async () => renderer?.unmount());
    mockDimensions = {width: 390, height: 844, scale: 2, fontScale: 1};
  });

  it.each([
    [320, 1],
    [320, 1.3],
    [360, 1.3],
    [390, 1],
    [430, 1.3],
  ])(
    'leaves a substantial next-package peek at %idp / font scale %s',
    async (width, fontScale) => {
      mockDimensions = {...mockDimensions, width, fontScale};
      const root = await render();
      const rail = root.findByType(WalletPackageRail);
      const {cardWidth, gutter} = rail.props;
      expect(cardWidth).toBeGreaterThanOrEqual(width * 0.52);
      expect(cardWidth).toBeLessThanOrEqual(width * 0.58);
      expect(width - gutter * 2 - cardWidth - Spacing.sm).toBeGreaterThan(70);
      expect(cardWidth - walletStyles.packageCard.padding * 2).toBeGreaterThan(
        140,
      );
      expect(packageButtons()).toHaveLength(3);
      const scroll = rail.findByType(ScrollView);
      expect(scroll.props.horizontal).toBe(true);
      expect(scroll.props.snapToInterval).toBe(cardWidth + Spacing.sm);
    },
  );

  it('keeps the price, coin amount and visible checkout action on each compact card', async () => {
    const controller = makeController();
    await render(controller);
    expect(packageButtons()).toHaveLength(packages.length);
    for (const [index, button] of packageButtons().entries()) {
      expect(button.findByType(CoinAmount).props.value).toBe(
        packages[index].coins,
      );
      const price =
        packages[index].displayPrice ||
        `${formatArabicNumber(packages[index].price)} جنيه`;
      const texts = button.findAllByType(Text);
      expect(
        texts.some(
          text =>
            text.props.children ===
            formatArabicDisplayText(packages[index].label),
        ),
      ).toBe(true);
      expect(texts.some(text => text.props.children === price)).toBe(true);
      expect(texts.some(text => text.props.children === 'اختيار الباقة')).toBe(
        true,
      );
    }
    expect(walletStyles.packageAction.backgroundColor).toBe(Palette.action);
    expect(walletStyles.packageAction.minHeight).toBeGreaterThanOrEqual(
      Accessibility.minTouchTarget,
    );
    expect(walletStyles.packageActionLabel.flexShrink).toBe(1);
    await act(async () => packageButtons()[1].props.onPress());
    expect(controller.startCheckout).toHaveBeenCalledWith(packages[1]);
    expect(controller.startCheckout).toHaveBeenCalledTimes(1);
  });

  it('preserves the checkout busy guard and disables stale packages', async () => {
    await render(makeController({checkoutLoading: '2'}));
    expect(packageButtons()).toHaveLength(packages.length);
    expect(packageButtons().every(button => button.props.disabled)).toBe(true);
    expect(packageButtons()[1].props.accessibilityState).toEqual({
      busy: true,
      disabled: true,
    });
    expect(packageButtons()[1].findAllByType(ActivityIndicator)).toHaveLength(
      1,
    );
    await act(async () => {
      renderer.update(
        <WalletView controller={makeController({packagesStatus: 'error'})} />,
      );
    });
    expect(packageButtons().every(button => button.props.disabled)).toBe(true);
    expect(
      packageButtons()[0]
        .findAllByType(Text)
        .some(text => text.props.children === 'حدّث الباقات أولًا'),
    ).toBe(true);
  });

  it('restores task identity and a visible action without moving rewards away from their title', async () => {
    const controller = makeController();
    const root = await render(controller);
    expect(root.findByType(TaskBrandIcon).props.value).toBe('follow_youtube');
    const title = root
      .findAllByType(Text)
      .find(text => text.props.children === 'تابع ركن على يوتيوب')!;
    expect(title.parent!.findByType(CoinAmount).props.value).toBe(50);
    expect(walletStyles.taskTitleRow.flexWrap).toBe('wrap');
    const actionStyle = StyleSheet.flatten(
      taskButton().props.style({pressed: false}),
    );
    expect(actionStyle.backgroundColor).toBe(Palette.primarySoft);
    expect(actionStyle.borderWidth).toBe(1);
    expect(actionStyle.minHeight).toBeGreaterThanOrEqual(
      Accessibility.minTouchTarget,
    );
    await act(async () => taskButton().props.onPress());
    expect(controller.handleTask).toHaveBeenCalledWith(
      controller.displayedTasks[0],
    );
  });

  it('gives task text its own row on a narrow phone with enlarged text', async () => {
    mockDimensions = {...mockDimensions, width: 320, fontScale: 1.3};
    await render();
    const actionStyle = StyleSheet.flatten(
      taskButton().props.style({pressed: false}),
    );
    expect(actionStyle.maxWidth).toBe('100%');
    expect(actionStyle.alignSelf).toBe('flex-end');
    expect(actionStyle.marginStart).toBe(0);
    expect(walletStyles.taskCopy.minWidth).toBe(0);
    expect(walletStyles.taskTitle.flexShrink).toBe(1);
  });

  it('keeps the balance readable and accessible with a compact header', async () => {
    const controller = makeController();
    const root = await render(controller);
    const balance = root
      .findAll(node => typeof node.props.style === 'function')
      .find(node => node.props.accessibilityLabel === 'تفاصيل رصيد العملات')!;
    expect(balance.props.accessibilityValue.text).toContain(
      formatArabicNumber(1250),
    );
    expect(walletStyles.balance.fontSize).toBeGreaterThanOrEqual(40);
    expect(walletStyles.balance.fontSize).toBeLessThanOrEqual(46);
    expect(walletStyles.balance.lineHeight).toBeLessThanOrEqual(60);
    expect(walletStyles.balanceCard.paddingBottom).toBeLessThanOrEqual(
      Spacing.xs,
    );
    await act(async () => balance.props.onPress());
    expect(controller.setWalletModal).toHaveBeenCalledWith('breakdown');
  });
});
