import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {useCourseSubscriptionCheckout} from '../src/hooks/useCourseSubscriptionCheckout';
import type {CourseCheckout} from '../src/services/api/courseCheckout';

const mockQuote = jest.fn();
const mockAuthorize = jest.fn();
const mockGet = jest.fn();
const mockLatest = jest.fn();
const mockResume = jest.fn();
const mockCancel = jest.fn();
const mockPayment = jest.fn();
const mockPackages = jest.fn();
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'user-1', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_COIN_CHECKOUT: true,
  IS_STORE_DISTRIBUTION: true,
}));
jest.mock('../src/services/roknApi', () => ({
  getCoinPackages: (...args: unknown[]) => mockPackages(...args),
}));
jest.mock('../src/services/coinCheckout', () => ({
  openCoinCheckout: (...args: unknown[]) => mockPayment(...args),
  subscribeCoinCheckoutCredits: () => () => undefined,
}));
jest.mock('../src/services/api/courseCheckout', () => ({
  quoteCourseCheckout: (...args: unknown[]) => mockQuote(...args),
  authorizeCourseCheckout: (...args: unknown[]) => mockAuthorize(...args),
  getCourseCheckout: (...args: unknown[]) => mockGet(...args),
  getLatestCourseCheckout: (...args: unknown[]) => mockLatest(...args),
  resumeCourseCheckout: (...args: unknown[]) => mockResume(...args),
  cancelCourseCheckout: (...args: unknown[]) => mockCancel(...args),
  selectCheckoutPackage: (
    items: Array<{coins: number; price: number}>,
    deficit: number,
  ) =>
    items
      .filter(item => item.coins >= deficit)
      .sort((a, b) => a.price - b.price)[0],
}));

