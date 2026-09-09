import React from 'react';
import {Alert, AppState} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import type {CoinPackage} from '../src/services/api/coinPackageMapper';

const mockWallet = jest.fn();
const mockPackages = jest.fn();
const mockCheckout = jest.fn();
const mockSaveItem = jest.fn();
let mockBoundary = {scope: 'account-a', epoch: 1};
const mockDisk = new Map<string, unknown>();

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    jest.requireActual('react').useEffect(effect, [effect]);
  },
  useIsFocused: () => true,
  useNavigation: () => ({setParams: jest.fn(), dispatch: jest.fn()}),
  useRoute: () => ({params: {}}),
  CommonActions: {navigate: jest.fn()},
}));
jest.mock('../src/services/roknApi', () => ({
  getWallet: (...args: unknown[]) => mockWallet(...args),
  getCoinPackages: (...args: unknown[]) => mockPackages(...args),
  getCoinTasks: async () => [],
  hasSession: async () => true,
}));
jest.mock('../src/services/coinCheckout', () => ({
  openCoinCheckout: (...args: unknown[]) => mockCheckout(...args),
  subscribeCoinCheckoutCredits: () => () => undefined,
}));
jest.mock('../src/navigation/checkoutReturn', () => ({
  acknowledgePendingCheckoutReturn: jest.fn(),
  claimPendingCheckoutReturn: async () => undefined,
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_NATIVE_CHECKOUT: false,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: {scope: string; epoch: number}) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: async (base: string, boundary: {scope: string}) =>
    `${base}:${boundary.scope}`,
  getItem: async (key: string) => mockDisk.get(key),
  saveItem: (...args: unknown[]) => mockSaveItem(...args),
}));

import {useWalletData} from '../src/screens/wallet/useWalletData';
import {useWalletCheckout} from '../src/screens/wallet/useWalletCheckout';
import {readWalletCache} from '../src/screens/wallet/walletCache';

const oldPackage: CoinPackage = {id: '1', price: 10, coins: 100, label: 'باقة'};
const newPackage: CoinPackage = {...oldPackage, price: 15, coins: 150};
const balance = {
  balance: 0,
  paidBalance: 0,
  rewardBalance: 0,
  rewardContributionCap: 1200,
  spendableBalance: 0,
  spendPolicy: 'reward_first_then_paid',
  coinRules: [],
  transactions: [],
};
const changedPackage = {response: {data: {code: 'package_terms_changed'}}};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => {
    resolve = yes;
  });
  return {promise, resolve};
};

