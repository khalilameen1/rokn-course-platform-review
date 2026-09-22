import React, {useState} from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import type {CourseAccessPlan} from '../src/services/roknApi';
import type {CourseDetailsRouteParams} from '../src/navigation/types';
import {useCoursePurchase} from '../src/screens/CourseDetails/details/useCoursePurchase';
import type {CourseDetailsData} from '../src/screens/CourseDetails/details/useCourseDetailsData';

const mockLegacyQuote = jest.fn();
const mockLegacyPurchase = jest.fn();
jest.mock('../src/constants/distribution', () => ({
  CAN_START_COIN_CHECKOUT: true,
  CAN_REDEEM_COURSE_ACCESS_CODE: true,
}));
jest.mock('../src/services/roknApi', () => ({
  quoteCoursePurchase: (...args: unknown[]) => mockLegacyQuote(...args),
  purchaseCourse: (...args: unknown[]) => mockLegacyPurchase(...args),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));
jest.mock('../src/screens/CourseDetails/details/useCourseAccessCode', () => ({
  useCourseAccessCode: () => ({
    busy: false,
    code: '',
    setCode: jest.fn(),
    redeem: jest.fn(),
  }),
}));

const plans: CourseAccessPlan[] = ['basic', 'guided', 'mentor'].map(code => ({
  code,
  name: code,
  priceCoins: 500,
  minimumPaidCoins: 0,
  chatEnabled: code !== 'basic',
  chatMessageLimit: 20,
  projectsEnabled: code !== 'basic',
  projectFeedbackLevel: 'report',
  projectReportEnabled: code !== 'basic',
  projectOutputEnabled: false,
  certificateEnabled: code !== 'basic',
}));

async function mount(routeParams: CourseDetailsRouteParams = {courseId: '52'}) {
  let current!: ReturnType<typeof useCoursePurchase>;
  const reload = jest.fn();
  const setOwned = jest.fn();
  const setParams = jest.fn();
  function Probe({
    balance = 0,
    identityKey = 'a',
  }: {
    balance?: number;
    identityKey?: string;
  }) {
    const [, setNotice] = useState('');
    current = useCoursePurchase({
      courseId: '52',
      identityKey,
      routeParams,
      setNotice,
      navigation: {setParams} as never,
      data: {
        course: {
          value: {
            id: '52',
            title: 'كورس ركن',
            price: 500,
            accessPlans: plans,
            publishedRevision: 4,
            owned: false,
            started: false,
            modules: [],
            reelCount: 10,
            projectCount: 3,
            previewReelCount: 1,
          },
          loading: false,
          error: '',
          notice: '',
          session: true,
          reload,
          setOwned,
        },
        commerce: {
          balance,
          paidBalance: balance,
          rewardBalance: 0,
          spendableBalance: balance,
          rewardContributionCap: 0,
          loading: false,
          packages: [],
        },
      } as unknown as CourseDetailsData,
    });
    return null;
  }
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Probe />);
  });
  return {read: () => current, renderer, Probe, reload, setOwned, setParams};
}

describe('course purchase quote ownership', () => {
  beforeEach(() => jest.clearAllMocks());

  it('ignores retired discount-code returns and opens the requested subscription', async () => {
    const view = await mount({
      courseId: '52',
      openPurchase: true,
      purchasePlanCode: 'mentor',
      purchaseCouponCode: 'OLD-COUPON',
    });
    expect(view.read().dialog.selectedPlan?.code).toBe('mentor');
    expect(view.read().dialog.dialogStep).not.toBeNull();
    expect(mockLegacyQuote).not.toHaveBeenCalled();
    expect(mockLegacyPurchase).not.toHaveBeenCalled();
    expect(view.setParams).toHaveBeenCalledWith(
      expect.objectContaining({
        openPurchase: false,
        purchaseCouponCode: undefined,
      }),
    );
    await act(() => view.renderer.unmount());
  });

  it('selects each plan without starting a competing quote or purchase', async () => {
    const view = await mount();
    for (const plan of plans) {
      await act(() => view.read().dialog.onSelectPlan(plan));
      expect(view.read().dialog.selectedPlan?.code).toBe(plan.code);
    }
    expect(mockLegacyQuote).not.toHaveBeenCalled();
    expect(mockLegacyPurchase).not.toHaveBeenCalled();
    expect(view.read().dialog).not.toHaveProperty('onApplyCoupon');
    expect(view.read().dialog).not.toHaveProperty('onConfirmPurchase');
    await act(() => view.renderer.unmount());
  });

  it('keeps a wallet refresh from changing or closing the selected sheet', async () => {
    const view = await mount();
    await act(() => view.read().dialog.onSelectPlan(plans[0]));
    const before = view.read().dialog.dialogStep;
    await act(() => view.renderer.update(<view.Probe balance={1000} />));
    expect(view.read().dialog.selectedPlan?.code).toBe('basic');
    expect(view.read().dialog.dialogStep).toBe(before);
    expect(view.setOwned).not.toHaveBeenCalled();
    expect(mockLegacyQuote).not.toHaveBeenCalled();
    await act(() => view.renderer.unmount());
  });

  it('marks completion only after the shared checkout reports success', async () => {
    const view = await mount();
    await act(() => view.read().dialog.onSelectPlan(plans[1]));
    expect(view.setOwned).not.toHaveBeenCalled();
    await act(() => view.read().dialog.onSubscribed());
    expect(view.setOwned).toHaveBeenCalledWith(true);
    expect(view.read().dialog.dialogStep).toBe('success');
    expect(view.reload).toHaveBeenCalledTimes(1);
    await act(() => view.renderer.unmount());
  });

  it('closes the previous account sheet when the session changes', async () => {
    const view = await mount();
    await act(() => view.read().dialog.onSelectPlan(plans[2]));
    await act(() => view.renderer.update(<view.Probe identityKey="b" />));
    expect(view.read().dialog.dialogStep).toBeNull();
    expect(view.setOwned).not.toHaveBeenCalled();
    await act(() => view.renderer.unmount());
  });
});
