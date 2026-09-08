import React from 'react';
import {AppState} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import TestRenderer, {act} from 'react-test-renderer';

const mockPost = jest.fn();
const mockGet = jest.fn();
let mockBoundary = {scope: 'user-1', epoch: 1};
let mockBalance = 20;
const task = {
  id: 'production-3',
  serverId: '3',
  title: 'اعرف كيف تستخدم عملاتك',
  description: '',
  reward: 7,
  status: 'ready_to_claim' as const,
  actionKey: 'coin_guide',
  requiresExternalVisit: false,
};
jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    jest.requireActual('react').useEffect(effect, [effect]);
  },
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/constants/distribution', () => ({
  IS_STORE_DISTRIBUTION: false,
  CAN_START_NATIVE_CHECKOUT: false,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  accountScopedStorageKey: async (key: string, boundary = mockBoundary) =>
    `${key}:${boundary.scope}`,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/walletEconomy'),
  ...jest.requireActual('../src/services/api/coinTasks'),
  hasSession: async () => true,
  getCoinPackages: async () => [],
  getCoinTasks: async () => [
    {...task, status: mockBalance > 20 ? 'claimed' : 'ready_to_claim'},
  ],
  getCourseDetailsSnapshot: async () => ({
    course: {id: '9', owned: false, price: 900, accessPlans: []},
  }),
  isCourseUnavailableError: () => false,
}));
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
}));
jest.mock('../src/screens/wallet/walletCache', () => ({
  readWalletCache: async () => null,
  saveWalletCache: async () => true,
}));
jest.mock('../src/services/coinCheckout', () => ({
  subscribeCoinCheckoutCredits: () => () => undefined,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.fn(),
  mapCoursePayload: jest.fn(),
}));

import {useWalletData} from '../src/screens/wallet/useWalletData';
import {useWalletTasks} from '../src/screens/wallet/useWalletTasks';
import {useCourseDetailsData} from '../src/screens/CourseDetails/details/useCourseDetailsData';
import {claimCoinTask} from '../src/services/api/coinTasks';
import {subscribeWalletSettlements} from '../src/services/walletSettlement';

const response = (data: Record<string, unknown>) => ({data: {data}});
const walletResponse = () =>
  response({
    total_balance: mockBalance,
    purchased_balance: 0,
    reward_balance: mockBalance,
    course_spendable_balance: mockBalance,
    reward_contribution_cap_per_course: 1200,
    spend_policy: 'reward_first_then_paid',
    coin_rules: [],
    recent_transactions:
      mockBalance > 20
        ? [
            {
              id: 'task-3-credit',
              direction: 'credit',
              amount: 7,
              label_ar: 'مكافأة مهمة',
            },
          ]
        : [],
  });
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};

