import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';

const mockSnapshot = jest.fn();
let mockOwner = {scope: 'learner-7', epoch: 1};
jest.mock('../src/services/roknApi', () => ({
  getCourseDetailsSnapshot: (...args: unknown[]) => mockSnapshot(...args),
  getWallet: jest.fn(),
  getCoinPackages: async () => [],
  hasSession: async () => mockOwner.scope !== 'guest',
  isCourseUnavailableError: () => false,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockOwner}),
  assertAccountSessionBoundary: (boundary: typeof mockOwner) => {
    if (
      boundary.scope !== mockOwner.scope ||
      boundary.epoch !== mockOwner.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: async (key: string, boundary: typeof mockOwner) =>
    `${key}:${boundary.scope}`,
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_NATIVE_CHECKOUT: false,
}));
jest.mock('../src/services/coinCheckout', () => ({
  subscribeCoinCheckoutCredits: () => () => undefined,
}));
jest.mock('../src/services/walletSettlement', () => ({
  subscribeWalletSettlements: () => () => undefined,
}));
jest.mock('../src/services/api/courseDetailsRequest', () => ({}));
jest.mock('../src/components/VideoPlayer/courseLearning/playback', () => ({}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  mapCoursePayload: jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/mapping',
  ).mapCoursePayload,
  applyLocalLearningState: jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/persistence',
  ).applyLocalLearningState,
}));

import {mapCourseDetailsPayload} from '../src/services/api/courseDetailsContract';
import {useCourseDetailsData} from '../src/screens/CourseDetails/details/useCourseDetailsData';

const snapshot = (id = '3', owned = true, title = 'الكورس المختار') => {
  const data = {
    id,
    title,
    access_type: owned ? 'paid' : 'none',
    published_revision: 1,
    is_coming_soon: false,
    ratings_count: 0,
    average_rating: null,
    metadata: {duration_minutes: 2, students_count: 0},
    access_plans: ['basic', 'guided', 'mentor'].map((code, index) => ({
      code,
      name: code,
      price_coins: 0,
      minimum_paid_coins: 0,
      chat_enabled: index > 0,
      chat_message_limit: index * 10,
      project_feedback_level: ['pass_only', 'report', 'enhanced'][index],
      project_report_enabled: index > 0,
      project_thread_reply_enabled: index > 1,
      project_output_enabled: index > 0,
      certificate_enabled: true,
    })),
    modules: [false, true].map((locked, index) => ({
      id: index + 1,
      title: `الوحدة ${index + 1}`,
      order: index + 1,
      is_locked: locked,
      lock_reason: locked ? 'project_required' : null,
      sections: (locked ? [23] : [21, 22]).map(sectionId => ({
        id: sectionId,
        title: `المقطع ${sectionId}`,
        type: 'lesson',
        content_id: sectionId + 10,
        is_preview: !locked,
        is_locked: locked,
        lock_reason: locked ? 'project_required' : null,
        is_completed: sectionId === 21,
        content: locked
          ? {}
          : {
              id: sectionId + 10,
              bunny_video_url: 'https://cdn.example.com/lesson.m3u8',
            },
      })),
    })),
  };
  return {course: mapCourseDetailsPayload(data), responsePayload: {data}};
};

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(finish => {
    resolve = finish;
  });
  return {promise, resolve};
};
const localCompleted = JSON.stringify({completedSections: ['22', '23']});
const getItem = jest.mocked(AsyncStorage.getItem);

