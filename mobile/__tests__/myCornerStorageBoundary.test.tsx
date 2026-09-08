import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';

const mockGet = jest.fn();
let mockBoundary = {scope: 'learner', epoch: 1};
let mockCourseTitle = 'الكورس المشترى';
let ownerSequence = 0;
jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void) => {
    const ReactModule = require('react');
    ReactModule.useEffect(effect, [effect]);
  },
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  accountScopedStorageKey: async (key: string, boundary: {scope: string}) =>
    `${key}:${boundary.scope}`,
  assertAccountSessionBoundary: (boundary: {scope: string; epoch: number}) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
  DEFAULT_READ_RECOVERY_BUDGET_MS: 20000,
}));
jest.mock('../src/services/api/courses', () =>
  jest.requireActual('../src/services/api/learningCourses'),
);
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/learning'),
  hasSession: async () => true,
}));
import {useMyCornerData} from '../src/screens/myCorner/useMyCornerData';
import {learningResumeTarget} from '../src/screens/myCorner/model';
import {
  getCachedLearningDashboard,
  getLearningDashboard,
} from '../src/services/api/learning';

const rawGet = jest.mocked(AsyncStorage.getItem).getMockImplementation()!;
const rawSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const deferred = () => {
  let resolve!: () => void;
  const promise = new Promise<void>(yes => {
    resolve = yes;
  });
  return {promise, resolve};
};
const flush = async () => {
  for (let index = 0; index < 30; index += 1) await Promise.resolve();
};