describe('explicit task claim settlement after leaving its initiating wallet', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let wallet!: ReturnType<typeof useWalletData>;
  let actions!: ReturnType<typeof useWalletTasks>;
  let course!: ReturnType<typeof useCourseDetailsData>;
  const Wallet = () => {
    wallet = useWalletData(mockBoundary.scope);
    actions = useWalletTasks(wallet, () => undefined);
    return null;
  };
  const Course = () => {
    course = useCourseDetailsData({
      courseId: '9',
      identityKey: mockBoundary.scope,
    });
    return null;
  };
  beforeEach(async () => {
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
    mockBalance = 20;
    await AsyncStorage.clear();
    mockGet.mockImplementation(async endpoint => {
      expect(endpoint).toBe('wallet');
      return walletResponse();
    });
    jest
      .spyOn(AppState, 'addEventListener')
      .mockReturnValue({remove: jest.fn()});
    AppState.currentState = 'active';
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.restoreAllMocks();
  });
  it.each(['current wallet', 'wallet', 'course'])(
    'updates the visible %s after the original task button receives its ACK',
    async destination => {
      const claim = deferred<ReturnType<typeof response>>();
      mockPost.mockReturnValue(claim.promise);
      await act(async () => {
        renderer = TestRenderer.create(<Wallet key="original" />);
      });
      let submitting!: Promise<void>;
      await act(async () => {
        submitting = actions.handleTask(wallet.tasks[0]);
      });
      expect(mockPost).toHaveBeenCalledWith('claim-coins', {method_id: 3});
      const staysMounted = destination === 'current wallet';
      const showsWallet = destination !== 'course';
      if (!staysMounted)
        await act(async () => {
          renderer!.update(
            showsWallet ? <Wallet key="replacement" /> : <Course />,
          );
        });
      expect(
        showsWallet ? wallet.wallet?.balance : course.commerce.balance,
      ).toBe(20);
      expect(mockGet).toHaveBeenCalledTimes(staysMounted ? 1 : 2);
      await act(async () => {
        mockBalance = 27;
        claim.resolve(
          response({task_state: 'claimed', new_balance: 27, earned_amount: 7}),
        );
        await submitting;
      });
      expect(
        showsWallet ? wallet.wallet?.balance : course.commerce.balance,
      ).toBe(27);
      if (showsWallet) {
        expect(wallet.tasks[0].status).toBe('claimed');
        expect(wallet.wallet?.transactions).toEqual([
          {id: 'task-3-credit', amount: 7, label: 'مكافأة مهمة'},
        ]);
      }
      expect(mockPost).toHaveBeenCalledTimes(1);
      // A still-mounted task button deliberately also awaits its own queued
      // task-list refresh. This adds one bounded read, not another claim.
      expect(mockGet).toHaveBeenCalledTimes(3);
    },
  );

  it.each([7, 0])(
    'notifies once for joined valid claim callers including replay amount %i',
    async amount => {
      const claim = deferred<ReturnType<typeof response>>();
      mockPost.mockReturnValue(claim.promise);
      const observed = jest.fn();
      const stop = subscribeWalletSettlements(observed);
      try {
        const first = claimCoinTask(task, {...mockBoundary});
        const joined = claimCoinTask(task, {...mockBoundary});
        claim.resolve(
          response({
            task_state: 'claimed',
            new_balance: 27,
            earned_amount: amount,
          }),
        );
        await expect(Promise.all([first, joined])).resolves.toEqual([
          {balance: 27, amount},
          {balance: 27, amount},
        ]);
        expect(mockPost).toHaveBeenCalledTimes(1);
        expect(observed).toHaveBeenCalledTimes(1);
        expect(observed).toHaveBeenCalledWith(mockBoundary);
      } finally {
        stop();
      }
    },
  );

  it.each(['transport', 'state', 'balance', 'amount'])(
    'does not publish an unconfirmed task result (%s)',
    async failure => {
      const claim = deferred<ReturnType<typeof response>>();
      mockPost.mockReturnValue(claim.promise);
      const observed = jest.fn();
      const stop = subscribeWalletSettlements(observed);
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Wallet />);
        });
        const claiming = claimCoinTask(task, {...mockBoundary}).catch(
          error => error,
        );
        await act(async () => {
          if (failure === 'transport') claim.reject(new Error('response-lost'));
          else
            claim.resolve(
              response({
                task_state: failure === 'state' ? 'started' : 'claimed',
                new_balance: failure === 'balance' ? 'invalid' : 27,
                earned_amount: failure === 'amount' ? 'invalid' : 7,
              }),
            );
          expect(await claiming).toBeInstanceOf(Error);
        });
        expect(observed).not.toHaveBeenCalled();
        expect(mockGet).toHaveBeenCalledTimes(1);
        expect(wallet.wallet?.balance).toBe(20);
      } finally {
        stop();
      }
    },
  );

  it.each(['user-1', 'user-2'])(
    'ignores an old claim after a replacement session for %s',
    async scope => {
      const claim = deferred<ReturnType<typeof response>>();
      mockPost.mockReturnValue(claim.promise);
      const observed = jest.fn();
      const stop = subscribeWalletSettlements(observed);
      try {
        const claiming = claimCoinTask(task, {...mockBoundary}).catch(
          error => error,
        );
        mockBoundary = {scope, epoch: mockBoundary.epoch + 1};
        mockBalance = 50;
        await act(async () => {
          renderer = TestRenderer.create(<Wallet />);
        });
        await act(async () => {
          claim.resolve(
            response({
              task_state: 'claimed',
              new_balance: 27,
              earned_amount: 7,
            }),
          );
          expect(await claiming).toMatchObject({
            message: 'ACCOUNT_CHANGED_DURING_REQUEST',
          });
        });
        expect(observed).not.toHaveBeenCalled();
        expect(mockGet).toHaveBeenCalledTimes(1);
        expect(wallet.wallet?.balance).toBe(50);
      } finally {
        stop();
      }
    },
  );
});
