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
const mockTrack = jest.fn();
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: (...args: unknown[]) => mockTrack(...args),
}));
jest.mock('../src/services/checkoutRouting', () => ({
  courseCheckoutTransport: {
    kind: 'native',
    channel: 'play',
    apiChannel: 'google',
  },
}));
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
  channel: 'google',
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
    require('../src/services/checkoutRouting').courseCheckoutTransport = {
      kind: 'native',
      channel: 'play',
      apiChannel: 'google',
    };
    jest.clearAllMocks();
    [
      mockLatest,
      mockQuote,
      mockPackages,
      mockAuthorize,
      mockResume,
      mockGet,
      mockCancel,
      mockPayment,
    ].forEach(mock => mock.mockReset());
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

  async function mount(
    onPaymentRecovery?: () => void,
    mode: 'purchase' | 'upgrade' = 'purchase',
  ) {
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
        mode,
        planCode: plan,
        visible,
        onCompleted: complete,
        onPaymentRecovery,
      });
      return null;
    }
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Probe />);
    });
    return {current: () => current, complete, renderer, Probe};
  }

  it('tracks only sheet visibility and leaves committed purchase events to the server', async () => {
    const view = await mount();
    expect(mockTrack.mock.calls.map(([event]) => event.event_name)).toEqual([
      'paywall_viewed',
    ]);
    await act(async () => {
      await view.current().confirm();
    });
    expect(mockTrack.mock.calls.map(([event]) => event.event_name)).toEqual([
      'paywall_viewed',
    ]);
    expect(
      mockTrack.mock.calls.every(([event]) => event.course_id === '3'),
    ).toBe(true);
    await act(async () => view.renderer.unmount());
  });

  it('does not report a top-up pending confirmation as a completed course purchase', async () => {
    mockResume.mockResolvedValue({...base, status: 'pending_payment'});
    const view = await mount();
    await act(async () => {
      await view.current().confirm();
    });
    expect(mockTrack.mock.calls.map(([event]) => event.event_name)).toEqual([
      'paywall_viewed',
    ]);
    mockResume.mockResolvedValue({...base, status: 'completed'});
    await act(async () => {
      await view.current().confirm();
    });
    expect(
      mockTrack.mock.calls.filter(
        ([event]) => event.event_name === 'purchase_completed',
      ),
    ).toHaveLength(0);
    await act(async () => view.renderer.unmount());
  });

  it('keeps upgrades out of the first course purchase funnel', async () => {
    const view = await mount(undefined, 'upgrade');
    await act(async () => {
      await view.current().confirm();
    });
    expect(view.complete).toHaveBeenCalledTimes(1);
    expect(mockTrack).not.toHaveBeenCalled();
    await act(async () => view.renderer.unmount());
  });

  it.each(['cancelled', 'reconfirm_required'] as const)(
    'reports restored payment before resume yields %s and no pending UI is published',
    async status => {
      const recovery = jest.fn();
      mockLatest.mockResolvedValue({...base, status: 'pending_payment'});
      mockResume.mockImplementation(async () => {
        expect(recovery).toHaveBeenCalledTimes(1);
        return {...base, status};
      });
      const view = await mount(recovery);
      expect(recovery).toHaveBeenCalledTimes(1);
      expect(view.current().pending).toBe(false);
      expect(view.current().quote?.status).toBe('quoted');
      expect(mockAuthorize).not.toHaveBeenCalled();
      expect(mockPayment).not.toHaveBeenCalled();
      await act(async () => view.renderer.unmount());
    },
  );

  it('uses the direct quote on Android and resumes the same intent after returning without payment', async () => {
    require('../src/services/checkoutRouting').courseCheckoutTransport = {
      kind: 'external',
      channel: 'direct',
      apiChannel: 'direct',
      surface: 'browser',
    };
    const directPackage = {...coinPackage, price: 18, displayPrice: undefined};
    const direct: CourseCheckout = {
      ...base,
      channel: 'direct',
      canResumePayment: true,
      packages: [directPackage],
      fundingMode: 'exact_shortfall',
      selectedPackage: directPackage,
    };
    mockQuote.mockResolvedValue(direct);
    mockAuthorize.mockResolvedValue({...direct, status: 'pending_payment'});
    mockResume.mockResolvedValue({...direct, status: 'pending_payment'});
    mockPayment.mockResolvedValue({
      success: false,
      pending: true,
      cancelled: false,
      coinsAdded: 0,
    });
    const view = await mount();
    expect(mockPackages).not.toHaveBeenCalled();
    expect(view.current().coinPackage?.price).toBe(18);
    await act(async () => {
      await view.current().confirm();
    });
    expect(view.complete).not.toHaveBeenCalled();
    expect(mockCancel).not.toHaveBeenCalled();
    expect(view.current().canResumePayment).toBe(true);
    mockResume.mockResolvedValue({...direct, status: 'completed'});
    mockPayment.mockResolvedValue({
      success: true,
      pending: false,
      cancelled: false,
      coinsAdded: 400,
    });
    await act(async () => {
      await view.current().confirm();
    });
    expect(mockPayment).toHaveBeenCalledTimes(2);
    for (const call of mockPayment.mock.calls) {
      expect(call[1].courseCheckoutId).toBe(base.id);
    }
    expect(mockQuote).toHaveBeenCalledTimes(2); // initial quote + package binding, no new intent on return
    expect(view.complete).toHaveBeenCalledTimes(1);
    await act(async () => {
      view.renderer.unmount();
    });
  });

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

  it('resumes an issued direct payment after quote expiry without creating a new quote', async () => {
    require('../src/services/checkoutRouting').courseCheckoutTransport = {
      kind: 'external',
      channel: 'direct',
      apiChannel: 'direct',
      surface: 'browser',
    };
    const pending: CourseCheckout = {
      ...base,
      channel: 'direct',
      status: 'pending_payment',
      expiresAt: '2000-01-01T00:00:00Z',
      canResumePayment: true,
    };
    mockLatest.mockResolvedValue(pending);
    mockResume.mockResolvedValue(pending);
    mockAuthorize.mockResolvedValue(pending);
    const view = await mount();
    expect(view.current().canResumePayment).toBe(true);
    await act(async () => {
      await view.current().confirm();
    });
    expect(mockQuote).not.toHaveBeenCalled();
    expect(mockPayment).toHaveBeenCalledTimes(1);
    expect(mockPayment.mock.calls[0][1]).toMatchObject({
      courseCheckoutId: base.id,
      transport: {kind: 'external', surface: 'browser'},
    });
    await act(() => view.renderer.unmount());
  });

  it('checks rather than reopens a payment when the server says its payment window is closed', async () => {
    require('../src/services/checkoutRouting').courseCheckoutTransport = {
      kind: 'external',
      channel: 'direct',
      apiChannel: 'direct',
      surface: 'browser',
    };
    const pending: CourseCheckout = {
      ...base,
      channel: 'direct',
      status: 'pending_payment',
      expiresAt: '2000-01-01T00:00:00Z',
      canResumePayment: false,
    };
    mockLatest.mockResolvedValue(pending);
    mockResume.mockResolvedValue(pending);
    const view = await mount();
    expect(view.current().canResumePayment).toBe(false);
    await act(async () => {
      await view.current().confirm();
    });
    expect(mockResume).toHaveBeenCalledWith(base.id);
    expect(mockPayment).not.toHaveBeenCalled();
    expect(mockAuthorize).not.toHaveBeenCalled();
    expect(mockQuote).not.toHaveBeenCalled();
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

  const conflict = {
    status: 409,
    data: {
      code: 'checkout_already_pending',
      data: {active_checkout: {id: 'older-checkout', course_id: 8}},
    },
  };
  it.each(['resume', 'cancel'] as const)(
    'resolves a different course checkout via %s without buying the current course',
    async action => {
      mockAuthorize.mockRejectedValueOnce(conflict);
      const view = await mount();
      await act(async () => {
        await view.current().confirm();
      });
      expect(view.current().blockedByPreviousCheckout).toBe(true);
      expect(mockGet).not.toHaveBeenCalled();
      const previous = {
        ...base,
        id: 'older-checkout',
        courseId: '8',
        status: 'completed',
      };
      mockResume.mockResolvedValueOnce(previous);
      mockCancel.mockResolvedValueOnce(previous);
      await act(async () => {
        if (action === 'cancel') await view.current().cancelPending();
        else await view.current().confirm();
      });
      expect(
        action === 'cancel' ? mockCancel : mockResume,
      ).toHaveBeenCalledWith('older-checkout');
      expect(view.current().blockedByPreviousCheckout).toBe(false);
      expect(view.current().quote?.courseId).toBe('3');
      expect(view.current().quote?.status).toBe('quoted');
      expect(view.complete).not.toHaveBeenCalled();
      expect(mockPayment).not.toHaveBeenCalled();
      expect(mockAuthorize).toHaveBeenCalledTimes(1);
      await act(() => view.renderer.unmount());
    },
  );

  it('keeps recovery actionable across pending results and network failures without charging again', async () => {
    mockAuthorize.mockRejectedValueOnce(conflict);
    const view = await mount();
    await act(async () => {
      await view.current().confirm();
    });
    mockResume.mockResolvedValueOnce({
      ...base,
      id: 'older-checkout',
      courseId: '8',
      status: 'pending_payment',
    });
    await act(async () => {
      await view.current().confirm();
    });
    expect(view.current().blockedByPreviousCheckout).toBe(true);
    mockCancel.mockRejectedValueOnce(new Error('offline'));
    await act(async () => {
      await view.current().cancelPending();
    });
    expect(view.current().blockedByPreviousCheckout).toBe(true);
    expect(view.current().notice).toContain('حاول مرة أخرى');
    mockCancel.mockResolvedValueOnce({
      ...base,
      id: 'older-checkout',
      courseId: '8',
      status: 'cancelled',
    });
    await act(async () => {
      await view.current().cancelPending();
    });
    expect(view.current().blockedByPreviousCheckout).toBe(false);
    expect(view.complete).not.toHaveBeenCalled();
    expect(mockPayment).not.toHaveBeenCalled();
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
