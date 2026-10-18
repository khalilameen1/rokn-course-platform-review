import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {
  createTestQueryClient,
  withQueryClient,
} from './helpers/queryClient';

let queryTestClient = createTestQueryClient();
beforeEach(() => {
  queryTestClient = createTestQueryClient();
});
import type {CourseDetails} from '../src/services/roknApi';

const mockWallet = jest.fn();
const mockPackages = jest.fn();
const mockDetails = jest.fn();
jest.mock('../src/services/roknApi', () => ({
  getCourseDetailsSnapshot: (...args: unknown[]) => mockDetails(...args),
  getWallet: (...args: unknown[]) => mockWallet(...args),
  getCoinPackages: (...args: unknown[]) => mockPackages(...args),
  hasSession: async () => true,
  isCourseUnavailableError: () => false,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'learner', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_NATIVE_CHECKOUT: false,
  CAN_START_COIN_CHECKOUT: true,
}));
jest.mock('../src/services/coinCheckout', () => ({
}));
jest.mock('../src/services/walletSettlement', () => ({
  subscribeWalletSettlements: () => () => undefined,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  mapCoursePayload: jest.fn(),
  applyLocalLearningState: jest.fn(),
}));

import {useCourseDetailsData} from '../src/screens/CourseDetails/details/useCourseDetailsData';
import {selectCourseDetailsPresentation} from '../src/screens/CourseDetails/details/selectors';

const course: CourseDetails = {
  id: '3',
  publishedRevision: 1,
  title: 'كورس ركن',
  description: '',
  price: 400,
  instructor: '',
  instructorBio: '',
  owned: false,
  started: false,
  modules: [],
  reelCount: 5,
  projectCount: 0,
  previewReelCount: 1,
  ratingAverage: null,
  ratingsCount: 0,
  userRating: null,
  studentsCount: 0,
  durationMinutes: 5,
  accessPlans: ['basic', 'guided', 'mentor'].map((code, index) => ({
    code,
    name: code,
    priceCoins: 400 + index * 200,
    chatEnabled: index > 0,
    chatMessageLimit: index * 20,
    projectFeedbackLevel: 'pass_only',
    projectReportEnabled: false,
    projectOutputEnabled: false,
    certificateEnabled: index > 0,
  })),
};

describe('course plan entry has no unrelated provider dependency', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockDetails.mockResolvedValue({course});
  });

  it.each(['pending', 'failed'] as const)(
    'opens the paid plan choices while the optional wallet read is %s',
    async state => {
      let release!: () => void;
      mockWallet.mockReset();
      if (state === 'pending') {
        mockWallet.mockReturnValueOnce(
          new Promise(resolve => {
            release = () =>
              resolve({
                balance: 60,
                paidBalance: 0,
                rewardBalance: 60,
                rewardContributionCap: 80,
                spendableBalance: 60,
              });
          }),
        );
      } else {
        mockWallet.mockRejectedValueOnce(new Error('offline'));
      }
      let current!: ReturnType<typeof useCourseDetailsData>;
      const Probe = () => {
        current = useCourseDetailsData({courseId: '3', identityKey: 'learner'});
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(withQueryClient(<Probe />, queryTestClient));
        });
        expect(current.course.loading).toBe(false);
        expect(current.course.session).toBe(true);
        expect(current.commerce.balance).toBeNull();
        expect(mockPackages).not.toHaveBeenCalled();
        const presentation = selectCourseDetailsPresentation({
          remoteCourse: current.course.value,
          remoteSession: current.course.session,
          remoteLoading: current.course.loading,
          remoteError: current.course.error,
          remoteBalance: current.commerce.balance,
          remotePaidBalance: current.commerce.paidBalance,
          remoteRewardBalance: current.commerce.rewardBalance,
          remoteSpendableBalance: current.commerce.spendableBalance,
          remoteRewardContributionCap: current.commerce.rewardContributionCap,
          remoteCommerceLoading: current.commerce.loading,
          remotePackages: current.commerce.packages,
          selectedPlanCode: 'guided',
        });
        expect(presentation.primaryAction).toEqual({
          kind: 'choose_plan',
          label: 'اشترِ الآن',
        });
        expect(presentation.primaryActionDisabled).toBe(false);
        if (release) await act(async () => release());
      } finally {
        if (renderer) await act(async () => renderer.unmount());
      }
    },
  );
});
