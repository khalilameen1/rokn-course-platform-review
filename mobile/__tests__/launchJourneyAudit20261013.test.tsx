import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {Alert} from 'react-native';

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), put: jest.fn(), post: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    scope: 'user-7',
    epoch: 1,
  })),
  assertAccountSessionBoundary: jest.fn(),
  accountScopedStorageKey: jest.fn(async (key: string) => `${key}:user-7`),
}));
jest.mock('../src/services/publicLinks', () => ({
  privacyPolicyUrl: 'https://rokn.app/privacy-policy',
}));
jest.mock('expo-document-picker', () => ({getDocumentAsync: jest.fn()}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => 'audit-request-7',
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));
jest.mock('../src/services/projectFeedbackDraft', () => ({
  cacheProjectFeedbackFile: jest.fn(),
  clearProjectFeedbackDraft: jest.fn(async () => undefined),
  loadProjectFeedbackDraft: jest.fn(async () => null),
  saveProjectFeedbackDraft: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  loadProjectFeedbackThread: jest.fn(),
  sendProjectFeedbackMessage: jest.fn(),
  uploadProjectFeedbackAttachment: jest.fn(),
  loadProjectResolution: jest.fn(),
  retryProjectReport: jest.fn(),
  retryProjectReview: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  getCoinPackages: jest.fn(),
  hasSession: async () => true,
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(async () => undefined),
}));
jest.mock('../src/services/checkoutRouting', () => ({
  courseCheckoutTransport: {
    kind: 'native',
    channel: 'appstore',
    apiChannel: 'apple',
  },
}));
jest.mock('../src/services/coinCheckout', () => ({
  openCoinCheckout: jest.fn(),
}));
jest.mock('../src/services/api/courseCheckout', () => ({
  getLatestCourseCheckout: jest.fn(),
  resumeCourseCheckout: jest.fn(),
  quoteCourseCheckout: jest.fn(),
  getCourseCheckout: jest.fn(),
  authorizeCourseCheckout: jest.fn(),
  cancelCourseCheckout: jest.fn(),
  selectCheckoutPackage: jest.fn(),
}));

import {publicRequest} from '../src/constants/api';
import {AI_CONSENT_VERSION, hasAiConsent} from '../src/services/aiConsent';
import {useProjectFeedback} from '../src/components/VideoPlayer/projectTransition/useProjectFeedback';
import {
  sendProjectFeedbackMessage,
  retryProjectReport,
  retryProjectReview,
} from '../src/components/VideoPlayer/courseLearningApi';
import type {
  CourseProject,
  ProjectFeedbackThread,
} from '../src/components/VideoPlayer/types';
import {useProjectResolution} from '../src/components/VideoPlayer/projectTransition/useProjectResolution';
import {useCourseSubscriptionCheckout} from '../src/hooks/useCourseSubscriptionCheckout';
import {
  getLatestCourseCheckout,
  resumeCourseCheckout,
  type CourseCheckout,
} from '../src/services/api/courseCheckout';
import {emitNativeStoreCreditOnce} from '../src/services/nativeStoreCredits';
import {emitCoinCheckoutCreditOnce} from '../src/services/coinCheckoutCoordinator';
import {
  markSectionComplete,
  resetSectionCompletionRuntime,
} from '../src/services/sectionCompletionOutbox';
import {
  isProductFeatureEnabled,
  resetProductFeaturesForTests,
} from '../src/services/productFeatures';

