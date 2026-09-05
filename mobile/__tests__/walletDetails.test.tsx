import React, {useState} from 'react';
import {Modal, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

jest.mock('@react-navigation/native', () => ({useNavigation: () => ({})}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/components/TabBar', () => () => null);
jest.mock('../src/components/containers/Containers', () => {
  const {View} = require('react-native');
  return {Container: View, Content: View};
});
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/components/ui/RoknCoin', () => ({
  __esModule: true,
  default: () => null,
  CoinAmount: () => null,
  RoknCoinStack: () => null,
}));
jest.mock('../src/components/ui/TaskBrandIcon', () => () => null);
jest.mock('../src/screens/wallet/WalletPackageRail', () => ({
  WalletPackageRail: () => null,
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));

import {WalletView} from '../src/screens/wallet/WalletView';
import type {WalletController} from '../src/screens/wallet/useWalletController';

describe('wallet details on demand', () => {
  const transaction = {
    id: '1',
    title: 'شحن المحفظة',
    amount: 50,
    createdAt: Date.now(),
  };
  const refreshWallet = jest.fn();
  let renderer: TestRenderer.ReactTestRenderer;

  const render = async (
    transactions = [transaction],
    walletStatus = 'ready',
  ) => {
    const Harness = () => {
      const [walletModal, setWalletModal] =
        useState<WalletController['walletModal']>(null);
      const controller = {
        ownerReady: true,
        serverSession: true,
        usingRemoteWallet: true,
        displayedBalance: 50,
        displayedPaidBalance: 50,
        displayedRewardBalance: 0,
        displayedRewardContributionCap: 100,
        displayedSpendableBalance: 50,
        displayedCoinRules: ['استخدم العملات لفتح الكورسات'],
        displayedPackages: [],
        displayedTasks: [],
        displayedTransactions: transactions,
        taskLoadingIds: [],
        tasksStatus: 'success',
        packagesStatus: 'success',
        walletStatus,
        walletModal,
        setWalletModal,
        refreshWallet,
      } as unknown as WalletController;
      return <WalletView controller={controller} />;
    };
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  const hasText = (text: string) =>
    renderer.root
      .findAllByType(Text)
      .some(node => node.props.children === text);
  const pressText = async (text: string) => {
    const button = renderer.root
      .findAll(node => typeof node.props.onPress === 'function')
      .find(node =>
        node.findAllByType(Text).some(child => child.props.children === text),
      );
    expect(button).toBeDefined();
    await act(async () => {
      button!.props.onPress();
    });
  };
  afterEach(async () => {
    await act(async () => renderer?.unmount());
  });

  it('keeps transactions hidden until selected and closes with the shared sheet', async () => {
    await render();
    expect(hasText('آخر العمليات')).toBe(true);
    expect(hasText(transaction.title)).toBe(false);
    await pressText('آخر العمليات');
    expect(hasText(transaction.title)).toBe(true);
    expect(hasText('كيف يعمل الرصيد؟')).toBe(true);
    await act(async () => {
      renderer.root.findByType(Modal).props.onRequestClose();
    });
    expect(hasText(transaction.title)).toBe(false);
    await pressText('كيف يعمل الرصيد؟');
    expect(hasText('استخدم العملات لفتح الكورسات')).toBe(true);
    expect(hasText(transaction.title)).toBe(false);
  });

  it('keeps a clear empty history behind the same entry point', async () => {
    await render([]);
    expect(hasText('لا توجد عمليات بعد')).toBe(false);
    await pressText('آخر العمليات');
    expect(hasText('لا توجد عمليات بعد')).toBe(true);
    await pressText('تم');
    expect(hasText('لا توجد عمليات بعد')).toBe(false);
  });

  it('does not describe a failed history load as an empty wallet', async () => {
    await render([], 'error');
    await pressText('آخر العمليات');
    expect(hasText('تعذّر تحميل العمليات')).toBe(true);
    expect(hasText('لا توجد عمليات بعد')).toBe(false);
    await pressText('إعادة المحاولة');
    expect(refreshWallet).toHaveBeenCalledTimes(1);
  });
});