const coinPackage = {
  id: '1',
  coins: 400,
  price: 20,
  label: '400',
  displayPrice: '٢٠ ج م',
};
const base: CourseCheckout = {
  id: 'checkout-1',
  status: 'quoted',
  courseId: '3',
  planCode: 'guided',
  courseRevision: 9,
  originalPrice: 500,
  discountAmount: 0,
  finalPrice: 500,
  paidCoins: 400,
  rewardCoins: 100,
  paidBalance: 0,
  rewardBalance: 100,
  deficit: 400,
  remainingPaidCoins: 0,
  remainingRewardCoins: 0,
  expiresAt: '2099-01-01T00:00:00Z',
  packages: [coinPackage],
  selectedPackageId: '1',
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('same-sheet course checkout authorization', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockLatest.mockResolvedValue(null);
    mockQuote.mockResolvedValue(base);
    mockPackages.mockResolvedValue([coinPackage]);
    mockAuthorize.mockResolvedValue({...base, status: 'pending_payment'});
    mockResume.mockResolvedValue({...base, status: 'completed'});
    mockGet.mockResolvedValue({...base, status: 'pending_payment'});
    mockCancel.mockResolvedValue({...base, status: 'cancelled'});
    mockPayment.mockResolvedValue({
      success: true,
      pending: false,
      cancelled: false,
      coinsAdded: 400,
    });
  });

  async function mount() {
    let current!: ReturnType<typeof useCourseSubscriptionCheckout>;
    const complete = jest.fn();
    function Probe({
      plan = 'guided',
      visible = true,
    }: {
      plan?: string;
      visible?: boolean;
    }) {
      current = useCourseSubscriptionCheckout({
        courseId: '3',
        planCode: plan,
        visible,
        onCompleted: complete,
      });
      return null;
    }
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Probe />);
    });
    return {current: () => current, complete, renderer, Probe};
  }

  it('authorizes once then pays and fulfills under that same consent without a second purchase tap', async () => {
    const view = await mount();
    expect(mockQuote).toHaveBeenLastCalledWith(
      expect.objectContaining({packageId: '1'}),
    );
    const hold = deferred<{
      success: boolean;
      pending: boolean;
      cancelled: boolean;
      coinsAdded: number;
    }>();
    mockPayment.mockReturnValue(hold.promise);
    let request!: Promise<void>;
    await act(async () => {
      request = view.current().confirm();
      await view.current().confirm();
    });
    expect(mockAuthorize).toHaveBeenCalledTimes(1);
    expect(mockPayment).toHaveBeenCalledTimes(1);
    expect(mockPayment).toHaveBeenCalledWith(
      coinPackage,
      expect.objectContaining({courseCheckoutId: 'checkout-1'}),
    );
    await act(async () => {
      hold.resolve({
        success: true,
        pending: false,
        cancelled: false,
        coinsAdded: 400,
      });
      await request;
    });
    expect(mockResume).toHaveBeenCalledWith('checkout-1');
    expect(view.complete).toHaveBeenCalledTimes(1);
    await act(() => view.renderer.unmount());
  });

  it('uses wallet funds without opening a payment surface', async () => {
    mockQuote.mockResolvedValue({
      ...base,
      deficit: 0,
      paidBalance: 400,
      packages: [],
      selectedPackageId: undefined,
    });
    mockAuthorize.mockResolvedValue({...base, status: 'completed', deficit: 0});
    const view = await mount();
    await act(async () => {
      await view.current().confirm();
    });
    expect(mockPayment).not.toHaveBeenCalled();
    expect(mockPackages).not.toHaveBeenCalled();
    expect(view.complete).toHaveBeenCalledTimes(1);
    await act(() => view.renderer.unmount());
  });

  it('never activates an unpaid cancellation', async () => {
    mockPayment.mockResolvedValue({
      cancelled: true,
      success: false,
      pending: false,
    });
    const view = await mount();
    await act(async () => {
      await view.current().confirm();
    });
    expect(mockCancel).toHaveBeenCalledWith('checkout-1');
    expect(mockResume).not.toHaveBeenCalled();
    expect(view.complete).not.toHaveBeenCalled();
    await act(() => view.renderer.unmount());
  });

  it('restores a pending authorization after restart and only checks that payment', async () => {
    mockLatest.mockResolvedValue({...base, status: 'pending_payment'});
    mockResume.mockResolvedValue({...base, status: 'pending_payment'});
    const view = await mount();
    expect(view.current().pending).toBe(true);
    expect(mockQuote).not.toHaveBeenCalled();
    await act(async () => {
      await view.current().confirm();
    });
    expect(mockResume).toHaveBeenCalledWith('checkout-1');
    expect(mockAuthorize).not.toHaveBeenCalled();
    expect(mockPayment).not.toHaveBeenCalled();
    expect(view.complete).not.toHaveBeenCalled();
    await act(() => view.renderer.unmount());
  });

  it('recovers a lost authorization response without a duplicate payment or debit', async () => {
    mockAuthorize.mockRejectedValue(new Error('timeout'));
    mockGet.mockResolvedValue({...base, status: 'completed'});
    const view = await mount();
    await act(async () => {
      await view.current().confirm();
    });
    expect(mockPayment).not.toHaveBeenCalled();
    expect(view.complete).toHaveBeenCalledTimes(1);
    await act(() => view.renderer.unmount());
  });

  it('lets the learner cancel an authorized request that never opened payment and start a fresh quote', async () => {
    mockLatest.mockResolvedValue({...base, status: 'pending_payment'});
    mockResume.mockResolvedValue({...base, status: 'pending_payment'});
    const view = await mount();
    mockLatest.mockResolvedValue({...base, status: 'cancelled'});
    await act(async () => {
      await view.current().cancelPending();
    });
    expect(mockCancel).toHaveBeenCalledWith('checkout-1');
    expect(view.current().quote?.status).toBe('quoted');
    expect(mockPayment).not.toHaveBeenCalled();
    await act(() => view.renderer.unmount());
  });

  it('expires a stale pending request before preparing a new quote on reopen', async () => {
    mockLatest.mockResolvedValue({...base, status: 'pending_payment'});
    mockResume.mockResolvedValue({...base, status: 'expired'});
    const view = await mount();
    expect(mockResume).toHaveBeenCalledWith('checkout-1');
    expect(view.current().quote?.status).toBe('quoted');
    expect(mockPayment).not.toHaveBeenCalled();
    await act(() => view.renderer.unmount());
  });

  it('will not fabricate a store price when no eligible localized product exists', async () => {
    mockPackages.mockResolvedValue([{...coinPackage, displayPrice: undefined}]);
    const view = await mount();
    expect(view.current().coinPackage).toBeUndefined();
    await act(async () => {
      await view.current().confirm();
    });
    expect(mockAuthorize).not.toHaveBeenCalled();
    expect(mockPayment).not.toHaveBeenCalled();
    await act(() => view.renderer.unmount());
  });

  it('does not open payment or update the removed screen after it closes during authorization', async () => {
    const hold = deferred<CourseCheckout>();
    mockAuthorize.mockReturnValue(hold.promise);
    const view = await mount();
    let request!: Promise<void>;
    await act(async () => {
      request = view.current().confirm();
    });
    await act(() => view.renderer.update(<view.Probe visible={false} />));
    await act(async () => {
      hold.resolve({...base, status: 'pending_payment'});
      await request;
    });
    expect(mockPayment).not.toHaveBeenCalled();
    expect(view.complete).not.toHaveBeenCalled();
    await act(() => view.renderer.unmount());
  });
});
