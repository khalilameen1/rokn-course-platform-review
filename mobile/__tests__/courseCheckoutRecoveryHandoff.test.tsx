import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import type {CourseAccessPlan} from '../src/services/roknApi';

const mockPost = jest.fn();
const mockBrowser = jest.fn();
const mockWallet = jest.fn();
const mockQuote = jest.fn();
const mockPurchase = jest.fn();
const mockStorage = new Map<string, unknown>();
let mockOwner = {scope: 'recovery-handoff', epoch: 1};
jest.mock('../src/constants/api', () => ({
  publicRequest: {post: (...args: unknown[]) => mockPost(...args)},
}));
jest.mock('expo-web-browser', () => ({
  openAuthSessionAsync: (...args: unknown[]) => mockBrowser(...args),
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
  getItem: async (key: string) => mockStorage.get(key) ?? null,
  saveItem: async (key: string, value: unknown) => {
    mockStorage.set(key, value);
    return true;
  },
  removeItem: async (key: string) => mockStorage.delete(key),
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
jest.mock('../src/services/roknApi', () => ({
  getWallet: (...args: unknown[]) => mockWallet(...args),
  quoteCoursePurchase: (...args: unknown[]) => mockQuote(...args),
  purchaseCourse: (...args: unknown[]) => mockPurchase(...args),
}));

import {useCourseCheckout} from '../src/screens/CourseDetails/details/useCourseCheckout';
import {
  reconcilePendingCoinCheckout,
  subscribeCoinCheckoutCredits,
} from '../src/services/coinCheckout';
import {
  getOrCreateCoinCheckoutAttempt,
  rememberCoinCheckoutOrder,
} from '../src/services/coinCheckoutAttemptStore';

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
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(accept => {
    resolve = accept;
  });
  return {promise, resolve};
};
const flush = async () => {
  for (let turn = 0; turn < 100; turn += 1) await Promise.resolve();
};
const response = (status: string) => ({
  data: {
    data: {
      status,
      financial_status:
        status === 'approved'
          ? 'settled'
          : status === 'pending'
          ? 'pending'
          : 'cancelled',
      package: {coins: 600},
    },
  },
});

describe('course top-up action joining an already running payment recovery', () => {
  it.each([
    'paid',
    'paid_other_package',
    'cancelled',
    'pending',
    'account_changed',
    'epoch_changed',
  ])('preserves the course action after recovery returns %s', async outcome => {
    jest.clearAllMocks();
    mockStorage.clear();
    mockOwner = {scope: `recovery-handoff-${outcome}`, epoch: 1};
    const requestedPackage =
      outcome === 'paid_other_package'
        ? {...coinPackage, id: '8', coins: 1200, price: 99}
        : coinPackage;
    const ownerChanges = outcome.endsWith('changed');
    const expectsNewPayment = outcome === 'cancelled' || outcome === 'pending';
    const providerOrder =
      outcome === 'pending' ? 'PKG-OLD-ORDER' : 'PKG-NEW-ORDER';
    const oldStatus = deferred<ReturnType<typeof response>>();
    mockPost.mockImplementation(
      async (path: string, body?: {idempotency_key: string}) => {
        if (path === 'payment/reconcile/PKG-OLD-ORDER')
          return oldStatus.promise;
        if (path === 'payment/initiate')
          return {
            data: {
              data: {
                payment_url: 'https://checkout.kashier.io/new-session',
                order_ref: providerOrder,
                idempotency_key: body!.idempotency_key,
              },
            },
          };
        if (path === `payment/abandon/${providerOrder}`)
          return response('cancelled');
        throw new Error(`Unexpected test request ${path}`);
      },
    );
    mockBrowser.mockResolvedValue({type: 'cancel'});
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
    const attempt = await getOrCreateCoinCheckoutAttempt(7, 49, 600, mockOwner);
    await rememberCoinCheckoutOrder(attempt, 'PKG-OLD-ORDER', mockOwner);
    const showConfirm = jest.fn();
    const showSuccess = jest.fn();
    const setOwned = jest.fn();
    const updateWallet = jest.fn();
    const credit = jest.fn(() => {
      if (ownerChanges) {
        mockOwner = {
          scope:
            outcome === 'account_changed' ? 'replacement' : mockOwner.scope,
          epoch: 2,
        };
      }
    });
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
        packages: [requestedPackage],
        closePurchase: jest.fn(),
        invalidateCoupon: jest.fn(),
        replaceCouponQuote: jest.fn(),
        showConfirm,
        showPlans: jest.fn(),
        showSuccess,
        showTopup: jest.fn(),
        setNotice: jest.fn(),
        setOwned,
        setPackages: jest.fn(),
        updateWallet,
        reload: jest.fn(),
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      const recovery = reconcilePendingCoinCheckout();
      await flush();
      expect(mockPost.mock.calls.map(([path]) => path)).toEqual([
        'payment/reconcile/PKG-OLD-ORDER',
      ]);
      let tap!: Promise<void>;
      await act(async () => {
        tap = checkout.buyCoins(requestedPackage);
        await flush();
      });
      expect(mockBrowser).not.toHaveBeenCalled();
      await act(async () => {
        oldStatus.resolve(response(expectsNewPayment ? outcome : 'approved'));
        await recovery;
        await tap;
      });
      if (expectsNewPayment) {
        expect(mockPost.mock.calls.map(([path]) => path)).toEqual([
          'payment/reconcile/PKG-OLD-ORDER',
          ...(outcome === 'pending' ? ['payment/reconcile/PKG-OLD-ORDER'] : []),
          'payment/initiate',
          `payment/abandon/${providerOrder}`,
        ]);
        expect(mockBrowser).toHaveBeenCalledTimes(1);
        if (outcome === 'pending') {
          expect(mockPost).toHaveBeenCalledWith(
            'payment/initiate',
            expect.objectContaining({
              idempotency_key: attempt.idempotencyKey,
            }),
            expect.objectContaining({
              headers: {'Idempotency-Key': attempt.idempotencyKey},
            }),
          );
        }
        expect(credit).not.toHaveBeenCalled();
        expect(showConfirm).not.toHaveBeenCalled();
      } else {
        expect(mockPost.mock.calls.map(([path]) => path)).toEqual([
          'payment/reconcile/PKG-OLD-ORDER',
        ]);
        expect(mockBrowser).not.toHaveBeenCalled();
        expect(credit).toHaveBeenCalledTimes(1);
        if (ownerChanges) {
          expect(updateWallet).not.toHaveBeenCalled();
          expect(mockWallet).not.toHaveBeenCalled();
          expect(mockQuote).not.toHaveBeenCalled();
          expect(showConfirm).not.toHaveBeenCalled();
        } else {
          expect(updateWallet).toHaveBeenCalledWith(
            expect.objectContaining({paidBalance: 600}),
          );
          expect(showConfirm).toHaveBeenCalledTimes(1);
        }
      }
      expect(mockPurchase).not.toHaveBeenCalled();
      expect(checkout.busy).toBe(false);
      if (outcome === 'paid') {
        mockPurchase.mockResolvedValueOnce({
          kind: 'success',
          balance: 0,
          paidBalance: 0,
          rewardBalance: 0,
          rewardContributionCap: 100,
        });
        await act(async () => checkout.confirm());
        expect(mockPurchase).toHaveBeenCalledTimes(1);
        expect(mockPurchase).toHaveBeenCalledWith(
          '3',
          'guided',
          undefined,
          600,
          1,
        );
        expect(setOwned).toHaveBeenCalledWith(true);
        expect(showSuccess).toHaveBeenCalledTimes(1);
        expect(mockBrowser).not.toHaveBeenCalled();
        expect(credit).toHaveBeenCalledTimes(1);
      }
    } finally {
      unsubscribe();
      await act(async () => renderer?.unmount());
    }
  });
});
