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
const mockOffer = jest.fn();
jest.mock('../src/services/api/engagement', () => ({
  getCoursePurchaseExitOffer: (...args: unknown[]) => mockOffer(...args),
}));
import {usePurchaseEntry} from '../src/screens/CourseDetails/details/usePurchaseEntry';
type Params = Parameters<typeof usePurchaseEntry>[0];
let sequence = 0;
function harness() {
  let entry!: ReturnType<typeof usePurchaseEntry>;
  let renderer!: TestRenderer.ReactTestRenderer;
  const params: Params = {
    accessPlans: [],
    courseId: String(++sequence),
    dialogStep: 'plans',
    identityKey: 'learner-a',
    navigation: {setParams: jest.fn()} as unknown as Params['navigation'],
    owned: false,
    pageReady: true,
    presentationActive: true,
    primaryAction: {kind: 'choose_plan', label: 'اختر الاشتراك'},
    purchasePrice: 400,
    remoteSession: true,
    routeParams: {courseId: '1'},
    selectedPlanCode: 'basic',
    closePurchase: jest.fn(),
    openForTerms: jest.fn(),
    showPlans: jest.fn(),
    setNotice: jest.fn(),
    spendableBalance: 0,
  };
  function Harness({input}: {input: Params}) {
    entry = usePurchaseEntry(input);
    return null;
  }
  return {
    get entry() {
      return entry;
    },
    params,
    async mount() {
      await act(async () => {
        renderer = TestRenderer.create(<Harness input={{...params}} />);
      });
    },
    async update(values: Partial<Params>) {
      Object.assign(params, values);
      await act(async () => {
        renderer.update(<Harness input={{...params}} />);
      });
    },
    async dismiss() {
      await act(async () => {
        entry.closeDialog();
      });
      Object.assign(params, {dialogStep: null});
      await act(async () => {
        renderer.update(<Harness input={{...params}} />);
      });
      await act(async () => {
        jest.advanceTimersByTime(180);
      });
    },
    async unmount() {
      await act(async () => {
        renderer.unmount();
      });
    },
  };
}
beforeEach(() => {
  jest.useFakeTimers();
  mockOffer.mockReset();
  mockOffer.mockResolvedValue({taskId: '8', additionalDiscountCoins: 20});
});
afterEach(() => {
  jest.useRealTimers();
});

it('shows only after a no-attempt close and a fresh useful server candidate', async () => {
  const h = harness();
  await h.mount();
  expect(mockOffer).not.toHaveBeenCalled();
  await h.dismiss();
  expect(mockOffer).toHaveBeenCalledWith(h.params.courseId, 'basic');
  expect(h.entry.retention.visible).toBe(true);
  await act(async () => {
    h.entry.retention.close();
  });
  await h.update({dialogStep: 'plans'});
  await h.dismiss();
  expect(mockOffer).toHaveBeenCalledTimes(1);
  await h.unmount();
});
it('never offers after an explicit buying attempt even if payment then fails', async () => {
  const h = harness();
  await h.mount();
  await act(async () => {
    h.entry.paymentAttempted();
  });
  await h.dismiss();
  expect(mockOffer).not.toHaveBeenCalled();
  expect(h.entry.retention.visible).toBe(false);
  await h.unmount();
});
it.each([null, 'offline'])(
  'silently omits an unavailable opportunity (%s)',
  async result => {
    if (result === 'offline') mockOffer.mockRejectedValue(new Error('offline'));
    else mockOffer.mockResolvedValue(null);
    const h = harness();
    await h.mount();
    await h.dismiss();
    expect(h.entry.retention.visible).toBe(false);
    await h.unmount();
  },
);
it.each([
  {presentationActive: false},
  {owned: true},
  {remoteSession: false},
  {remoteSession: null},
  {pageReady: false},
  {dialogStep: 'success'},
] as Partial<Params>[])(
  'does not query outside eligible entry state %o',
  async change => {
    const h = harness();
    await h.mount();
    await h.update(change);
    await h.dismiss();
    expect(mockOffer).not.toHaveBeenCalled();
    await h.unmount();
  },
);
it.each([
  {presentationActive: false},
  {identityKey: 'learner-b'},
  {courseId: 'other-course'},
  {selectedPlanCode: 'guided'},
  {owned: true},
  {dialogStep: 'plans'},
] as Partial<Params>[])('rejects a stale candidate after %o', async change => {
  let resolve!: (value: unknown) => void;
  mockOffer.mockImplementation(
    () =>
      new Promise(done => {
        resolve = done;
      }),
  );
  const h = harness();
  await h.mount();
  await h.dismiss();
  await h.update(change);
  await act(async () => {
    resolve({taskId: '8', additionalDiscountCoins: 20});
  });
  expect(h.entry.retention.visible).toBe(false);
  await h.unmount();
});
it('payment intent invalidates a candidate already in flight', async () => {
  let resolve!: (value: unknown) => void;
  mockOffer.mockImplementation(
    () =>
      new Promise(done => {
        resolve = done;
      }),
  );
  const h = harness();
  await h.mount();
  await h.dismiss();
  await act(async () => {
    h.entry.paymentAttempted();
    resolve({taskId: '8', additionalDiscountCoins: 20});
  });
  expect(h.entry.retention.visible).toBe(false);
  await h.unmount();
});
