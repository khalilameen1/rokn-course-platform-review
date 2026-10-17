import React from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import type {WalletSnapshot} from '../src/services/roknApi';

const mockPost = jest.fn();
const mockOpenBrowser = jest.fn();
const mockGetWallet = jest.fn();
const mockStorage = new Map<string, unknown>();
const mockNavigation = {setParams: jest.fn(), dispatch: jest.fn()};
let mockBoundary = {scope: 'learner', epoch: 1};
let mockBalance = 0;
let mockOrderRef = '';
let mockCase = 0;

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    const {useEffect} = jest.requireActual('react');
    useEffect(effect, [effect]);
  },
  useIsFocused: () => true,
  useNavigation: () => mockNavigation,
  useRoute: () => ({params: {}}),
}));
jest.mock('expo-crypto', () => ({
  randomUUID: () => '11111111-1111-4111-8111-111111111111',
}));
jest.mock('expo-web-browser', () => ({
  openAuthSessionAsync: (...args: unknown[]) => mockOpenBrowser(...args),
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {post: (...args: unknown[]) => mockPost(...args)},
}));
jest.mock('../src/constants/distribution', () => ({
  DISTRIBUTION_CHANNEL: 'direct',
  CAN_START_EXTERNAL_CHECKOUT: true,
  CAN_START_NATIVE_CHECKOUT: false,
  CAN_START_COIN_CHECKOUT: true,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: async (key: string, boundary = mockBoundary) =>
    `${key}:${boundary.scope}`,
  getItem: async (key: string) => mockStorage.get(key) ?? null,
  saveItem: async (key: string, value: unknown) => {
    mockStorage.set(key, value);
    return true;
  },
  removeItem: async (key: string) => mockStorage.delete(key),
}));
jest.mock('../src/services/productFeatures', () => ({
  requireProductFeature: async () => undefined,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/navigation/checkoutReturn', () => ({
  savePendingCheckoutReturn: async () => undefined,
  claimPendingCheckoutReturn: async () => undefined,
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: async () => true,
  getWallet: (...args: unknown[]) => mockGetWallet(...args),
  getCoinPackages: async () => [],
  getCoinTasks: async () => [],
  getCourseDetailsSnapshot: async () => ({
    course: {id: '3', owned: false, price: 900, accessPlans: []},
  }),
  isCourseUnavailableError: () => false,
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));
jest.mock('../src/screens/wallet/walletCache', () => ({
  readWalletCache: async () => null,
  saveWalletCache: async () => undefined,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.fn(),
  mapCoursePayload: jest.fn(),
}));

import {
  openCoinCheckout,
  reconcilePendingCoinCheckout,
} from '../src/services/coinCheckout';
import {subscribeWalletSettlements} from '../src/services/walletSettlement';
import {useWalletData} from '../src/screens/wallet/useWalletData';
import {useCourseDetailsData} from '../src/screens/CourseDetails/details/useCourseDetailsData';

const coinPackage = {id: '7', coins: 600, price: 49, label: 'باقة'};
const snapshot = (): WalletSnapshot => ({
  balance: mockBalance,
  paidBalance: mockBalance,
  rewardBalance: 0,
  rewardContributionCap: 100,
  spendableBalance: mockBalance,
  spendPolicy: 'reward_first_then_paid',
  coinRules: [],
  transactions: mockBalance
    ? [{id: 'credit', amount: mockBalance, label: 'شحن الرصيد'}]
    : [],
});
const response = (data: Record<string, unknown>) => ({data: {data}});
const paidResponse = () =>
  response({
    status: 'approved',
    financial_status: 'settled',
    package: {coins: 600},
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

describe('checkout credit survives its initiating screen', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let wallet!: ReturnType<typeof useWalletData>;
  let course!: ReturnType<typeof useCourseDetailsData>;
  let credit: jest.Mock;
  let unsubscribe: () => void;
  const Wallet = () => {
    wallet = useWalletData(mockBoundary.scope);
    return null;
  };
  const Course = () => {
    course = useCourseDetailsData({
      courseId: '3',
      identityKey: mockBoundary.scope,
    });
    return null;
  };
  const mount = async (element = <Wallet />) => {
    await act(async () => {
      renderer = TestRenderer.create(element);
    });
  };
  const removeScreen = async () => {
    await act(async () => renderer!.unmount());
    renderer = undefined;
  };
  const prepareCheckout = () => {
    const reconcile = deferred<ReturnType<typeof response>>();
    mockPost.mockImplementation(
      (endpoint: string, body?: {idempotency_key: string}) => {
        if (endpoint === 'payment/initiate') {
          return Promise.resolve(
            response({
              payment_url: 'https://checkout.kashier.io/session',
              order_ref: mockOrderRef,
              idempotency_key: body!.idempotency_key,
            }),
          );
        }
        if (endpoint === `payment/reconcile/${mockOrderRef}`)
          return reconcile.promise;
        throw new Error(`Unexpected mocked endpoint ${endpoint}`);
      },
    );
    return reconcile;
  };

  beforeEach(() => {
    jest.clearAllMocks();
    mockStorage.clear();
    mockBoundary = {scope: `learner-${++mockCase}`, epoch: mockCase};
    mockOrderRef = `PKG-OWNER-${mockCase}`;
    mockBalance = 0;
    mockGetWallet.mockImplementation(async () => snapshot());
    mockOpenBrowser.mockImplementation(async () => ({
      type: 'success',
      url: `rokn://payment-result?status=pending&order_ref=${mockOrderRef}&coins=0`,
    }));
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    credit = jest.fn();
    // Balance readers listen to the one wallet settlement channel.
    unsubscribe = subscribeWalletSettlements(credit);
  });
  afterEach(async () => {
    if (renderer) await removeScreen();
    unsubscribe();
    jest.restoreAllMocks();
  });

  it.each(['wallet', 'course'] as const)(
    'refreshes the replacement %s after the old Wallet is removed before paid reconciliation',
    async destination => {
      const reconcile = prepareCheckout();
      await mount();
      let operation!: Promise<void>;
      await act(async () => {
        operation = openCoinCheckout(coinPackage).then(() => undefined);
      });
      expect(mockPost).toHaveBeenCalledWith(
        `payment/reconcile/${mockOrderRef}`,
      );
      // Runtime joins this actual checkout while the provider result is pending.
      const recovery = reconcilePendingCoinCheckout();
      await removeScreen();
      await mount(destination === 'wallet' ? <Wallet /> : <Course />);
      expect(mockGetWallet).toHaveBeenCalledTimes(2);
      expect(
        destination === 'wallet'
          ? wallet.wallet?.balance
          : course.commerce.balance,
      ).toBe(0);

      await act(async () => {
        mockBalance = 600;
        reconcile.resolve(paidResponse());
        await operation;
        await recovery;
      });
      expect(
        destination === 'wallet'
          ? wallet.wallet?.balance
          : course.commerce.balance,
      ).toBe(600);
      expect(mockGetWallet).toHaveBeenCalledTimes(3);
      if (destination === 'wallet')
        expect(wallet.wallet?.transactions).toHaveLength(1);
      expect(credit).toHaveBeenCalledTimes(1);
      expect(credit.mock.calls[0][0].scope).toBe(mockBoundary.scope);
      expect(mockOpenBrowser).toHaveBeenCalledTimes(1);
      expect(Alert.alert).not.toHaveBeenCalled();
      await act(async () => {
        await reconcilePendingCoinCheckout();
      });
      expect(credit).toHaveBeenCalledTimes(1);
      expect(mockGetWallet).toHaveBeenCalledTimes(3);
    },
  );

  it('notifies a course top-up intent once despite a joined caller and foreground recovery', async () => {
    const reconcile = prepareCheckout();
    await mount();
    const options = {
      returnTo: {
        name: 'CourseDetails' as const,
        params: {courseId: '3', openPurchase: true},
      },
    };
    const first = openCoinCheckout(coinPackage, options);
    const duplicate = openCoinCheckout(coinPackage, options);
    await act(async () => undefined);
    const recovery = reconcilePendingCoinCheckout();
    await act(async () => {
      mockBalance = 600;
      reconcile.resolve(paidResponse());
      await Promise.all([first, duplicate, recovery]);
    });
    expect(credit).toHaveBeenCalledTimes(1);
    expect(wallet.wallet?.balance).toBe(600);
    expect(mockGetWallet).toHaveBeenCalledTimes(2);
    expect(mockOpenBrowser).toHaveBeenCalledTimes(1);
  });

  it('does not repeat the credit event when a later explicit call receives the same paid order', async () => {
    mockPost.mockResolvedValue(
      response({
        checkout_state: 'paid',
        order_status: 'approved',
        financial_status: 'settled',
        order_ref: mockOrderRef,
        coins_added: 600,
      }),
    );
    await mount();
    await act(async () => {
      mockBalance = 600;
      await openCoinCheckout(coinPackage);
    });
    await act(async () => {
      await openCoinCheckout(coinPackage);
    });
    expect(credit).toHaveBeenCalledTimes(1);
    expect(mockGetWallet).toHaveBeenCalledTimes(2);
    expect(wallet.wallet?.balance).toBe(600);
    expect(mockOpenBrowser).not.toHaveBeenCalled();
  });
});
