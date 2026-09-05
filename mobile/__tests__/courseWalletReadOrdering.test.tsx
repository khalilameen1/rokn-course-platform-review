import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockGetWallet = jest.fn();
jest.mock('../src/services/roknApi', () => ({
  getCourseDetailsSnapshot: async () => ({
    course: {id: '3', owned: false, price: 900, accessPlans: []},
  }),
  getWallet: (...args: unknown[]) => mockGetWallet(...args),
  getCoinPackages: async () => [],
  hasSession: async () => true,
  isCourseUnavailableError: () => false,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'learner', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_NATIVE_CHECKOUT: false,
}));
jest.mock('../src/services/coinCheckout', () => ({
  subscribeCoinCheckoutCredits: () => () => undefined,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.fn(),
  mapCoursePayload: jest.fn(),
}));

import {useCourseDetailsData} from '../src/screens/CourseDetails/details/useCourseDetailsData';

const wallet = (paidBalance: number) => ({
  balance: paidBalance + 45,
  paidBalance,
  rewardBalance: 45,
  rewardContributionCap: 90,
  spendableBalance: paidBalance + 45,
});

describe('course wallet read ordering', () => {
  it.each([900, 0])(
    'keeps the newer confirmed wallet update (%s paid coins) over an older foreground read',
    async paidCoins => {
      let finishOldRead!: (value: ReturnType<typeof wallet>) => void;
      mockGetWallet.mockReset().mockReturnValueOnce(
        new Promise(resolve => {
          finishOldRead = resolve;
        }),
      );
      let data!: ReturnType<typeof useCourseDetailsData>;
      const Harness = () => {
        data = useCourseDetailsData({courseId: '3', identityKey: 'learner'});
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Harness />);
        });
        expect(mockGetWallet).toHaveBeenCalledTimes(1);
        // The foreground read started before the authoritative top-up/purchase
        // response. A late read must not roll the displayed wallet backwards.
        await act(async () => {
          data.commerce.updateWallet(wallet(paidCoins));
        });
        expect(data.commerce.paidBalance).toBe(paidCoins);
        await act(async () => {
          finishOldRead(wallet(paidCoins === 900 ? 0 : 900));
        });
        expect(data.commerce.paidBalance).toBe(paidCoins);
        expect(data.commerce.balance).toBe(paidCoins + 45);
        expect(data.commerce.loading).toBe(false);

        // A later read remains authoritative, including a lower balance.
        // This is ordering, not a sticky maximum or an optimistic credit.
        mockGetWallet.mockResolvedValueOnce(wallet(120));
        await act(async () => {
          data.course.reload();
        });
        expect(mockGetWallet).toHaveBeenCalledTimes(2);
        expect(data.commerce.paidBalance).toBe(120);
      } finally {
        if (renderer) await act(async () => renderer.unmount());
      }
    },
  );
});
