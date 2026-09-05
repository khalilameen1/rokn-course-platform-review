import React, {useEffect, useState} from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockPurchaseCourse = jest.fn();
const mockOpenCoinCheckout = jest.fn();

jest.mock('../src/constants/distribution', () => ({
  CAN_START_COIN_CHECKOUT: true,
}));

jest.mock('../src/services/coinCheckout', () => ({
  openCoinCheckout: (...args: unknown[]) => mockOpenCoinCheckout(...args),
}));

jest.mock('../src/services/roknApi', () => ({
  getCoinPackages: jest.fn(),
  getWallet: jest.fn(),
  purchaseCourse: (...args: unknown[]) => mockPurchaseCourse(...args),
  quoteCoursePurchase: jest.fn(),
}));

jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));

import {useCourseCheckout} from '../src/screens/CourseDetails/details/useCourseCheckout';
import {useCoursePurchaseFlow} from '../src/screens/CourseDetails/details/useCoursePurchaseFlow';

const accessChangedError = () => ({
  response: {
    status: 409,
    data: {code: 'course_access_changed'},
  },
});

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((nextResolve, nextReject) => {
    resolve = nextResolve;
    reject = nextReject;
  });
  return {promise, reject, resolve};
};

describe('course checkout current-access reconciliation', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it.each([
    ['payment_configuration_unavailable', 'الدفع متوقف مؤقتًا\nحاول لاحقًا', false],
    ['checkout_temporarily_unavailable', 'الدفع متوقف مؤقتًا\nحاول لاحقًا', false],
    ['provider_status_unavailable', 'تعذّر تأكيد حالة الدفع\nحدّث الصفحة قبل محاولة الشحن مرة أخرى', true],
  ] as const)('distinguishes checkout opening failure from uncertain settlement (%s)', async (code, message, refresh) => {
    mockOpenCoinCheckout.mockRejectedValue({response: {data: {code}}});
    const reload = jest.fn();
    const setNotice = jest.fn();
    const showSuccess = jest.fn();
    let buyCoins!: ReturnType<typeof useCourseCheckout>['buyCoins'];
    const Harness = () => {
      buyCoins = useCourseCheckout({
        closePurchase: jest.fn(),
        couponApplied: false,
        courseId: '52',
        effectivePrice: 600,
        identityKey: 'account-a',
        invalidateCoupon: jest.fn(),
        packages: [],
        publishedRevision: 4,
        purchasePrice: 600,
        reload,
        replaceCouponQuote: jest.fn(),
        selectedPlan: {code: 'guided', priceCoins: 600} as never,
        shortfall: 600,
        showConfirm: jest.fn(),
        showPlans: jest.fn(),
        showSuccess,
        showTopup: jest.fn(),
        setNotice,
        setOwned: jest.fn(),
        setPackages: jest.fn(),
        updateWallet: jest.fn(),
      }).buyCoins;
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => { renderer = TestRenderer.create(<Harness />); });
      await act(async () => {
        await buyCoins({id: '4', coins: 900, price: 10, label: '٩٠٠ عملة'});
      });
      expect(mockOpenCoinCheckout).toHaveBeenCalledTimes(1);
      expect(setNotice).toHaveBeenLastCalledWith(message);
      expect(reload).toHaveBeenCalledTimes(refresh ? 1 : 0);
      expect(mockPurchaseCourse).not.toHaveBeenCalled();
      expect(showSuccess).not.toHaveBeenCalled();
    } finally {
      if (renderer) await act(async () => renderer.unmount());
    }
  });

  it('closes confirm before the reload reveals existing ownership', async () => {
    mockPurchaseCourse.mockRejectedValue(accessChangedError());
    const notices: string[] = [];
    let step: ReturnType<typeof useCoursePurchaseFlow>['step'] = null;
    let confirm!: () => Promise<void>;

    const Harness = () => {
      const flow = useCoursePurchaseFlow();
      const {
        close,
        showConfirm,
        showSuccess,
        step: purchaseStep,
      } = flow;
      const [owned, setOwned] = useState(false);
      useEffect(() => showConfirm(), [showConfirm]);
      useEffect(() => {
        if (owned && purchaseStep !== null && purchaseStep !== 'success') {
          showSuccess();
        }
      }, [owned, purchaseStep, showSuccess]);
      const checkout = useCourseCheckout({
        closePurchase: close,
        couponApplied: false,
        courseId: '52',
        effectivePrice: 600,
        identityKey: 'account-a',
        invalidateCoupon: jest.fn(),
        packages: [],
        publishedRevision: 4,
        purchasePrice: 600,
        reload: () => setOwned(true),
        replaceCouponQuote: jest.fn(),
        selectedPlan: {
          code: 'guided',
          name: 'إرشاد',
          priceCoins: 600,
          minimumPaidCoins: 0,
        } as never,
        shortfall: 0,
        showConfirm,
        showPlans: flow.showPlans,
        showSuccess,
        showTopup: flow.showTopup,
        setNotice: value => {
          if (typeof value === 'string') notices.push(value);
        },
        setOwned,
        setPackages: jest.fn(),
        updateWallet: jest.fn(),
      });
      step = purchaseStep;
      confirm = checkout.confirm;
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    expect(step).toBe('confirm');

    await act(async () => {
      await confirm();
    });

    expect(step).toBeNull();
    expect(notices.at(-1)).toBe(
      'تغيّر وصولك للكورس\nنحدّث تفاصيل فئتك الحالية',
    );
    await act(async () => renderer.unmount());
  });

  it('does not apply an old access response after switching accounts', async () => {
    const purchase = deferred<never>();
    mockPurchaseCourse.mockReturnValue(purchase.promise);
    const reload = jest.fn();
    const closePurchase = jest.fn();
    const setNotice = jest.fn();
    let confirm!: () => Promise<void>;

    const Harness = ({identityKey}: {identityKey: string}) => {
      const checkout = useCourseCheckout({
        closePurchase,
        couponApplied: false,
        courseId: '52',
        effectivePrice: 600,
        identityKey,
        invalidateCoupon: jest.fn(),
        packages: [],
        publishedRevision: 4,
        purchasePrice: 600,
        reload,
        replaceCouponQuote: jest.fn(),
        selectedPlan: {
          code: 'guided',
          name: 'إرشاد',
          priceCoins: 600,
          minimumPaidCoins: 0,
        } as never,
        shortfall: 0,
        showConfirm: jest.fn(),
        showPlans: jest.fn(),
        showSuccess: jest.fn(),
        showTopup: jest.fn(),
        setNotice,
        setOwned: jest.fn(),
        setPackages: jest.fn(),
        updateWallet: jest.fn(),
      });
      confirm = checkout.confirm;
      return null;
    };

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness identityKey="account-a" />);
    });
    let oldConfirm!: Promise<void>;
    await act(async () => {
      oldConfirm = confirm();
      await Promise.resolve();
    });
    await act(async () => {
      renderer.update(<Harness identityKey="account-b" />);
    });
    await act(async () => {
      purchase.reject(accessChangedError());
      await oldConfirm;
    });

    expect(reload).not.toHaveBeenCalled();
    expect(closePurchase).not.toHaveBeenCalled();
    expect(setNotice).not.toHaveBeenCalledWith(
      'تغيّر وصولك للكورس\nنحدّث تفاصيل فئتك الحالية',
    );
    await act(async () => renderer.unmount());
  });
});