describe('MyCorner authoritative courses with unavailable native cache', () => {
  beforeEach(async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    jest.mocked(AsyncStorage.getItem).mockImplementation(rawGet);
    jest.mocked(AsyncStorage.setItem).mockImplementation(rawSet);
    await AsyncStorage.clear();
    mockBoundary = {scope: `learner-${++ownerSequence}`, epoch: ownerSequence};
    mockCourseTitle = 'الكورس المشترى';
    mockGet.mockImplementation(async (route: string) => {
      const data =
        route === 'learning/courses'
          ? {
              items: [
                {
                  course_id: 3,
                  title: mockCourseTitle,
                  progress_percentage: 50,
                  completed_sections: 1,
                  total_sections: 2,
                  learning_started: true,
                  access_type: 'paid',
                  chat_available: false,
                  certificate_available: false,
                  resume: {available: false},
                  next_section: {id: 30, type: 'lesson', title: 'الدرس التالي'},
                },
              ],
              pagination: {has_more: false, next_cursor: null},
            }
          : route === 'user/paths'
          ? []
          : route === 'streaks'
          ? {current_streak: 0, week: {days: []}}
          : {earned_badges: []};
      return {data: {data}};
    });
  });
  afterEach(() => {
    jest.mocked(AsyncStorage.getItem).mockImplementation(rawGet);
    jest.mocked(AsyncStorage.setItem).mockImplementation(rawSet);
    jest.useRealTimers();
  });

  it.each(['read', 'write', 'read-failed-network'] as const)(
    'keeps API ownership authoritative when cache %s never settles',
    async kind => {
      const storage = deferred();
      if (kind.startsWith('read'))
        jest.mocked(AsyncStorage.getItem).mockImplementation(async key => {
          if (key.startsWith('@rokn/learning-dashboard')) await storage.promise;
          return rawGet(key);
        });
      else
        jest
          .mocked(AsyncStorage.setItem)
          .mockImplementation(async (key, value) => {
            if (key.startsWith('@rokn/learning-dashboard'))
              await storage.promise;
            return rawSet(key, value);
          });
      if (kind === 'read-failed-network') {
        const normalGet = mockGet.getMockImplementation()!;
        mockGet.mockImplementation((route: string, options: unknown) => {
          if (route === 'learning/courses')
            return Promise.reject(new Error('Network unavailable'));
          return normalGet(route, options);
        });
      }
      let current!: ReturnType<typeof useMyCornerData>;
      const Harness = () => {
        current = useMyCornerData(mockBoundary.scope);
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<Harness />);
          await flush();
        });
        expect(mockGet).toHaveBeenCalledWith(
          'learning/courses',
          expect.anything(),
        );
        await act(async () => {
          await jest.advanceTimersByTimeAsync(2000);
        });
        if (kind === 'read-failed-network') {
          expect(current.dashboard).toBeNull();
          expect(current.dashboardLoading).toBe(false);
          expect(current.learningOwnershipFresh).toBe(false);
          expect(current.dashboardError).not.toBe('');
          return;
        }
        expect(current.dashboard?.courses).toEqual([
          expect.objectContaining({id: '3', title: 'الكورس المشترى'}),
        ]);
        expect(current.dashboardLoading).toBe(false);
        expect(current.learningOwnershipFresh).toBe(true);
        expect(
          learningResumeTarget(
            current.dashboard!.courses[0],
            current.learningOwnershipFresh,
          ),
        ).toEqual({
          courseId: '3',
          lessonId: '30',
          initialPositionSeconds: undefined,
        });
      } finally {
        await act(async () => {
          renderer.unmount();
          storage.resolve();
          await flush();
        });
      }
    },
  );

  it.each([false, true])(
    'serializes an old native cache write before a newer refresh (same account signed in again: %s)',
    async newSession => {
      const storage = deferred();
      let firstWrite = true;
      jest
        .mocked(AsyncStorage.setItem)
        .mockImplementation(async (key, value) => {
          if (firstWrite) {
            firstWrite = false;
            await storage.promise;
          }
          return rawSet(key, value);
        });
      const old = getLearningDashboard();
      await flush();
      await jest.advanceTimersByTimeAsync(1000);
      await expect(old).resolves.toMatchObject({
        courses: [{title: 'الكورس المشترى'}],
      });
      if (newSession) {
        mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
        await AsyncStorage.clear();
      }
      mockCourseTitle = 'محتوى الكورس بعد التحديث';
      const fresh = getLearningDashboard();
      await flush();
      await jest.advanceTimersByTimeAsync(2000);
      await expect(fresh).resolves.toMatchObject({
        courses: [{title: mockCourseTitle}],
      });
      // Timing out the old write must not release the actual native write slot.
      expect(AsyncStorage.setItem).toHaveBeenCalledTimes(1);
      storage.resolve();
      await flush();
      await expect(getCachedLearningDashboard()).resolves.toMatchObject({
        courses: [{title: mockCourseTitle}],
      });
      expect(AsyncStorage.setItem).toHaveBeenCalledTimes(2);
    },
  );

  it('removes a late native cache write after logout instead of resurrecting the deleted account cache', async () => {
    const storage = deferred();
    const scope = mockBoundary.scope;
    jest.mocked(AsyncStorage.setItem).mockImplementation(async (key, value) => {
      await storage.promise;
      return rawSet(key, value);
    });
    const read = getLearningDashboard();
    await flush();
    await jest.advanceTimersByTimeAsync(1000);
    await read;
    mockBoundary = {scope: 'guest', epoch: mockBoundary.epoch + 1};
    await AsyncStorage.clear();
    storage.resolve();
    await flush();
    expect(await rawGet(`@rokn/learning-dashboard/v3:${scope}`)).toBeNull();
  });

  it('does not cache an older HTTP result after a newer dashboard refresh has succeeded', async () => {
    const response = deferred();
    const normalGet = mockGet.getMockImplementation()!;
    let delayFirstCourse = true;
    mockGet.mockImplementation(async (route: string) => {
      const value = await normalGet(route);
      if (route === 'learning/courses' && delayFirstCourse) {
        delayFirstCourse = false;
        await response.promise;
      }
      return value;
    });
    const old = getLearningDashboard();
    await flush();
    mockCourseTitle = 'الكورس الحالي';
    await expect(getLearningDashboard()).resolves.toMatchObject({
      courses: [{title: mockCourseTitle}],
    });
    response.resolve();
    await expect(old).resolves.toMatchObject({
      courses: [{title: 'الكورس المشترى'}],
    });
    await expect(getCachedLearningDashboard()).resolves.toMatchObject({
      courses: [{title: mockCourseTitle}],
    });
  });
});
