import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {useCourseSubscriptionCheckout} from '../src/hooks/useCourseSubscriptionCheckout';
import {emitNativeStoreCreditOnce} from '../src/services/nativeStoreCredits';
import {emitCoinCheckoutCreditOnce} from '../src/services/coinCheckoutCoordinator';
import type {CourseCheckout} from '../src/services/api/courseCheckout';

let mockAccount = 'user-7';
let mockEpoch = 1;
const mockLatest = jest.fn();
const mockResume = jest.fn();
const mockAuthorize = jest.fn();
const mockOpenPayment = jest.fn();
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({
    scope: mockAccount,
    epoch: mockEpoch,
  }),
  assertAccountSessionBoundary: (boundary: {scope: string; epoch: number}) => {
    if (boundary.scope !== mockAccount || boundary.epoch !== mockEpoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: async (key: string) => `${key}:${mockAccount}`,
}));
jest.mock('../src/services/checkoutRouting', () => ({
  courseCheckoutTransport: {
    kind: 'native',
    channel: 'appstore',
    apiChannel: 'apple',
  },
}));
jest.mock('../src/services/coinCheckout', () => ({
  openCoinCheckout: (...args: unknown[]) => mockOpenPayment(...args),
}));
jest.mock('../src/services/roknApi', () => ({getCoinPackages: jest.fn()}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));
jest.mock('../src/services/api/courseCheckout', () => ({
  getLatestCourseCheckout: (...args: unknown[]) => mockLatest(...args),
  resumeCourseCheckout: (...args: unknown[]) => mockResume(...args),
  authorizeCourseCheckout: (...args: unknown[]) => mockAuthorize(...args),
  quoteCourseCheckout: jest.fn(),
  getCourseCheckout: jest.fn(),
  cancelCourseCheckout: jest.fn(),
  selectCheckoutPackage: jest.fn(),
}));

const pending: CourseCheckout = {
  id: 'apple-upgrade',
  channel: 'apple',
  status: 'pending_payment',
  courseId: '3',
  planCode: 'mentor',
  courseRevision: 9,
  originalPrice: 250,
  discountAmount: 0,
  finalPrice: 250,
  paidCoins: 250,
  rewardCoins: 0,
  paidBalance: 0,
  rewardBalance: 0,
  deficit: 250,
  remainingPaidCoins: 650,
  remainingRewardCoins: 0,
  expiresAt: '2099-01-01T00:00:00Z',
  packages: [],
};
const credit = {
  success: true,
  pending: false,
  cancelled: false,
  coinsAdded: 900,
  orderRef: 'confirmed-apple-order',
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
let receiptSequence = 0;
const emitNative = () =>
  emitNativeStoreCreditOnce(
    `credit-recovery-${++receiptSequence}`,
    credit,
    '@rokn/native-store-reconciliation/v1:user-7',
  );

describe('course checkout credit observers share authoritative reconciliation', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockAccount = 'user-7';
    mockEpoch = 1;
    mockLatest.mockReset().mockResolvedValue(pending);
    mockResume.mockReset().mockResolvedValue(pending);
  });

  const mount = async () => {
    let current!: ReturnType<typeof useCourseSubscriptionCheckout>;
    const completed = jest.fn();
    const Probe = ({visible = true}) => {
      current = useCourseSubscriptionCheckout({
        courseId: '3',
        planCode: 'mentor',
        mode: 'upgrade',
        visible,
        onCompleted: completed,
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Probe />);
    });
    expect(current.pending).toBe(true);
    mockResume.mockClear();
    return {
      get current() {
        return current;
      },
      completed,
      renderer,
      Probe,
    };
  };

  it('coalesces simultaneous native and external signals and completes only after the server acknowledgement', async () => {
    const screen = await mount();
    const reconciliation = deferred<CourseCheckout>();
    mockResume.mockReturnValueOnce(reconciliation.promise);
    try {
      await act(async () => {
        await emitNative();
        emitCoinCheckoutCreditOnce({scope: 'user-7', epoch: 1}, {
          ...credit,
          orderRef: 'dual-credit-recovery',
        });
      });
      expect(mockResume).toHaveBeenCalledTimes(1);
      expect(mockResume).toHaveBeenCalledWith(pending.id);
      expect(screen.current.pending).toBe(true);
      expect(screen.completed).not.toHaveBeenCalled();
      expect(mockAuthorize).not.toHaveBeenCalled();
      expect(mockOpenPayment).not.toHaveBeenCalled();
      await act(async () => {
        reconciliation.resolve({...pending, status: 'completed'});
      });
      expect(screen.completed).toHaveBeenCalledTimes(1);
      expect(screen.current.pending).toBe(false);
      await act(async () => {
        await emitNative();
      });
      expect(mockResume).toHaveBeenCalledTimes(1);
      expect(screen.completed).toHaveBeenCalledTimes(1);
    } finally {
      reconciliation.resolve(pending);
      await act(async () => screen.renderer.unmount());
    }
  });

  it('does not treat the credited coins as enrollment if the server is still pending', async () => {
    const screen = await mount();
    try {
      await act(async () => {
        await emitNative();
      });
      expect(mockResume).toHaveBeenCalledTimes(1);
      expect(screen.current.pending).toBe(true);
      expect(screen.completed).not.toHaveBeenCalled();
      expect(mockOpenPayment).not.toHaveBeenCalled();
    } finally {
      await act(async () => screen.renderer.unmount());
    }
  });

  it('unsubscribes when the sheet closes and recovers through the existing latest-intent read on reopening', async () => {
    const screen = await mount();
    try {
      await act(async () => {
        screen.renderer.update(<screen.Probe visible={false} />);
      });
      await act(async () => {
        await emitNative();
      });
      expect(mockResume).not.toHaveBeenCalled();
      expect(screen.completed).not.toHaveBeenCalled();
      mockResume.mockResolvedValueOnce({...pending, status: 'completed'});
      await act(async () => {
        screen.renderer.update(<screen.Probe />);
      });
      expect(mockResume).toHaveBeenCalledTimes(1);
      expect(screen.completed).toHaveBeenCalledTimes(1);
    } finally {
      await act(async () => screen.renderer.unmount());
    }
  });

  it('does not deliver a foreign-account receipt to the current sheet', async () => {
    const screen = await mount();
    try {
      mockAccount = 'user-8';
      mockEpoch += 1;
      await act(async () => {
        await emitNative();
      });
      expect(mockResume).not.toHaveBeenCalled();
      expect(screen.completed).not.toHaveBeenCalled();
    } finally {
      await act(async () => screen.renderer.unmount());
    }
  });

  it('does not publish an acknowledgement that arrives after the account changes', async () => {
    const screen = await mount();
    const reconciliation = deferred<CourseCheckout>();
    mockResume.mockReturnValueOnce(reconciliation.promise);
    try {
      await act(async () => {
        await emitNative();
      });
      expect(mockResume).toHaveBeenCalledTimes(1);
      mockAccount = 'user-8';
      mockEpoch += 1;
      await act(async () => {
        reconciliation.resolve({...pending, status: 'completed'});
      });
      expect(screen.completed).not.toHaveBeenCalled();
      expect(screen.current.pending).toBe(true);
    } finally {
      reconciliation.resolve(pending);
      await act(async () => screen.renderer.unmount());
    }
  });
});
