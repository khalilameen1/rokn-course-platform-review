import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

jest.mock('../src/constants/distribution', () => ({
  CAN_START_COIN_CHECKOUT: true,
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));

import {usePurchaseEntry} from '../src/screens/CourseDetails/details/usePurchaseEntry';

type EntryParams = Parameters<typeof usePurchaseEntry>[0];
const plans: EntryParams['accessPlans'] = ['basic', 'mentor'].map(code => ({
  code,
  name: code,
  priceCoins: 700,
  chatEnabled: false,
  chatMessageLimit: 0,
  projectFeedbackLevel: 'pass_only',
  projectReportEnabled: false,
  projectOutputEnabled: false,
  certificateEnabled: true,
}));

function setup(overrides: Partial<EntryParams> = {}) {
  const consume = jest.fn();
  const openForTerms = jest.fn();
  const setNotice = jest.fn();
  const params: EntryParams = {
    accessPlans: plans,
    busy: false,
    couponBusy: false,
    courseId: '52',
    dialogStep: null,
    effectivePurchasePrice: 700,
    effectiveSpendableBalance: 0,
    identityKey: 'learner-a',
    navigation: {setParams: consume} as unknown as EntryParams['navigation'],
    owned: false,
    pageReady: true,
    primaryAction: {kind: 'disabled', label: 'جارٍ تجهيز الشراء'},
    purchaseCouponCode: '',
    purchasePrice: 700,
    purchaseRestoreStatus: 'idle',
    remoteSession: true,
    routeParams: {courseId: '52', openPurchase: true},
    selectedPlanCode: 'mentor',
    closePurchase: jest.fn(),
    openForTerms,
    showPlans: jest.fn(),
    setNotice,
    spendableBalance: 0,
    ...overrides,
  };
  const Harness = ({ready}: {ready: boolean}) => {
    usePurchaseEntry(ready ? {
      ...params,
      primaryAction: {kind: 'choose_plan', label: 'اختر الفئة المناسبة لك'},
      effectiveSpendableBalance: 900,
      spendableBalance: 900,
    } : params);
    return null;
  };
  return {consume, openForTerms, setNotice, Harness};
}

describe('purchase return readiness', () => {
  it.each([undefined, 'mentor'])(
    'keeps the login return intent until commerce is ready (plan=%s)',
    async purchasePlanCode => {
      const {consume, openForTerms, setNotice, Harness} = setup({
        routeParams: {courseId: '52', openPurchase: true, purchasePlanCode},
      });
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Harness ready={false} />);
        });
        expect(consume).not.toHaveBeenCalled();
        expect(openForTerms).not.toHaveBeenCalled();
        expect(setNotice).not.toHaveBeenCalled();

        await act(async () => renderer.update(<Harness ready />));
        expect(openForTerms).toHaveBeenCalledTimes(1);
        expect(openForTerms).toHaveBeenCalledWith({
          forcePlanSelection: !purchasePlanCode,
          purchasePrice: 700,
          spendableBalance: 900,
        });
        expect(consume).toHaveBeenCalledTimes(1);
      } finally {
        if (renderer) await act(async () => renderer.unmount());
      }
    },
  );

  it('reports a completed wallet failure instead of waiting forever', async () => {
    const {consume, openForTerms, setNotice, Harness} = setup({
      primaryAction: {kind: 'wallet_unavailable', label: 'شراء الكورس'},
    });
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness ready={false} />);
    });
    expect(consume).toHaveBeenCalledTimes(1);
    expect(openForTerms).not.toHaveBeenCalled();
    expect(setNotice).toHaveBeenLastCalledWith(
      'تعذّر التحقق من رصيدك\nحاول بعد لحظات',
    );
    await act(async () => renderer.unmount());
  });
});
