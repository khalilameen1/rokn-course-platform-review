import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';

let mockBoundary = {scope: 'learner', epoch: 1};
let mockUuidSequence = 0;
const mockPost = jest.fn();
jest.mock('../src/constants/api', () => ({
  publicRequest: {post: (...args: unknown[]) => mockPost(...args)},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  accountScopedStorageKey: async (key: string, boundary: {scope: string}) =>
    `${key}:${boundary.scope}`,
  assertAccountSessionBoundary: (boundary: {scope: string; epoch: number}) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  saveItem: async (key: string, value: unknown) => {
    try {
      await require('@react-native-async-storage/async-storage').setItem(
        key,
        JSON.stringify(value),
      );
      return true;
    } catch {
      return false;
    }
  },
  removeItem: async (key: string) =>
    require('@react-native-async-storage/async-storage').removeItem(key),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () =>
    `00000000-0000-4000-8000-${String(++mockUuidSequence).padStart(12, '0')}`,
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_COIN_CHECKOUT: true,
}));
jest.mock('../src/services/coinCheckout', () => ({
  openCoinCheckout: jest.fn(),
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/coursePurchase'),
  getCoinPackages: jest.fn(),
  getWallet: jest.fn(),
}));

import {purchaseCourse} from '../src/services/api/coursePurchase';
import {purchaseFullTrackUpgrade} from '../src/services/api/courseUpgrade';
import {getOrCreateCoursePurchaseAttemptKey} from '../src/services/api/courseAccessAttemptStore';
import {useCourseCheckout} from '../src/screens/CourseDetails/details/useCourseCheckout';

