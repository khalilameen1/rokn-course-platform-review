import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
import type {CourseAccessPlan} from '../src/services/roknApi';

const mockPost = jest.fn();
const mockWallet = jest.fn();
const mockQuote = jest.fn();
const mockPurchase = jest.fn();
let mockOwner = {scope: 'buyer', epoch: 1};
let mockCancel = false;
jest.mock('../src/constants/api', () => ({
  publicRequest: {post: (...args: unknown[]) => mockPost(...args)},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockOwner}),
  assertAccountSessionBoundary: (owner: typeof mockOwner) => {
    if (owner.scope !== mockOwner.scope || owner.epoch !== mockOwner.epoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: async (key: string, owner = mockOwner) =>
    `${key}:${owner.scope}`,
  getItem: async (key: string) => {
    const value =
      await require('@react-native-async-storage/async-storage').getItem(key);
    return value === null ? null : JSON.parse(value);
  },
  saveItem: async (key: string, value: unknown) => {
    await require('@react-native-async-storage/async-storage').setItem(
      key,
      JSON.stringify(value),
    );
    return true;
  },
  removeItem: async (key: string) => {
    await require('@react-native-async-storage/async-storage').removeItem(key);
    return true;
  },
}));
jest.mock('../src/constants/distribution', () => ({
  DISTRIBUTION_CHANNEL: 'direct',
  CAN_START_EXTERNAL_CHECKOUT: true,
  CAN_START_NATIVE_CHECKOUT: false,
  CAN_START_COIN_CHECKOUT: true,
}));
jest.mock('../src/services/productFeatures', () => ({
  requireProductFeature: async () => undefined,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));
jest.mock('../src/services/coinCheckoutProvider', () => ({
  openCoinCheckoutSurface: async () => {
    if (mockCancel)
      throw Object.assign(new Error('cancelled'), {code: 'CHECKOUT_CANCELLED'});
    return 'provider-callback';
  },
  parseCoinCheckoutCallback: () => ({valid: true}),
}));
jest.mock('../src/services/roknApi', () => ({
  getWallet: (...args: unknown[]) => mockWallet(...args),
  quoteCoursePurchase: (...args: unknown[]) => mockQuote(...args),
  purchaseCourse: (...args: unknown[]) => mockPurchase(...args),
}));

import {useCourseCheckout} from '../src/screens/CourseDetails/details/useCourseCheckout';
import {
  openCoinCheckout,
  reconcilePendingCoinCheckout,
  subscribeCoinCheckoutCredits,
} from '../src/services/coinCheckout';
import {
  clearCoinCheckoutAttempt,
  getOrCreateCoinCheckoutAttempt,
  readCoinCheckoutAttempt,
  rememberCoinCheckoutOrder,
} from '../src/services/coinCheckoutAttemptStore';
import {
  savePendingCheckoutReturn,
  claimPendingCheckoutReturn,
} from '../src/navigation/checkoutReturn';

const coinPackage = {id: '7', coins: 600, price: 49, label: 'باقة'};
const plan: CourseAccessPlan = {
  code: 'guided',
  name: 'إرشاد',
  priceCoins: 600,
  minimumPaidCoins: 0,
  chatEnabled: false,
  chatMessageLimit: 0,
  projectFeedbackLevel: 'report',
  projectReportEnabled: true,
  projectOutputEnabled: true,
  certificateEnabled: true,
};
const deferred = () => {
  let resolve!: () => void;
  const promise = new Promise<void>(yes => {
    resolve = yes;
  });
  return {promise, resolve};
};

describe('confirmed top-up delivery across terminal local storage', () => {
  it.each([
    'attempt_read',
    'attempt_remove',
    'return_read',
    'return_remove',
    'attempt_remove_account_change',
    'return_remove_account_change',
    'attempt_remove_cancel',
  ])(
    'reaches explicit course confirmation while %s is stalled',
    async stage => {
      jest.useFakeTimers();
      jest.clearAllMocks();
      mockOwner = {scope: stage, epoch: 1};
      mockCancel = stage.endsWith('cancel');
      const disk = new Map<string, string>();
      const blocked = deferred();
      const started = deferred();
      let paid = false;
      const prefix = stage.startsWith('attempt')
        ? '@rokn/coin-checkout-attempt/'
        : '@rokn/pending-checkout-return/';
      const wait = async (method: string, key: string) => {
        if (paid && stage.split('_')[1] === method && key.startsWith(prefix)) {
          started.resolve();
          await blocked.promise;
        }
      };
      (AsyncStorage.getItem as jest.Mock).mockImplementation(
        async (key: string) => {
          await wait('read', key);
          return disk.get(key) ?? null;
        },
      );
      (AsyncStorage.setItem as jest.Mock).mockImplementation(
        async (key: string, value: string) => {
          disk.set(key, value);
        },
      );
      (AsyncStorage.removeItem as jest.Mock).mockImplementation(
        async (key: string) => {
          await wait('remove', key);
          disk.delete(key);
        },
      );
      mockPost.mockImplementation(
        async (path: string, body?: {idempotency_key: string}) => {
          if (path === 'payment/initiate')
            return {
              data: {
                data: {
                  payment_url: 'https://checkout.kashier.io/session',
                  order_ref: 'PKG-SETTLED-7',
                  idempotency_key: body!.idempotency_key,
                },
              },
            };
          if (
            path === 'payment/reconcile/PKG-SETTLED-7' ||
            path === 'payment/abandon/PKG-SETTLED-7'
          ) {
            paid = true;
            return {
              data: {
                data: {
                  status: mockCancel ? 'cancelled' : 'approved',
                  financial_status: mockCancel ? 'unsettled' : 'settled',
                  package: {coins: 600},
                },
              },
            };
          }
          throw new Error(`Unexpected test request ${path}`);
        },
      );
      mockWallet.mockResolvedValue({
        balance: 600,
        paidBalance: 600,
        rewardBalance: 0,
        rewardContributionCap: 100,
      });
      mockQuote.mockResolvedValue({
        accessPlanCode: 'guided',
        courseRevision: 1,
        originalPrice: 600,
        finalPrice: 600,
        couponCode: '',
      });
      const showConfirm = jest.fn();
      const updateWallet = jest.fn();
      const credit = jest.fn();
      const unsubscribe = subscribeCoinCheckoutCredits(credit);
      let checkout!: ReturnType<typeof useCourseCheckout>;
      const Harness = () => {
        checkout = useCourseCheckout({
          courseId: '3',
          identityKey: mockOwner.scope,
          selectedPlan: plan,
          couponApplied: false,
          effectivePrice: 600,
          purchasePrice: 600,
          shortfall: 600,
          publishedRevision: 1,
          packages: [coinPackage],
          closePurchase: jest.fn(),
          invalidateCoupon: jest.fn(),
          replaceCouponQuote: jest.fn(),
          showConfirm,
          showPlans: jest.fn(),
          showSuccess: jest.fn(),
          showTopup: jest.fn(),
          setNotice: jest.fn(),
          setOwned: jest.fn(),
          setPackages: jest.fn(),
          updateWallet,
          reload: jest.fn(),
        });
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      let flight!: Promise<void>;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Harness />);
        });
        await act(async () => {
          flight = checkout.buyCoins(coinPackage);
          await jest.advanceTimersByTimeAsync(0);
          expect(mockPost.mock.calls.map(([path]) => path)).toEqual([
            'payment/initiate',
            `payment/${mockCancel ? 'abandon' : 'reconcile'}/PKG-SETTLED-7`,
          ]);
          if (stage.endsWith('account_change')) {
            mockOwner = {scope: 'replacement', epoch: 2};
            renderer.update(<Harness />);
          }
          await jest.advanceTimersByTimeAsync(800);
        });
        expect(checkout.busy).toBe(false);
        if (mockCancel) {
          expect(showConfirm).not.toHaveBeenCalled();
          expect(updateWallet).not.toHaveBeenCalled();
          expect(credit).not.toHaveBeenCalled();
        } else if (stage.endsWith('account_change')) {
          expect(showConfirm).not.toHaveBeenCalled();
          expect(updateWallet).not.toHaveBeenCalled();
          // Return cleanup happens after A's credit event; it must not emit
          // another event or deliver the course result into replacement B.
          expect(credit).toHaveBeenCalledTimes(
            stage.startsWith('return') ? 1 : 0,
          );
        } else {
          expect(showConfirm).toHaveBeenCalledTimes(1);
          expect(updateWallet).toHaveBeenCalledWith(
            expect.objectContaining({balance: 600}),
          );
          expect(credit).toHaveBeenCalledTimes(1);
        }
        expect(mockPost).toHaveBeenCalledTimes(2);
        expect(mockPurchase).not.toHaveBeenCalled();
      } finally {
        blocked.resolve();
        await act(async () => {
          await flight;
          renderer?.unmount();
        });
        unsubscribe();
        jest.useRealTimers();
        mockCancel = false;
      }
    },
  );

  it('keeps raw removal ahead of a subsequent required attempt write', async () => {
    jest.useFakeTimers();
    mockOwner = {scope: 'attempt-order', epoch: 1};
    const disk = new Map<string, string>();
    const blocked = deferred();
    const started = deferred();
    (AsyncStorage.getItem as jest.Mock).mockImplementation(
      async key => disk.get(key) ?? null,
    );
    (AsyncStorage.setItem as jest.Mock).mockImplementation(
      async (key, value) => {
        disk.set(key, value);
      },
    );
    (AsyncStorage.removeItem as jest.Mock).mockImplementation(async key => {
      started.resolve();
      await blocked.promise;
      disk.delete(key);
    });
    const initial = await getOrCreateCoinCheckoutAttempt(7, 49, 600, mockOwner);
    let nextSettled = false;
    let next: ReturnType<typeof getOrCreateCoinCheckoutAttempt> | undefined;
    const retirement = clearCoinCheckoutAttempt(
      initial.idempotencyKey,
      mockOwner,
    );
    try {
      await started.promise;
      await jest.advanceTimersByTimeAsync(800);
      await retirement;
      next = getOrCreateCoinCheckoutAttempt(8, 99, 1200, mockOwner).then(
        value => {
          nextSettled = true;
          return value;
        },
      );
      await jest.advanceTimersByTimeAsync(800);
      expect(nextSettled).toBe(false);
    } finally {
      blocked.resolve();
      await retirement;
      await next;
      jest.useRealTimers();
    }
    expect(await readCoinCheckoutAttempt(8, mockOwner)).toMatchObject({
      packageId: 8,
      expectedCoins: 1200,
    });
    expect(await readCoinCheckoutAttempt(7, mockOwner)).toBeNull();
  });

  it('preserves a new return destination behind the late removal of the old paid receipt', async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockOwner = {scope: 'return-order', epoch: 1};
    const disk = new Map<string, string>();
    const blocked = deferred();
    const started = deferred();
    (AsyncStorage.getItem as jest.Mock).mockImplementation(
      async key => disk.get(key) ?? null,
    );
    (AsyncStorage.setItem as jest.Mock).mockImplementation(
      async (key, value) => {
        disk.set(key, value);
      },
    );
    (AsyncStorage.removeItem as jest.Mock).mockImplementation(
      async (key: string) => {
        if (key.startsWith('@rokn/pending-checkout-return/')) {
          started.resolve();
          await blocked.promise;
        }
        disk.delete(key);
      },
    );
    mockPost.mockResolvedValue({
      data: {
        data: {
          checkout_state: 'paid',
          order_status: 'approved',
          financial_status: 'settled',
          order_ref: 'PKG-OLD-SETTLED',
          coins_added: 600,
          package: {id: 7, coins: 600},
        },
      },
    });
    const paid = openCoinCheckout(coinPackage, {
      returnTo: {
        name: 'CourseDetails',
        params: {courseId: '3', openPurchase: true},
      },
    });
    let replacement: ReturnType<typeof savePendingCheckoutReturn> | undefined;
    let replacementWritten = false;
    try {
      await started.promise;
      await jest.advanceTimersByTimeAsync(800);
      await expect(paid).resolves.toMatchObject({success: true});
      replacement = savePendingCheckoutReturn(
        {name: 'CourseDetails', params: {courseId: '4', openPurchase: true}},
        mockOwner,
      ).then(value => {
        replacementWritten = true;
        return value;
      });
      await jest.advanceTimersByTimeAsync(800);
      expect(replacementWritten).toBe(false);
      expect(mockPost).toHaveBeenCalledTimes(1);
    } finally {
      blocked.resolve();
      await paid;
      await replacement;
      jest.useRealTimers();
    }
    expect((await claimPendingCheckoutReturn())?.returnTo).toMatchObject({
      name: 'CourseDetails',
      params: {courseId: '4'},
    });
  });

  it('still requires durable pre-payment intent storage before sending or opening payment', async () => {
    jest.clearAllMocks();
    mockOwner = {scope: 'failed-presend', epoch: 1};
    (AsyncStorage.getItem as jest.Mock).mockResolvedValue(null);
    (AsyncStorage.setItem as jest.Mock).mockRejectedValue(
      new Error('native disk unavailable'),
    );
    await expect(openCoinCheckout(coinPackage)).rejects.toThrow(
      'native disk unavailable',
    );
    expect(mockPost).not.toHaveBeenCalled();
  });

  it('emits foreground recovered credit once without reopening payment while terminal removal waits', async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockOwner = {scope: 'foreground-recovery', epoch: 1};
    const disk = new Map<string, string>();
    const blocked = deferred();
    const started = deferred();
    (AsyncStorage.getItem as jest.Mock).mockImplementation(
      async key => disk.get(key) ?? null,
    );
    (AsyncStorage.setItem as jest.Mock).mockImplementation(
      async (key, value) => {
        disk.set(key, value);
      },
    );
    (AsyncStorage.removeItem as jest.Mock).mockImplementation(async key => {
      started.resolve();
      await blocked.promise;
      disk.delete(key);
    });
    const attempt = await getOrCreateCoinCheckoutAttempt(7, 49, 600, mockOwner);
    await rememberCoinCheckoutOrder(attempt, 'PKG-RECOVERED-7', mockOwner);
    mockPost.mockResolvedValue({
      data: {
        data: {
          status: 'approved',
          financial_status: 'settled',
          package: {coins: 600},
        },
      },
    });
    const credit = jest.fn();
    const unsubscribe = subscribeCoinCheckoutCredits(credit);
    const flight = reconcilePendingCoinCheckout();
    try {
      await started.promise;
      await jest.advanceTimersByTimeAsync(800);
      await expect(flight).resolves.toMatchObject({
        success: true,
        coinsAdded: 600,
        orderRef: 'PKG-RECOVERED-7',
      });
      expect(credit).toHaveBeenCalledTimes(1);
      expect(mockPost.mock.calls.map(([path]) => path)).toEqual([
        'payment/reconcile/PKG-RECOVERED-7',
      ]);
      expect(mockPurchase).not.toHaveBeenCalled();
    } finally {
      blocked.resolve();
      await flight;
      await clearCoinCheckoutAttempt(attempt.idempotencyKey, mockOwner);
      unsubscribe();
      jest.useRealTimers();
    }
    expect(credit).toHaveBeenCalledTimes(1);
  });
});