const boundary = {scope: 'user-7', epoch: 1};
const response = (accepted: boolean) => ({
  data: {
    success: true,
    data: {
      version: AI_CONSENT_VERSION,
      accepted,
    },
  },
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const tick = async () => {
  for (let i = 0; i < 30; i++) await Promise.resolve();
};
const originalStorageGet = jest
  .mocked(AsyncStorage.getItem)
  .getMockImplementation()!;
const originalStorageSet = jest
  .mocked(AsyncStorage.setItem)
  .getMockImplementation()!;
const thread: ProjectFeedbackThread = {
  id: 'audit-thread-7',
  feedbackLevel: 'enhanced',
  canReply: true,
  status: 'ready',
  remainingMessages: 5,
  messages: [
    {
      id: 'report-7',
      role: 'assistant',
      status: 'completed',
      text: 'تقرير المشروع',
    },
  ],
};

describe('Launch audit — remaining defect reproductions and prepared repair acceptance', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    jest
      .mocked(AsyncStorage.getItem)
      .mockReset()
      .mockImplementation(originalStorageGet);
    jest
      .mocked(AsyncStorage.setItem)
      .mockReset()
      .mockImplementation(originalStorageSet);
    await AsyncStorage.clear();
    (publicRequest.get as jest.Mock).mockReset();
    (publicRequest.put as jest.Mock).mockReset();
    (publicRequest.post as jest.Mock).mockReset();
    resetProductFeaturesForTests();
    (publicRequest.get as jest.Mock).mockResolvedValue(response(true));
    (publicRequest.put as jest.Mock).mockResolvedValue(response(true));
    (publicRequest.post as jest.Mock).mockResolvedValue({});
    (sendProjectFeedbackMessage as jest.Mock).mockResolvedValue(thread);
  });
  afterEach(() => jest.restoreAllMocks());

  it('delivers confirmed AI consent while its optional local receipt write remains pending', async () => {
    const localWrite = deferred<void>();
    jest.spyOn(AsyncStorage, 'setItem').mockReturnValueOnce(localWrite.promise);
    let settled = false;
    const result = hasAiConsent(boundary).then(value => {
      settled = true;
      return value;
    });
    await tick();
    expect(publicRequest.get).toHaveBeenCalledTimes(1);
    expect(AsyncStorage.setItem).toHaveBeenCalledTimes(1);
    expect(settled).toBe(true);
    await expect(result).resolves.toBe(true);
    localWrite.resolve();
    await tick();
  });

  it('delivers acknowledged lesson completion while the optional local player-cache write remains pending', async () => {
    resetSectionCompletionRuntime();
    const localWrite = deferred<void>();
    jest.spyOn(AsyncStorage, 'setItem').mockReturnValueOnce(localWrite.promise);
    let completed = false;
    const result = markSectionComplete('3', '30').then(value => {
      completed = value;
      return value;
    });
    await tick();
    expect(publicRequest.post).toHaveBeenCalledWith(
      'courses/3/sections/30/complete',
    );
    expect(AsyncStorage.setItem).toHaveBeenCalledTimes(1);
    expect(completed).toBe(true);
    await expect(result).resolves.toBe(true);
    localWrite.resolve();
    await tick();
  });

  it.each(['playback', 'checkout', 'project_uploads', 'ai_chat'] as const)(
    'delivers remote %s capability without waiting for optional cached settings',
    async feature => {
      const localRead = deferred<string | null>();
      jest
        .spyOn(AsyncStorage, 'getItem')
        .mockReturnValueOnce(localRead.promise);
      (publicRequest.get as jest.Mock).mockResolvedValue({
        data: {
          data: {
            version: 'audit-flags',
            expires_at: new Date(Date.now() + 60_000).toISOString(),
            flags: {
              playback: true,
              checkout: true,
              project_uploads: true,
              ai_chat: true,
            },
          },
        },
      });
      let settled = false;
      const enabled = isProductFeatureEnabled(feature).then(value => {
        settled = true;
        return value;
      });
      await tick();
      expect(publicRequest.get).toHaveBeenCalledTimes(1);
      expect(settled).toBe(true);
      await expect(enabled).resolves.toBe(true);
      localRead.resolve(
        JSON.stringify({
          version: 'older-disk-flags',
          retrievedAt: Date.now(),
          expiresAt: Date.now() + 60_000,
          flags: {
            playback: false,
            checkout: false,
            project_uploads: false,
            ai_chat: false,
          },
        }),
      );
      await tick();
      await expect(isProductFeatureEnabled(feature)).resolves.toBe(true);
    },
  );

  it.each(['playback', 'checkout', 'project_uploads', 'ai_chat'] as const)(
    'delivers confirmed remote %s permission while its optional cache write remains pending',
    async feature => {
      const localWrite = deferred<void>();
      jest
        .spyOn(AsyncStorage, 'setItem')
        .mockReturnValueOnce(localWrite.promise);
      (publicRequest.get as jest.Mock).mockResolvedValue({
        data: {
          data: {
            version: 'audit-flags',
            expires_at: new Date(Date.now() + 60_000).toISOString(),
            flags: {
              playback: true,
              checkout: true,
              project_uploads: true,
              ai_chat: true,
            },
          },
        },
      });
      let settled = false;
      const enabled = isProductFeatureEnabled(feature).then(value => {
        settled = true;
        return value;
      });
      await tick();
      expect(publicRequest.get).toHaveBeenCalledTimes(1);
      expect(settled).toBe(true);
      await expect(enabled).resolves.toBe(true);
      localWrite.resolve();
      await tick();
    },
  );

  it.each(['close', 'background'] as const)(
    'retires discussion preparation without a late consent dialog or send after %s',
    async interruption => {
      const consentRead = deferred<ReturnType<typeof response>>();
      (publicRequest.get as jest.Mock).mockReturnValueOnce(consentRead.promise);
      const alert = jest
        .spyOn(Alert, 'alert')
        .mockImplementation(() => undefined);
      let current!: ReturnType<typeof useProjectFeedback>;
      const Probe = ({away = false}: {away?: boolean}) => {
        current = useProjectFeedback({
          active: interruption === 'close' ? !away : true,
          appIsActive: interruption === 'background' ? !away : true,
          projectId: '7',
          seedThread: thread,
          feedbackLevel: 'enhanced',
          replyEnabled: true,
          reportStatus: 'ready',
        });
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      let sending!: Promise<void>;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Probe />);
        });
        expect(current.canReply).toBe(true);
        await act(async () => {
          sending = current.send({text: 'كيف أحسن الإضاءة في مشروعي'});
          await tick();
        });
        expect(publicRequest.get).toHaveBeenCalledTimes(1);
        expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
        await act(async () => {
          renderer.update(<Probe away />);
        });
        await act(async () => {
          consentRead.resolve(response(false));
          await tick();
        });
        await act(async () => {
          await sending;
        });
        expect(alert).not.toHaveBeenCalled();
        expect(sendProjectFeedbackMessage).not.toHaveBeenCalled();
        expect(current.sending).toBe(false);
      } finally {
        if (renderer) await act(async () => renderer.unmount());
      }
    },
  );

  it('reconciles a pending upgrade on its actual late Apple credit event without another payment', async () => {
    const pending: CourseCheckout = {
      id: 'audit-native-upgrade',
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
    (getLatestCourseCheckout as jest.Mock).mockResolvedValue(pending);
    (resumeCourseCheckout as jest.Mock).mockResolvedValue(pending);
    const completed = jest.fn();
    let current!: ReturnType<typeof useCourseSubscriptionCheckout>;
    const Probe = () => {
      current = useCourseSubscriptionCheckout({
        courseId: '3',
        planCode: 'mentor',
        mode: 'upgrade',
        visible: true,
        onCompleted: completed,
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Probe />);
      });
      expect(current.pending).toBe(true);
      (resumeCourseCheckout as jest.Mock).mockClear();
      (resumeCourseCheckout as jest.Mock).mockResolvedValue({
        ...pending,
        status: 'completed',
      });
      const credit = {
        success: true,
        pending: false,
        cancelled: false,
        coinsAdded: 900,
        orderRef: 'audit-native-order',
      };
      await act(async () => {
        await emitNativeStoreCreditOnce(
          'audit-native-receipt',
          credit,
          '@rokn/native-store-reconciliation/v1:user-7',
        );
        await tick();
      });
      expect(resumeCourseCheckout).toHaveBeenCalledTimes(1);
      expect(completed).toHaveBeenCalledTimes(1);
      expect(current.pending).toBe(false);
      await act(async () => {
        emitCoinCheckoutCreditOnce({scope: 'user-7', epoch: 1}, credit);
        await tick();
      });
      expect(resumeCourseCheckout).toHaveBeenCalledTimes(1);
      expect(completed).toHaveBeenCalledTimes(1);
    } finally {
      if (renderer) await act(async () => renderer.unmount());
    }
  });

  it.each([
    ['review', 'close'],
    ['review', 'background'],
    ['report', 'close'],
    ['report', 'background'],
  ] as const)(
    'retires %s preparation after %s while consent was loading',
    async (kind, interruption) => {
      const consentRead = deferred<ReturnType<typeof response>>();
      (publicRequest.get as jest.Mock).mockReturnValueOnce(consentRead.promise);
      const alert = jest
        .spyOn(Alert, 'alert')
        .mockImplementation(() => undefined);
      const project: CourseProject = {
        id: '7',
        sectionId: '9',
        moduleId: '3',
        title: 'مشروع ركن',
        requirements: 'صمم',
        status: kind === 'review' ? 'review_unavailable' : 'passed',
        isGraduationProject: false,
        canSubmit: false,
        canContinue: false,
        canRetryReview: kind === 'review',
        canRetryReport: kind === 'report',
        reviewRetryEndpoint: '/api/v1/project-submissions/audit/review/retry',
        reportRetryEndpoint: '/api/v1/project-submissions/audit/report/retry',
        reportEnabled: kind === 'report',
        reportStatus: kind === 'report' ? 'failed' : 'not_included',
      };
      const transport =
        kind === 'review' ? retryProjectReview : retryProjectReport;
      jest
        .mocked(transport)
        .mockResolvedValue({
          ...project,
          status: 'evaluating',
          reportStatus: 'queued',
        } as never);
      let current!: ReturnType<typeof useProjectResolution>;
      const Probe = ({away = false}: {away?: boolean}) => {
        current = useProjectResolution({
          active: interruption === 'close' ? !away : true,
          appIsActive: interruption === 'background' ? !away : true,
          project,
        });
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      let retrying!: Promise<void>;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Probe />);
        });
        await act(async () => {
          retrying =
            kind === 'review' ? current.retryReview() : current.retryReport();
          await tick();
        });
        expect(transport).not.toHaveBeenCalled();
        await act(async () => {
          renderer.update(<Probe away />);
        });
        await act(async () => {
          consentRead.resolve(response(false));
          await tick();
        });
        await act(async () => {
          await retrying;
        });
        expect(alert).not.toHaveBeenCalled();
        expect(transport).not.toHaveBeenCalled();
        expect(
          kind === 'review' ? current.reviewRetrying : current.reportRetrying,
        ).toBe(false);
      } finally {
        if (renderer) await act(async () => renderer.unmount());
      }
    },
  );
});
