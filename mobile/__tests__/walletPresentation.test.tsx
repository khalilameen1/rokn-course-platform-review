import React from 'react';
import {StyleSheet, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {WalletView} from '../src/screens/wallet/WalletView';
import {WalletPackageRail} from '../src/screens/wallet/WalletPackageRail';
import {RoknCoinStack, CoinAmount} from '../src/components/ui/RoknCoin';
import TaskBrandIcon from '../src/components/ui/TaskBrandIcon';
import {walletStyles} from '../src/screens/wallet/walletStyles';
import {learnerRewardTasks} from '../src/screens/wallet/rewardsPresentation';
import type {WalletController} from '../src/screens/wallet/useWalletController';
import {formatArabicNumber} from '../src/constants/arabicFormatting';
import {Accessibility} from '../src/constants/designSystem';

let mockDimensions = {width: 390, height: 844, scale: 2, fontScale: 1};
jest.mock('react-native/Libraries/Utilities/useWindowDimensions', () => ({
  __esModule: true,
  default: () => mockDimensions,
}));
jest.mock('@react-navigation/native', () => ({useNavigation: () => ({})}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/components/TabBar', () => () => null);
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/components/ui/TaskBrandIcon', () => () => null);
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('../src/components/containers/Containers', () => {
  const {View} = require('react-native');
  return {Container: View, Content: View};
});
jest.mock('../src/components/ui/PremiumUI', () => {
  const {View} = require('react-native');
  return {ResponsiveFrame: View, StatusView: () => null};
});

const makeController = (
  overrides: Partial<WalletController> = {},
): WalletController => ({
  displayedBalance: 1250,
  displayedPaidBalance: 1000,
  displayedRewardBalance: 250,
  displayedCoinRules: ['قيمة الخصم تظهر قبل الدفع'],
  displayedTasks: [
    {
      id: 'production-1',
      serverId: '1',
      title: 'تابع ركن على يوتيوب',
      description: 'شرح طويل لا يظهر في الصفحة',
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
  refreshWallet: jest.fn(),
  refreshWalletManually: jest.fn(),
  serverSession: true,
  setWalletModal: jest.fn(),
  taskActionLabel: () => 'اشتراك',
  taskLoadingIds: [],
  tasksStatus: 'ready',
  usingRemoteWallet: true,
  walletModal: null,
  walletStatus: 'ready',
  ...overrides,
});

describe('rewards presentation', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const render = async (controller = makeController()) => {
    await act(async () => {
      renderer = TestRenderer.create(<WalletView controller={controller} />);
    });
    return renderer.root;
  };
  const texts = () =>
    renderer.root.findAllByType(Text).map(node => node.props.children);
  const taskButton = () =>
    renderer.root
      .findAll(node => typeof node.props.style === 'function')
      .find(node => node.props.accessibilityLabel?.startsWith('اشتراك '))!;
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    mockDimensions = {width: 390, height: 844, scale: 2, fontScale: 1};
  });
  it.each([
    [320, 1],
    [320, 2],
    [390, 1],
    [430, 1.3],
    [800, 2],
  ])(
    'shows earned balance, artwork and no top-up catalogue at %idp / font scale %s',
    async (width, fontScale) => {
      mockDimensions = {...mockDimensions, width, fontScale};
      const root = await render();
      expect(root.findAllByType(WalletPackageRail)).toHaveLength(0);
      expect(root.findAllByType(RoknCoinStack)).toHaveLength(1);
      expect(texts()).toContain('اكسب عملات');
      expect(texts()).toContain(formatArabicNumber(250));
      expect(texts()).not.toContain(formatArabicNumber(1250));
      expect(texts()).not.toContain('شحن الرصيد');
      const balance = root
        .findAllByType(Text)
        .find(node => node.props.accessibilityLabel === 'رصيد المكافآت')!;
      expect(balance.props.numberOfLines).toBeUndefined();
      expect(balance.props.allowFontScaling).not.toBe(false);
    },
  );
  it('keeps the task title, brand and reward without a description paragraph', async () => {
    const controller = makeController();
    const root = await render(controller);
    expect(texts()).toContain('اشترك في قناتنا على يوتيوب');
    expect(texts()).not.toContain(controller.displayedTasks[0].description);
    expect(root.findByType(TaskBrandIcon).props).toEqual({
      value: 'follow_youtube',
      plain: true,
    });
    expect(root.findByType(CoinAmount).props.value).toBe(50);
    expect(
      StyleSheet.flatten(taskButton().props.style({pressed: false})).minHeight,
    ).toBeGreaterThanOrEqual(Accessibility.minTouchTarget);
    await act(async () => taskButton().props.onPress());
    expect(controller.handleTask).toHaveBeenCalledWith(
      controller.displayedTasks[0],
    );
  });
  it('disables task mutations when the task snapshot is stale', async () => {
    await render(makeController({tasksStatus: 'error'}));
    expect(taskButton().props.disabled).toBe(true);
  });
  it('places completed tasks behind a disclosure and never exposes welcome as a claim', async () => {
    const controller = makeController();
    const completed = {
      ...controller.displayedTasks[0],
      status: 'claimed' as const,
    };
    const tasks = learnerRewardTasks([
      completed,
      {...completed, actionKey: 'register'},
      {...completed, actionKey: 'welcome_bonus'},
    ]);
    expect(tasks).toEqual([completed]);
    const root = await render({...controller, displayedTasks: tasks});
    expect(texts()).not.toContain('تم الاستلام');
    const toggle = root.findAll(
      node =>
        node.props.accessibilityState?.expanded === false &&
        typeof node.props.onPress === 'function',
    )[0];
    await act(async () => toggle.props.onPress());
    expect(texts()).toContain('تم الاستلام');
    expect(root.findAllByType(CoinAmount)).toHaveLength(0);
  });
  it('has matching disclosure styling and safe touch targets', async () => {
    await render();
    expect(walletStyles.disclosure.minHeight).toBeGreaterThanOrEqual(
      Accessibility.minTouchTarget,
    );
    expect(walletStyles.disclosure).not.toHaveProperty('backgroundColor');
    const button = renderer.root
      .findAll(node => typeof node.props.style === 'function')
      .find(node =>
        node
          .findAllByType(Text)
          .some(text => text.props.children === 'كيف يعمل الرصيد'),
      )!;
    expect(
      StyleSheet.flatten(button.props.style({pressed: false})),
    ).toMatchObject(walletStyles.disclosure);
  });
  it('does not invent a zero balance while loading or after a failure', async () => {
    await render(
      makeController({displayedBalance: null, walletStatus: 'error'}),
    );
    expect(texts()).toContain('—');
    expect(texts()).toContain('تعذّر تحديث الرصيد');
    expect(texts()).not.toContain(formatArabicNumber(0));
  });
});