describe('course details optional local learning read', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let data: ReturnType<typeof useCourseDetailsData>;
  const Harness = ({courseId = '3', identityKey = mockOwner.scope}) => {
    data = useCourseDetailsData({courseId, identityKey});
    return null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  const passLocalReadBudget = async () => {
    await act(async () => {
      jest.advanceTimersByTime(1000);
    });
  };

  beforeEach(() => {
    jest.useFakeTimers();
    mockOwner = {scope: 'learner-7', epoch: 1};
    mockSnapshot.mockReset().mockResolvedValue(snapshot());
    getItem.mockReset().mockResolvedValue(null);
  });

  afterEach(async () => {
    if (renderer) await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it('shows the owned server course even if native player storage never settles', async () => {
    getItem.mockReturnValueOnce(new Promise(() => undefined));
    await mount();
    expect(getItem).toHaveBeenCalledWith(
      '@rokn/course-player/v3:learner-7',
    );
    expect(mockSnapshot).toHaveBeenCalledTimes(1);
    await passLocalReadBudget();

    expect(data.course.loading).toBe(false);
    expect(data.course.value).toMatchObject({id: '3', owned: true});
    expect(data.course.error).toBe('');
    expect(data.course.learningValue?.modules[0].reels[0].isCompleted).toBe(true);
    expect(data.course.learningValue?.modules[0].reels[1].isCompleted).toBe(false);
    expect(data.course.learningValue?.modules[1]).toMatchObject({
      isLocked: true,
      lockReason: 'project_required',
      reels: [{isLocked: true, videoUrl: ''}],
    });
  });

  it('keeps the selected course after guest login even if the new owner local read stalls', async () => {
    mockOwner = {scope: 'guest', epoch: 1};
    mockSnapshot.mockResolvedValueOnce(snapshot('3', false));
    await mount();
    expect(data.course.value).toMatchObject({id: '3', owned: false});
    expect(getItem).not.toHaveBeenCalled();

    getItem.mockReturnValueOnce(new Promise(() => undefined));
    mockOwner = {scope: 'learner-7', epoch: 2};
    await act(async () => {
      renderer?.update(<Harness />);
    });
    await passLocalReadBudget();

    expect(data.course.loading).toBe(false);
    expect(data.course.value).toMatchObject({id: '3', owned: true});
    expect(data.course.session).toBe(true);
    expect(mockSnapshot.mock.calls.map(call => call[0])).toEqual(['3', '3']);
  });

  it('uses timely local completion hints without unlocking a server project gate', async () => {
    getItem.mockResolvedValueOnce(localCompleted);
    await mount();

    expect(data.course.loading).toBe(false);
    expect(data.course.learningValue?.modules[0].reels[1].isCompleted).toBe(true);
    expect(data.course.learningValue?.modules[1]).toMatchObject({
      isLocked: true,
      lockReason: 'project_required',
      reels: [{isCompleted: true, isLocked: true, videoUrl: ''}],
    });
  });

  it('keeps server details usable after native storage rejects', async () => {
    getItem.mockRejectedValueOnce(new Error('native read unavailable'));
    await mount();

    expect(data.course.loading).toBe(false);
    expect(data.course.value?.owned).toBe(true);
    expect(data.course.learningValue?.modules[0].reels[1].isCompleted).toBe(false);
  });

  it('neither mutates the fallback in place nor revives access after a newer revoked read', async () => {
    const oldRead = deferred<string | null>();
    getItem.mockReturnValueOnce(oldRead.promise);
    await mount();
    await passLocalReadBudget();
    const fallback = data.course.learningValue;
    const fallbackCopy = JSON.stringify(fallback);
    expect(fallback?.modules[0].reels[1].isCompleted).toBe(false);

    mockSnapshot.mockResolvedValueOnce(snapshot('3', false, 'الوصول الحالي'));
    await act(async () => data.course.reload());
    expect(data.course.value).toMatchObject({
      id: '3',
      owned: false,
      title: 'الوصول الحالي',
    });
    expect(data.course.learningValue).toBeNull();
    await act(async () => oldRead.resolve(localCompleted));

    expect(JSON.stringify(fallback)).toBe(fallbackCopy);
    expect(data.course.value?.owned).toBe(false);
    expect(data.course.learningValue).toBeNull();
  });

  it.each(['course', 'account'])(
    'ignores the late local result after changing %s',
    async change => {
      const oldRead = deferred<string | null>();
      getItem.mockReturnValueOnce(oldRead.promise);
      await mount();
      await passLocalReadBudget();
      const fallback = data.course.learningValue;
      const fallbackCopy = JSON.stringify(fallback);

      const nextId = change === 'course' ? '4' : '3';
      if (change === 'account') {
        mockOwner = {scope: 'learner-8', epoch: 2};
      }
      mockSnapshot.mockResolvedValueOnce(snapshot(nextId, true, 'التفاصيل الحالية'));
      await act(async () => {
        renderer?.update(<Harness courseId={nextId} />);
      });
      const current = data.course.learningValue;
      expect(data.course.loading).toBe(false);
      expect(data.course.value).toMatchObject({
        id: nextId,
        title: 'التفاصيل الحالية',
      });

      await act(async () => oldRead.resolve(localCompleted));
      expect(data.course.learningValue).toBe(current);
      expect(data.course.learningValue?.modules[0].reels[1].isCompleted).toBe(false);
      expect(JSON.stringify(fallback)).toBe(fallbackCopy);
    },
  );

  it('does not commit a fallback for a retired session epoch', async () => {
    const oldRead = deferred<string | null>();
    getItem.mockReturnValueOnce(oldRead.promise);
    await mount();
    mockOwner = {...mockOwner, epoch: 2};
    await passLocalReadBudget();

    expect(data.course.value).toBeNull();
    expect(data.course.learningValue).toBeNull();
    await act(async () => oldRead.resolve(localCompleted));
    expect(data.course.value).toBeNull();
  });

  it('does not replace an invalid owned learning contract with local state', async () => {
    const invalid = snapshot();
    invalid.responsePayload.data.modules = [];
    mockSnapshot.mockResolvedValueOnce(invalid);
    await mount();

    expect(data.course.loading).toBe(false);
    expect(data.course.value).toBeNull();
    expect(data.course.learningValue).toBeNull();
    expect(data.course.error).not.toBe('');
    expect(getItem).not.toHaveBeenCalled();
  });
});
