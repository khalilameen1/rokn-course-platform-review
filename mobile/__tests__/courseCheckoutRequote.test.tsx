import React, {useCallback, useState} from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import type {
  CourseAccessPlan,
  CoursePurchaseQuote,
} from '../src/services/roknApi';
import type {CourseDetailsRouteParams} from '../src/navigation/types';
import type {CourseWalletUpdate} from '../src/screens/CourseDetails/details/useCourseDetailsData';

const mockPurchaseCourse = jest.fn();
const mockQuote = jest.fn();
const mockWallet = jest.fn();
const mockPackages = jest.fn();
const mockOpenCoinCheckout = jest.fn();

jest.mock('../src/constants/distribution', () => ({
  CAN_START_COIN_CHECKOUT: true,
}));
jest.mock('../src/services/coinCheckout', () => ({
  openCoinCheckout: (...args: unknown[]) => mockOpenCoinCheckout(...args),
}));
jest.mock('../src/services/roknApi', () => ({
  getCoinPackages: (...args: unknown[]) => mockPackages(...args),
  getWallet: (...args: unknown[]) => mockWallet(...args),
  purchaseCourse: (...args: unknown[]) => mockPurchaseCourse(...args),
  quoteCoursePurchase: (...args: unknown[]) => mockQuote(...args),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));
jest.mock('../src/screens/CourseDetails/details/useCourseAccessCode', () => ({
  useCourseAccessCode: () => ({busy: false, code: '', grantActivated: false}),
}));
// Entry/session behavior has its own suite. Keep the real purchase, flow,
// coupon and checkout hooks here so displayed prices cannot be mocked away.
jest.mock('../src/screens/CourseDetails/details/usePurchaseEntry', () => ({
  usePurchaseEntry: ({closePurchase}: {closePurchase: () => void}) => ({
    closeDialog: closePurchase,
  }),
}));

import {useCoursePurchase} from '../src/screens/CourseDetails/details/useCoursePurchase';

const plan: CourseAccessPlan = {
  code: 'guided',
  name: 'إرشاد',
  priceCoins: 1000,
  minimumPaidCoins: 0,
  chatEnabled: true,
  chatMessageLimit: 20,
  projectFeedbackLevel: 'report',
  projectReportEnabled: true,
  projectOutputEnabled: true,
  certificateEnabled: true,
};
const coinPackage = {id: '4', coins: 1000, price: 10, label: '١٠٠٠ عملة'};
const wallet = (balance: number) => ({
  balance,
  paidBalance: balance,
  rewardBalance: 0,
  rewardContributionCap: 0,
  spendableBalance: balance,
});
const quote = (
  overrides: Partial<CoursePurchaseQuote> = {},
): CoursePurchaseQuote => ({
  courseRevision: 4,
  accessPlanCode: 'guided',
  originalPrice: 1000,
  discountAmount: 500,
  finalPrice: 500,
  couponCode: 'SAVE',
  discountPercentage: 50,
  ...overrides,
});
const fullPriceQuote = () =>
  quote({
    couponCode: '',
    discountAmount: 0,
    discountPercentage: 0,
    finalPrice: 1000,
  });
const rejection = (code: string, message = '') => ({
  response: {
    status: code === 'course_price_changed' ? 409 : 422,
    data: {code, message},
  },
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

const couponErrors = [
  ['coupon_invalid', 'الكود غير صحيح أو انتهت صلاحيته'],
  ['coupon_already_used', 'استخدمت هذا الكود من قبل'],
  ['coupon_not_applicable', 'لا ينطبق الخصم على هذه الفئة'],
  ['coupon_quota_reached', 'اكتمل عدد مرات استخدام هذا الكود'],
] as const;

const renderPurchase = async ({
  initialBalance = 1000,
  routeParams = {courseId: '52'},
}: {
  initialBalance?: number;
  routeParams?: CourseDetailsRouteParams;
} = {}) => {
  let current!: ReturnType<typeof useCoursePurchase>;
  let currentNotice = '';
  let credit!: (balance: number) => void;
  const reload = jest.fn();
  const setOwned = jest.fn();
  const setPackages = jest.fn();
  const Harness = ({identityKey = 'account-a'}: {identityKey?: string}) => {
    const [currentWallet, setWallet] = useState(wallet(initialBalance));
    const updateWallet = useCallback((value: CourseWalletUpdate) => {
      setWallet(previous => ({...previous, ...value}));
    }, []);
    const [notice, setNotice] = useState('');
    currentNotice = notice;
    credit = next => setWallet(wallet(next));
    current = useCoursePurchase({
      courseId: '52',
      identityKey,
      navigation: {} as never,
      routeParams,
      setNotice,
      data: {
        course: {
          value: {
            id: '52',
            price: 1000,
            accessPlans: [plan],
            publishedRevision: 4,
            owned: false,
            title: 'كورس ركن',
            description: '',
            instructor: '',
            instructorBio: '',
            started: false,
            modules: [],
            reelCount: 0,
            projectCount: 0,
            previewReelCount: 0,
            ratingAverage: null,
            ratingsCount: 0,
            userRating: null,
            studentsCount: 0,
            durationMinutes: null,
          },
          error: '',
          notice: '',
          loading: false,
          session: true,
          reload,
          setOwned,
          setValue: jest.fn(),
          learningValue: null,
        },
        commerce: {
          ...currentWallet,
          loading: false,
          packages: [coinPackage],
          setPackages,
          updateWallet,
        },
      },
    });
    return null;
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
  });
  return {
    read: () => current.dialog,
    notice: () => currentNotice,
    reload,
    setOwned,
    setPackages,
    credit: async (balance: number) => {
      await act(async () => credit(balance));
    },
    switchAccount: async () => {
      await act(async () =>
        renderer.update(<Harness identityKey="account-b" />),
      );
    },
    unmount: async () => {
      await act(async () => renderer.unmount());
    },
  };
};

type PurchaseHarness = Awaited<ReturnType<typeof renderPurchase>>;
const applyCoupon = async (harness: PurchaseHarness) => {
  await act(async () => harness.read().onSelectPlan(plan));
  await act(async () => harness.read().onCouponCodeChange('SAVE'));
  mockQuote.mockResolvedValueOnce(quote());
  await act(async () => harness.read().onApplyCoupon());
  expect(harness.read().couponApplied).toBe(true);
  expect(harness.read().purchasePrice).toBe(500);
  mockQuote.mockClear();
};

describe('course checkout requotes rejected discounts without automatic purchases', () => {
  let harness: PurchaseHarness | undefined;
  beforeEach(() => {
    jest.resetAllMocks();
    mockWallet.mockResolvedValue(wallet(1000));
    mockPackages.mockResolvedValue([coinPackage]);
    mockPurchaseCourse.mockResolvedValue({
      kind: 'success',
      ...wallet(0),
      originalPrice: 1000,
      discountAmount: 0,
    });
  });
  afterEach(async () => {
    await harness?.unmount();
    harness = undefined;
  });

  it.each(couponErrors)(
    'removes %s and requires another confirmation at full price',
    async (code, message) => {
      harness = await renderPurchase();
      await applyCoupon(harness);
      mockPurchaseCourse.mockRejectedValueOnce(rejection(code, message));
      mockQuote.mockResolvedValueOnce(fullPriceQuote());

      await act(async () => harness!.read().onConfirmPurchase());

      expect(mockPurchaseCourse).toHaveBeenCalledTimes(1);
      expect(mockPurchaseCourse).toHaveBeenLastCalledWith(
        '52',
        'guided',
        'SAVE',
        500,
        4,
      );
      expect(mockQuote).toHaveBeenCalledTimes(1);
      expect(mockQuote).toHaveBeenCalledWith('52', 'guided', '', 4);
      expect(harness.read()).toMatchObject({
        couponApplied: false,
        couponCode: '',
        couponDiscountAmount: 0,
        purchasePrice: 1000,
        dialogStep: 'confirm',
        busy: false,
      });
      expect(harness.notice()).toBe(
        `${message}\nراجع الإجمالي بدون خصم قبل الشراء`,
      );
      expect(mockOpenCoinCheckout).not.toHaveBeenCalled();
      expect(harness.setOwned).not.toHaveBeenCalled();

      await act(async () => harness!.read().onConfirmPurchase());
      expect(mockPurchaseCourse).toHaveBeenCalledTimes(2);
      expect(mockPurchaseCourse).toHaveBeenLastCalledWith(
        '52',
        'guided',
        undefined,
        1000,
        4,
      );
      expect(harness.read().dialogStep).toBe('success');
    },
  );

  it('preserves a still-valid coupon with a changed discount and shows the new expected price', async () => {
    harness = await renderPurchase();
    await applyCoupon(harness);
    mockPurchaseCourse.mockRejectedValueOnce(rejection('course_price_changed'));
    mockQuote.mockResolvedValueOnce(
      quote({discountAmount: 200, finalPrice: 800, discountPercentage: 20}),
    );

    await act(async () => harness!.read().onConfirmPurchase());

    expect(mockQuote).toHaveBeenCalledWith('52', 'guided', 'SAVE', 4);
    expect(harness.read()).toMatchObject({
      couponApplied: true,
      couponCode: 'SAVE',
      couponDiscountAmount: 200,
      purchasePrice: 800,
      originalPurchasePrice: 1000,
      dialogStep: 'confirm',
    });
    expect(mockPurchaseCourse).toHaveBeenCalledTimes(1);
    expect(mockOpenCoinCheckout).not.toHaveBeenCalled();
    await act(async () => harness!.read().onConfirmPurchase());
    expect(mockPurchaseCourse).toHaveBeenLastCalledWith(
      '52',
      'guided',
      'SAVE',
      800,
      4,
    );
  });

  it.each(couponErrors)(
    'handles %s discovered while requoting a price rejection',
    async (code, message) => {
      harness = await renderPurchase();
      await applyCoupon(harness);
      mockPurchaseCourse.mockRejectedValueOnce(
        rejection('course_price_changed'),
      );
      mockQuote
        .mockRejectedValueOnce(rejection(code, message))
        .mockResolvedValueOnce(fullPriceQuote());

      await act(async () => harness!.read().onConfirmPurchase());

      expect(mockQuote.mock.calls).toEqual([
        ['52', 'guided', 'SAVE', 4],
        ['52', 'guided', '', 4],
      ]);
      expect(harness.read()).toMatchObject({
        couponApplied: false,
        couponCode: '',
        purchasePrice: 1000,
        dialogStep: 'confirm',
      });
      expect(harness.notice()).toContain(message);
      expect(mockPurchaseCourse).toHaveBeenCalledTimes(1);
      expect(mockOpenCoinCheckout).not.toHaveBeenCalled();
    },
  );

  it('routes changed course base terms to plan review without confirming the stale plan', async () => {
    harness = await renderPurchase();
    await applyCoupon(harness);
    mockPurchaseCourse.mockRejectedValueOnce(rejection('course_price_changed'));
    mockQuote.mockResolvedValueOnce(
      quote({originalPrice: 1200, finalPrice: 700}),
    );
    await act(async () => harness!.read().onConfirmPurchase());
    expect(harness.read()).toMatchObject({
      dialogStep: 'plans',
      couponApplied: false,
      couponCode: 'SAVE',
    });
    expect(harness.reload).toHaveBeenCalledTimes(1);
    expect(mockPurchaseCourse).toHaveBeenCalledTimes(1);
    expect(mockOpenCoinCheckout).not.toHaveBeenCalled();
  });

  it('requotes after pending top-up credit before enabling confirmation', async () => {
    harness = await renderPurchase({initialBalance: 0});
    await applyCoupon(harness);
    mockOpenCoinCheckout.mockResolvedValue({
      success: false,
      pending: true,
      orderRef: 'pending-1',
    });
    await act(async () => harness!.read().onBuyCoins(coinPackage));
    expect(harness.read().dialogStep).toBe('topup');
    expect(mockQuote).not.toHaveBeenCalled();

    const currentQuote = deferred<CoursePurchaseQuote>();
    mockQuote.mockReturnValueOnce(currentQuote.promise);
    await harness.credit(1000);
    expect(harness.read().busy).toBe(true);
    expect(mockQuote).toHaveBeenCalledWith('52', 'guided', 'SAVE', 4);
    await act(async () => harness!.read().onConfirmPurchase());
    expect(mockPurchaseCourse).not.toHaveBeenCalled();

    await act(async () =>
      currentQuote.resolve(
        quote({finalPrice: 800, discountAmount: 200, discountPercentage: 20}),
      ),
    );
    expect(harness.read()).toMatchObject({
      busy: false,
      dialogStep: 'confirm',
      purchasePrice: 800,
      couponApplied: true,
    });
    expect(mockOpenCoinCheckout).toHaveBeenCalledTimes(1);
    expect(mockPurchaseCourse).not.toHaveBeenCalled();
    await act(async () => harness!.read().onConfirmPurchase());
    expect(mockPurchaseCourse).toHaveBeenLastCalledWith(
      '52',
      'guided',
      'SAVE',
      800,
      4,
    );
  });

  it('shows the new shortfall when delayed credit no longer covers the invalid coupon total', async () => {
    harness = await renderPurchase({initialBalance: 0});
    await applyCoupon(harness);
    mockWallet.mockResolvedValue(wallet(500));
    mockQuote
      .mockRejectedValueOnce(rejection('coupon_quota_reached'))
      .mockResolvedValueOnce(fullPriceQuote());
    await harness.credit(500);
    expect(harness.read()).toMatchObject({
      dialogStep: 'topup',
      couponApplied: false,
      purchasePrice: 1000,
      shortfall: 500,
      balance: 500,
    });
    expect(mockQuote).toHaveBeenCalledTimes(2);
    expect(mockOpenCoinCheckout).not.toHaveBeenCalled();
    expect(mockPurchaseCourse).not.toHaveBeenCalled();
  });

  it('retains paid top-up credit and closes stale actions if the fallback quote fails', async () => {
    harness = await renderPurchase({initialBalance: 0});
    await applyCoupon(harness);
    mockOpenCoinCheckout.mockResolvedValue({success: true, coinsAdded: 1000});
    mockQuote
      .mockRejectedValueOnce(rejection('coupon_invalid'))
      .mockRejectedValueOnce(new Error('offline'));
    await act(async () => harness!.read().onBuyCoins(coinPackage));
    expect(harness.read()).toMatchObject({
      balance: 1000,
      dialogStep: null,
      busy: false,
      couponApplied: false,
      couponCode: '',
    });
    expect(harness.notice()).toBe(
      'تعذّر تحديث الإجمالي\nافتح الشراء للمحاولة مرة أخرى',
    );
    expect(harness.reload).toHaveBeenCalledTimes(1);
    expect(mockOpenCoinCheckout).toHaveBeenCalledTimes(1);
    expect(mockPurchaseCourse).not.toHaveBeenCalled();
  });

  it('does not discard a coupon code or retry without it on a transport failure', async () => {
    harness = await renderPurchase();
    await applyCoupon(harness);
    mockPurchaseCourse.mockRejectedValueOnce(rejection('course_price_changed'));
    mockQuote.mockRejectedValueOnce(new Error('offline'));
    await act(async () => harness!.read().onConfirmPurchase());
    expect(harness.read()).toMatchObject({
      couponApplied: false,
      couponCode: 'SAVE',
      dialogStep: null,
    });
    expect(mockQuote).toHaveBeenCalledTimes(1);
    expect(mockPurchaseCourse).toHaveBeenCalledTimes(1);
  });

  it('waits for the credited wallet even if the quote fails first', async () => {
    harness = await renderPurchase({initialBalance: 0});
    await applyCoupon(harness);
    mockOpenCoinCheckout.mockResolvedValue({success: true, coinsAdded: 1000});
    const currentWallet = deferred<ReturnType<typeof wallet>>();
    mockWallet.mockReturnValueOnce(currentWallet.promise);
    mockQuote.mockRejectedValueOnce(new Error('offline'));
    let attempt!: Promise<void>;
    await act(async () => {
      attempt = harness!.read().onBuyCoins(coinPackage);
    });
    expect(harness.read().busy).toBe(true);
    expect(harness.read().dialogStep).toBe('topup');
    expect(harness.notice()).toBe('جارٍ تحديث الإجمالي');
    await act(async () => {
      currentWallet.resolve(wallet(1000));
      await attempt;
    });
    expect(harness.read()).toMatchObject({
      busy: false,
      balance: 1000,
      dialogStep: null,
    });
  });

  it('ignores a prior account wallet and quote response during recovery', async () => {
    harness = await renderPurchase({initialBalance: 0});
    await applyCoupon(harness);
    mockPurchaseCourse.mockRejectedValueOnce(rejection('course_price_changed'));
    const currentWallet = deferred<ReturnType<typeof wallet>>();
    const currentQuote = deferred<CoursePurchaseQuote>();
    mockWallet.mockReturnValueOnce(currentWallet.promise);
    mockQuote.mockReturnValueOnce(currentQuote.promise);
    let attempt!: Promise<void>;
    await act(async () => {
      attempt = harness!.read().onConfirmPurchase();
    });
    await harness.switchAccount();
    await act(async () => {
      currentWallet.resolve(wallet(2000));
      currentQuote.resolve(quote());
      await attempt;
    });
    expect(harness.read()).toMatchObject({
      balance: 0,
      couponApplied: false,
      couponCode: '',
      dialogStep: null,
      busy: false,
    });
    expect(harness.notice()).toBe('');
    expect(harness.reload).not.toHaveBeenCalled();
    expect(harness.setOwned).not.toHaveBeenCalled();
  });

  it('keeps the valid coupon when refreshing a changed top-up package catalogue', async () => {
    harness = await renderPurchase({initialBalance: 0});
    await applyCoupon(harness);
    mockOpenCoinCheckout.mockRejectedValueOnce(
      rejection('package_terms_changed'),
    );
    mockQuote.mockResolvedValueOnce(quote());
    await act(async () => harness!.read().onBuyCoins(coinPackage));
    expect(harness.read()).toMatchObject({
      couponApplied: true,
      couponCode: 'SAVE',
      purchasePrice: 500,
      originalPurchasePrice: 1000,
      dialogStep: 'topup',
    });
    expect(harness.notice()).toBe('تم تحديث باقات الشحن\nاختر الباقة من جديد');
    expect(mockOpenCoinCheckout).toHaveBeenCalledTimes(1);
    expect(mockPurchaseCourse).not.toHaveBeenCalled();
  });

  it('does not restore the rejected coupon again from a consumed return intent', async () => {
    mockQuote.mockResolvedValueOnce(quote());
    harness = await renderPurchase({
      routeParams: {
        courseId: '52',
        openPurchase: true,
        purchasePlanCode: 'guided',
        purchaseCouponCode: 'SAVE',
      },
    });
    expect(harness.read().couponApplied).toBe(true);
    mockQuote.mockClear();
    mockPurchaseCourse.mockRejectedValueOnce(rejection('coupon_invalid'));
    mockQuote.mockResolvedValueOnce(fullPriceQuote());
    await act(async () => harness!.read().onConfirmPurchase());
    expect(mockQuote.mock.calls).toEqual([['52', 'guided', '', 4]]);
    expect(harness.read()).toMatchObject({
      couponApplied: false,
      couponCode: '',
      purchasePrice: 1000,
    });
  });
});
