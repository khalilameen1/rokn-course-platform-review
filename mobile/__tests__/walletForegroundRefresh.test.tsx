import React from 'react';
import {AppState, type AppStateStatus} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import type {AccountSessionBoundary} from '../src/constants/helpers';
import type {CoinTask, WalletSnapshot} from '../src/services/roknApi';

const mockWallet = jest.fn();
const mockPackages = jest.fn();
const mockTasks = jest.fn();
const mockHasSession = jest.fn();
const mockCaptureBoundary = jest.fn();
const mockSaveCache = jest.fn();
let mockBoundary = {scope: 'account-a', epoch: 1};
let mockFocused = true;

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    const {useEffect} = jest.requireActual('react');
    const focused = mockFocused;
    useEffect(() => (focused ? effect() : undefined), [effect, focused]);
  },
}));
jest.mock('../src/services/roknApi', () => ({
  getWallet: (...args: unknown[]) => mockWallet(...args),
  getCoinPackages: (...args: unknown[]) => mockPackages(...args),
  getCoinTasks: (...args: unknown[]) => mockTasks(...args),
  hasSession: (...args: unknown[]) => mockHasSession(...args),
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: (...args: unknown[]) =>
    mockCaptureBoundary(...args),
  assertAccountSessionBoundary: (boundary: {scope: string; epoch: number}) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));
jest.mock('../src/screens/wallet/walletCache', () => ({
  readWalletCache: async () => null,
  saveWalletCache: (...args: unknown[]) => mockSaveCache(...args),
}));

import {useWalletData} from '../src/screens/wallet/useWalletData';

const wallet = (rewardBalance: number): WalletSnapshot => ({
  balance: rewardBalance,
  paidBalance: 0,
  rewardBalance,
  rewardContributionCap: 1200,
  spendableBalance: rewardBalance,
  spendPolicy: 'reward_first_then_paid',
  coinRules: [],
  transactions: [],
});
const task = (status: CoinTask['status']): CoinTask => ({
  id: 'production-12',
  serverId: '12',
  actionKey: 'link_whatsapp',
  title: 'اربط واتسابك بركن',
  description: '',
  reward: 15,
  requiresExternalVisit: true,
  status,
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return {promise, resolve, reject};
};

describe('wallet foreground snapshot after external task credit', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let data!: ReturnType<typeof useWalletData>;
  let listeners: Set<(state: AppStateStatus) => void>;
  const Harness = ({identityKey = 'account-a'}: {identityKey?: string}) => {
    data = useWalletData(identityKey);
    return null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  const emit = async (...states: AppStateStatus[]) => {
    await act(async () => {
      states.forEach(state => {
        AppState.currentState = state;
        listeners.forEach(listener => listener(state));
      });
    });
  };
  const setFocused = async (focused: boolean) => {
    mockFocused = focused;
    await act(async () => renderer!.update(<Harness />));
  };
  const returnToWallet = async (via: 'app' | 'screen') => {
    if (via === 'app') await emit('background', 'active');
    else {
      await setFocused(false);
      await setFocused(true);
    }
  };

  beforeEach(() => {
    jest.resetAllMocks();
    mockBoundary = {scope: 'account-a', epoch: 1};
    mockFocused = true;
    mockCaptureBoundary.mockImplementation(async () => ({...mockBoundary}));
    mockHasSession.mockResolvedValue(true);
    mockWallet.mockResolvedValue(wallet(100));
    mockTasks.mockResolvedValue([task('started')]);
    mockPackages.mockResolvedValue([]);
    mockSaveCache.mockResolvedValue(undefined);
    AppState.currentState = 'active';
    listeners = new Set();
    jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation((_event, listener) => {
        listeners.add(listener);
        return {
          remove: () => {
            listeners.delete(listener);
          },
        };
      });
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.restoreAllMocks();
  });

  it.each(['resolved', 'rejected'] as const)(
    'queues one fresh wallet/tasks read behind slow packages (%s)',
    async settlement => {
      const oldPackages = deferred<[]>();
      const freshPackages = deferred<[]>();
      mockPackages
        .mockReturnValueOnce(oldPackages.promise)
        .mockReturnValueOnce(freshPackages.promise);
      await mount();
      expect(data.wallet?.balance).toBe(100);
      expect(data.tasks[0].status).toBe('started');
      expect(mockWallet).toHaveBeenCalledTimes(1);
      expect(mockTasks).toHaveBeenCalledTimes(1);

      await emit('background');
      // WhatsApp settles on the server while the pre-return package read is
      // still pending. The earlier wallet/task responses are now out of date.
      mockWallet.mockResolvedValue(wallet(115));
      mockTasks.mockResolvedValue([task('claimed')]);
      await emit('active', 'active', 'background', 'active', 'active');
      expect(mockWallet).toHaveBeenCalledTimes(1);

      await act(async () => {
        if (settlement === 'resolved') oldPackages.resolve([]);
        else oldPackages.reject(new Error('old packages unavailable'));
      });
      expect(mockWallet).toHaveBeenCalledTimes(2);
      expect(mockTasks).toHaveBeenCalledTimes(2);
      expect(mockPackages).toHaveBeenCalledTimes(2);
      expect(data.wallet?.balance).toBe(115);
      expect(data.tasks[0].status).toBe('claimed');

      // Duplicate active notifications are not another trip out of the app
      // and must not enqueue a third snapshot behind this fresh one.
      await emit('active', 'active');
      await act(async () => freshPackages.resolve([]));
      await emit('active');
      expect(mockWallet).toHaveBeenCalledTimes(2);
      expect(mockTasks).toHaveBeenCalledTimes(2);
      expect(data.walletStatus).toBe('ready');
      expect(data.tasksStatus).toBe('ready');
    },
  );

  it('refreshes once immediately when returning without an in-flight read', async () => {
    await mount();
    mockWallet.mockResolvedValue(wallet(115));
    mockTasks.mockResolvedValue([task('claimed')]);
    await emit('background', 'active', 'active');
    expect(mockWallet).toHaveBeenCalledTimes(2);
    expect(data.wallet?.balance).toBe(115);
    expect(data.tasks[0].status).toBe('claimed');
  });

  it('refreshes after screen refocus without duplicating initial mount or repeated focus reads', async () => {
    const oldPackages = deferred<[]>();
    mockPackages.mockReturnValueOnce(oldPackages.promise);
    await mount();
    expect(mockWallet).toHaveBeenCalledTimes(1);
    await setFocused(false);
    expect(listeners.size).toBe(0);
    mockWallet.mockResolvedValue(wallet(115));
    mockTasks.mockResolvedValue([task('claimed')]);
    await setFocused(true);
    await setFocused(true);
    await returnToWallet('screen');
    expect(mockWallet).toHaveBeenCalledTimes(1);
    await act(async () => oldPackages.resolve([]));
    expect(mockWallet).toHaveBeenCalledTimes(2);
    expect(mockTasks).toHaveBeenCalledTimes(2);
    expect(data.wallet?.balance).toBe(115);
    expect(data.tasks[0].status).toBe('claimed');
    expect(listeners.size).toBe(1);
    await setFocused(true);
    expect(mockWallet).toHaveBeenCalledTimes(2);
  });

  it.each(['app', 'screen'] as const)(
    'drops the old account queued %s return refresh and late response',
    async via => {
      const oldPackages = deferred<[]>();
      mockPackages.mockReturnValueOnce(oldPackages.promise);
      await mount();
      await returnToWallet(via);

      mockBoundary = {scope: 'account-b', epoch: 2};
      mockWallet.mockResolvedValue(wallet(40));
      mockTasks.mockResolvedValue([task('available')]);
      await act(async () =>
        renderer!.update(<Harness identityKey="account-b" />),
      );
      expect(mockWallet).toHaveBeenCalledTimes(2);
      expect(data.wallet?.balance).toBe(40);
      expect(data.tasks[0].status).toBe('available');

      await act(async () => oldPackages.resolve([]));
      expect(mockWallet).toHaveBeenCalledTimes(2);
      expect(mockTasks).toHaveBeenCalledTimes(2);
      expect(data.wallet?.balance).toBe(40);
      expect(mockSaveCache).toHaveBeenCalledTimes(1);
      expect(mockSaveCache.mock.calls[0][0]).toEqual(mockBoundary);
    },
  );

  it.each(['app', 'screen'] as const)(
    'cancels queued %s return work and removes the listener on unmount',
    async via => {
      const oldPackages = deferred<[]>();
      mockPackages.mockReturnValueOnce(oldPackages.promise);
      await mount();
      await returnToWallet(via);
      const previousController = data;
      await act(async () => renderer!.unmount());
      renderer = undefined;
      expect(listeners.size).toBe(0);

      await act(async () => oldPackages.resolve([]));
      await previousController.refreshAfterCurrent();
      expect(
        previousController.ownsBoundary(mockBoundary as AccountSessionBoundary),
      ).toBe(false);
      expect(mockWallet).toHaveBeenCalledTimes(1);
      expect(mockTasks).toHaveBeenCalledTimes(1);
      expect(mockSaveCache).not.toHaveBeenCalled();
    },
  );

  it('does not begin session or remote reads after a late boundary resolves on unmount', async () => {
    const boundary = deferred<AccountSessionBoundary>();
    mockCaptureBoundary.mockReturnValueOnce(boundary.promise);
    await mount();
    await emit('background', 'active');
    await act(async () => renderer!.unmount());
    renderer = undefined;
    await act(async () =>
      boundary.resolve(mockBoundary as AccountSessionBoundary),
    );
    expect(mockCaptureBoundary).toHaveBeenCalledTimes(1);
    expect(mockHasSession).not.toHaveBeenCalled();
    expect(mockWallet).not.toHaveBeenCalled();
    expect(mockTasks).not.toHaveBeenCalled();
  });
});