// Actual wallet data, checkout consumer and raw cache queue. Only provider,
// navigation, session and native persistence are controlled fixtures.
describe('wallet package invalidation lifecycle', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let data!: ReturnType<typeof useWalletData>;
  let checkout!: ReturnType<typeof useWalletCheckout>;
  const Harness = ({identityKey = 'account-a'}: {identityKey?: string}) => {
    data = useWalletData(identityKey);
    checkout = useWalletCheckout(data);
    return null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  const flushOptionalReads = async () => {
    await act(async () => jest.advanceTimersByTimeAsync(800));
  };

  beforeEach(() => {
    jest.useFakeTimers();
    jest.resetAllMocks();
    mockDisk.clear();
    mockBoundary = {scope: 'account-a', epoch: 1};
    mockWallet.mockResolvedValue(balance);
    mockPackages.mockResolvedValue([oldPackage]);
    mockCheckout.mockRejectedValue(changedPackage);
    mockSaveItem.mockImplementation(async (key, value) => {
      mockDisk.set(key, value);
      return true;
    });
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    jest
      .spyOn(AppState, 'addEventListener')
      .mockReturnValue({remove: jest.fn()});
    AppState.currentState = 'active';
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.restoreAllMocks();
    jest.useRealTimers();
  });

  it('reloads changed packages without waiting for native invalidation and preserves raw write order', async () => {
    const nativeWrite = deferred<void>();
    let paying!: Promise<void>;
    await mount();
    mockSaveItem.mockImplementationOnce(async (key, value) => {
      await nativeWrite.promise;
      mockDisk.set(key, value);
      return true;
    });
    mockPackages.mockResolvedValue([newPackage]);
    try {
      await act(async () => {
        paying = checkout.startCheckout(oldPackage);
      });
      await flushOptionalReads();
      expect(mockPackages).toHaveBeenCalledTimes(2);
      expect(data.packages).toEqual([newPackage]);
      expect(checkout.checkoutLoading).toBeNull();
      expect(mockCheckout).toHaveBeenCalledTimes(1);
      // The newer snapshot remains queued, not concurrent with the old
      // invalidation. Its eventual write must be the final persisted state.
      expect(mockSaveItem).toHaveBeenCalledTimes(2);
      await act(async () => nativeWrite.resolve());
      await paying;
      const saved = await readWalletCache(mockBoundary);
      expect(saved?.packages).toEqual([newPackage]);
    } finally {
      await act(async () => nativeWrite.resolve());
      await flushOptionalReads();
      if (paying) await act(async () => paying);
    }
  });

  it.each(['rejected', 'not_saved'])(
    'does not restore a known obsolete package after %s invalidation and failed refresh',
    async failure => {
      await mount();
      if (failure === 'rejected') {
        mockSaveItem.mockRejectedValueOnce(new Error('native write failed'));
      } else {
        mockSaveItem.mockResolvedValueOnce(false);
      }
      mockPackages.mockRejectedValueOnce(new Error('network failed'));
      await act(async () => checkout.startCheckout(oldPackage));
      expect(checkout.checkoutLoading).toBeNull();
      expect(data.packages).toEqual([]);
      expect(data.packagesStatus).toBe('error');
      expect(mockCheckout).toHaveBeenCalledTimes(1);

      mockPackages.mockResolvedValue([newPackage]);
      await act(async () => data.refreshManually());
      expect(data.packages).toEqual([newPackage]);
      expect(data.packagesStatus).toBe('ready');
    },
  );

  it('cannot restore packages from an older read after the checkout rejects their terms', async () => {
    const oldRead = deferred<CoinPackage[]>();
    let refreshing!: Promise<void>;
    let paying!: Promise<void>;
    await mount();
    mockPackages.mockReturnValueOnce(oldRead.promise);
    await act(async () => {
      refreshing = data.refreshManually();
    });
    mockPackages.mockRejectedValueOnce(new Error('fresh packages offline'));
    await act(async () => {
      paying = checkout.startCheckout(oldPackage);
    });
    expect(data.packages).toEqual([]);
    expect(mockPackages).toHaveBeenCalledTimes(3);
    expect(data.manualRefreshing).toBe(false);
    await act(async () => oldRead.resolve([oldPackage]));
    await act(async () => {
      await Promise.all([refreshing, paying]);
    });
    expect(data.packages).toEqual([]);
    expect(data.packagesStatus).toBe('error');
    expect(mockPackages).toHaveBeenCalledTimes(3);
    expect(mockCheckout).toHaveBeenCalledTimes(1);
  });

  it('accepts an empty replacement catalogue and does not restore the removed package from cache', async () => {
    await mount();
    mockPackages.mockResolvedValue([]);
    await act(async () => checkout.startCheckout(oldPackage));
    expect(data.packages).toEqual([]);
    expect(data.packagesStatus).toBe('ready');
    expect(checkout.checkoutLoading).toBeNull();
    expect((await readWalletCache(mockBoundary))?.packages).toEqual([]);
  });

  it('does not invalidate packages when only the checkout provider is temporarily unavailable', async () => {
    await mount();
    mockCheckout.mockRejectedValueOnce({
      response: {data: {code: 'checkout_temporarily_unavailable'}},
    });
    await act(async () => checkout.startCheckout(oldPackage));
    expect(data.packages).toEqual([oldPackage]);
    expect(data.packagesStatus).toBe('ready');
    expect(checkout.checkoutLoading).toBeNull();
    expect(mockPackages).toHaveBeenCalledTimes(1);
    expect(mockCheckout).toHaveBeenCalledTimes(1);
  });
});