const rawGet = jest.mocked(AsyncStorage.getItem).getMockImplementation()!;
const rawSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const rawRemove = jest.mocked(AsyncStorage.removeItem).getMockImplementation()!;
const deferred = () => {
  let resolve!: () => void;
  const promise = new Promise<void>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const flush = async () => {
  for (let step = 0; step < 40; step += 1) await Promise.resolve();
};
const success = (route: string) => ({
  data: {
    data:
      route === 'courses/authorize'
        ? {
            total_balance: 300,
            spendable_balance: 300,
            purchased_balance: 300,
            reward_balance: 0,
            original_price: 100,
            discount_amount: 0,
          }
        : {
            course_revision: 4,
            already_upgraded: true,
            chat_available: true,
            ai_included: true,
            certificate_available: true,
            target_plan_code: 'mentor',
          },
  },
});
const attempts = [
  {
    name: 'purchase',
    run: (id = '42') => purchaseCourse(id, 'guided', '', 100, 4),
  },
  {
    name: 'upgrade',
    run: (id = '42') => purchaseFullTrackUpgrade(id, 'mentor', 100, 4),
  },
];

describe('confirmed course access does not wait for native terminal cleanup', () => {
  beforeEach(async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    jest.mocked(AsyncStorage.getItem).mockImplementation(rawGet);
    jest.mocked(AsyncStorage.setItem).mockImplementation(rawSet);
    jest.mocked(AsyncStorage.removeItem).mockImplementation(rawRemove);
    await AsyncStorage.clear();
    mockBoundary = {scope: 'learner', epoch: 1};
    mockPost.mockImplementation(async (route: string) => success(route));
  });
  afterEach(() => {
    jest.mocked(AsyncStorage.getItem).mockImplementation(rawGet);
    jest.mocked(AsyncStorage.setItem).mockImplementation(rawSet);
    jest.mocked(AsyncStorage.removeItem).mockImplementation(rawRemove);
    jest.useRealTimers();
  });

  it.each(attempts)(
    'returns confirmed $name while its cleanup read is stalled',
    async entry => {
      const storage = deferred();
      mockPost.mockImplementation(async (route: string) => {
        jest.mocked(AsyncStorage.getItem).mockImplementationOnce(async key => {
          await storage.promise;
          return rawGet(key);
        });
        return success(route);
      });
      let settled = false;
      const operation = entry.run().then(value => {
        settled = true;
        return value;
      });
      try {
        await flush();
        expect(mockPost).toHaveBeenCalledTimes(1);
        await jest.advanceTimersByTimeAsync(2000);
        expect(settled).toBe(true);
        expect(mockPost).toHaveBeenCalledTimes(1);
      } finally {
        storage.resolve();
        await operation;
      }
    },
  );

  it('does not block a different course behind completed-access cleanup', async () => {
    const storage = deferred();
    mockPost.mockImplementationOnce(async (route: string) => {
      jest.mocked(AsyncStorage.getItem).mockImplementationOnce(async key => {
        await storage.promise;
        return rawGet(key);
      });
      return success(route);
    });
    const first = purchaseCourse('42', 'guided', '', 100, 4);
    await flush();
    let secondSettled = false;
    const second = purchaseCourse('43', 'guided', '', 100, 4).then(value => {
      secondSettled = true;
      return value;
    });
    try {
      await jest.advanceTimersByTimeAsync(2000);
      expect(secondSettled).toBe(true);
      expect(mockPost).toHaveBeenCalledTimes(2);
    } finally {
      storage.resolve();
      await Promise.all([first, second]);
    }
  });

  it('keeps the real same-key cleanup ahead of a newly stored attempt after its wait expires', async () => {
    const storage = deferred();
    let removedKey = '';
    mockPost.mockImplementationOnce(async (route: string) => {
      jest.mocked(AsyncStorage.removeItem).mockImplementationOnce(async key => {
        removedKey = key;
        await storage.promise;
        return rawRemove(key);
      });
      return success(route);
    });
    const purchase = purchaseCourse('42', 'guided', '', 100, 4);
    let nextAttempt: Promise<string> | undefined;
    try {
      await jest.advanceTimersByTimeAsync(2000);
      await expect(purchase).resolves.toMatchObject({kind: 'success'});
      const previousId = mockPost.mock.calls[0][1].idempotency_key;
      let nextSettled = false;
      nextAttempt = getOrCreateCoursePurchaseAttemptKey(
        {courseId: 42, accessPlanCode: 'guided', couponCode: ''},
        mockBoundary,
      ).then(value => {
        nextSettled = true;
        return value;
      });
      await flush();
      expect(nextSettled).toBe(false);
      expect(AsyncStorage.setItem).toHaveBeenCalledTimes(1);
      storage.resolve();
      const nextId = await nextAttempt;
      expect(nextId).not.toBe(previousId);
      expect(JSON.parse(String(await rawGet(removedKey))).idempotencyKey).toBe(
        nextId,
      );
      expect(mockPost).toHaveBeenCalledTimes(1);
    } finally {
      storage.resolve();
      await Promise.all([purchase, nextAttempt]);
      await flush();
    }
  });

  it.each(attempts)(
    'does not adopt $name for a new account when terminal cleanup exceeds its wait',
    async entry => {
      const storage = deferred();
      mockPost.mockImplementationOnce(async (route: string) => {
        jest.mocked(AsyncStorage.getItem).mockImplementationOnce(async key => {
          await storage.promise;
          return rawGet(key);
        });
        return success(route);
      });
      const operation = entry.run().then(
        value => ({value, error: undefined}),
        error => ({value: undefined, error}),
      );
      try {
        await flush();
        expect(mockPost).toHaveBeenCalledTimes(1);
        mockBoundary = {scope: 'another-learner', epoch: 2};
        await jest.advanceTimersByTimeAsync(2000);
        expect((await operation).error?.message).toBe(
          'ACCOUNT_CHANGED_DURING_REQUEST',
        );
        expect((await operation).value).toBeUndefined();
        expect(AsyncStorage.removeItem).not.toHaveBeenCalled();
      } finally {
        storage.resolve();
        await flush();
      }
    },
  );

  it.each(attempts)(
    'does not send $name without its durable request identity',
    async entry => {
      jest
        .mocked(AsyncStorage.setItem)
        .mockRejectedValueOnce(new Error('disk full'));
      await expect(entry.run()).rejects.toThrow(/IDEMPOTENCY_UNAVAILABLE/);
      expect(mockPost).not.toHaveBeenCalled();
    },
  );

  it.each(attempts)(
    'does not report $name success from a malformed HTTP acknowledgement',
    async entry => {
      mockPost.mockResolvedValueOnce({data: {data: {}}});
      await expect(entry.run()).rejects.toThrow(/API_CONTRACT_INVALID/);
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(AsyncStorage.removeItem).not.toHaveBeenCalled();
      expect(await AsyncStorage.getAllKeys()).toHaveLength(1);
    },
  );

  it.each(attempts)(
    'retains the original identity for an explicitly retried uncertain $name',
    async entry => {
      mockPost.mockRejectedValueOnce(new Error('connection lost'));
      await expect(entry.run()).rejects.toThrow('connection lost');
      const originalId = mockPost.mock.calls[0][1].idempotency_key;
      expect(AsyncStorage.removeItem).not.toHaveBeenCalled();
      await entry.run();
      expect(mockPost).toHaveBeenCalledTimes(2);
      expect(mockPost.mock.calls[1][1].idempotency_key).toBe(originalId);
      expect(await AsyncStorage.getAllKeys()).toHaveLength(0);
    },
  );

  it('lets the real checkout display access and leave its busy state after the server accepts', async () => {
    const storage = deferred();
    mockPost.mockImplementation(async (route: string) => {
      jest.mocked(AsyncStorage.removeItem).mockImplementationOnce(async key => {
        await storage.promise;
        return rawRemove(key);
      });
      return success(route);
    });
    const setOwned = jest.fn();
    const showSuccess = jest.fn();
    const updateWallet = jest.fn();
    let checkout!: ReturnType<typeof useCourseCheckout>;
    const Harness = () => {
      checkout = useCourseCheckout({
        closePurchase: jest.fn(),
        couponApplied: false,
        courseId: '42',
        effectivePrice: 100,
        identityKey: 'learner',
        invalidateCoupon: jest.fn(),
        packages: [],
        publishedRevision: 4,
        purchasePrice: 100,
        reload: jest.fn(),
        replaceCouponQuote: jest.fn(),
        selectedPlan: {code: 'guided', priceCoins: 100} as never,
        shortfall: 0,
        showConfirm: jest.fn(),
        showPlans: jest.fn(),
        showSuccess,
        showTopup: jest.fn(),
        setNotice: jest.fn(),
        setOwned,
        setPackages: jest.fn(),
        updateWallet,
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    let confirmation!: Promise<void>;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      await act(async () => {
        confirmation = checkout.confirm();
        await flush();
      });
      await act(async () => {
        await jest.advanceTimersByTimeAsync(2000);
      });
      expect(setOwned).toHaveBeenCalledWith(true);
      expect(showSuccess).toHaveBeenCalledTimes(1);
      expect(updateWallet).toHaveBeenCalledWith(
        expect.objectContaining({kind: 'success', balance: 300}),
      );
      expect(checkout.busy).toBe(false);
      expect(mockPost).toHaveBeenCalledTimes(1);
    } finally {
      await act(async () => {
        storage.resolve();
        await confirmation;
        renderer?.unmount();
      });
    }
  });
});
