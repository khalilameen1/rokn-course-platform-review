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
import {
  buildMyCornerModel,
  learningResumeTarget,
} from '../src/screens/myCorner/model';
import type {LearningDashboard} from '../src/services/api/learning';
import {createTestQueryClient, withQueryClient} from './helpers/queryClient';
import {attachQueryPersistence} from '../src/services/query/queryPersistence';
import {accountQueryKeys} from '../src/services/query/queryKeys';
import type {QueryClient} from '@tanstack/react-query';

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
let client: QueryClient;
/** The account's query entry, i.e. what the device snapshot will carry. */
const savedDashboard = () =>
  client.getQueryData<LearningDashboard>(
    accountQueryKeys(mockBoundary.scope).learningDashboard(),
  );

describe('MyCorner authoritative courses with unavailable native cache', () => {
  beforeEach(async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    jest.mocked(AsyncStorage.getItem).mockImplementation(rawGet);
    jest.mocked(AsyncStorage.setItem).mockImplementation(rawSet);
    await AsyncStorage.clear();
    mockBoundary = {scope: `learner-${++ownerSequence}`, epoch: ownerSequence};
    client = createTestQueryClient();
    attachQueryPersistence(client, {throttleTime: 0});
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
    attachQueryPersistence(client).dispose();
    jest.mocked(AsyncStorage.getItem).mockImplementation(rawGet);
    jest.mocked(AsyncStorage.setItem).mockImplementation(rawSet);
    jest.useRealTimers();
  });

  it.each(['user/profile', 'streaks', 'user/paths'])(
    'delivers owned courses before the unrelated %s read settles',
    async routeToDelay => {
      const secondary = deferred();
      const normalGet = mockGet.getMockImplementation()!;
      mockGet.mockImplementation(async (route: string) => {
        const value = await normalGet(route);
        if (route === routeToDelay) await secondary.promise;
        return value;
      });
      let current!: ReturnType<typeof useMyCornerData>;
      const Harness = () => {
        current = useMyCornerData(mockBoundary.scope);
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      try {
        await act(async () => {
          renderer = TestRenderer.create(withQueryClient(<Harness />, client));
          await flush();
        });
        expect(current.dashboard?.courses[0].id).toBe('3');
        expect(current.learningOwnershipFresh).toBe(true);
        expect(current.dashboardLoading).toBe(false);
        await act(async () => {
          secondary.resolve();
          await flush();
        });
        expect(current.dashboard?.courses[0].id).toBe('3');
        expect(current.dashboardError).toBe('');
      } finally {
        await act(async () => {
          renderer.unmount();
          secondary.resolve();
          await flush();
        });
      }
    },
  );

  it('reports a failed authoritative library without waiting for secondary panels', async () => {
    const secondary = deferred();
    const normalGet = mockGet.getMockImplementation()!;
    mockGet.mockImplementation(async (route: string) => {
      if (route === 'learning/courses') throw new Error('library unavailable');
      const value = await normalGet(route);
      await secondary.promise;
      return value;
    });
    let current!: ReturnType<typeof useMyCornerData>;
    const Harness = () => {
      current = useMyCornerData(mockBoundary.scope);
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(withQueryClient(<Harness />, client));
        await flush();
      });
      expect(current.dashboard).toBeNull();
      expect(current.learningOwnershipFresh).toBe(false);
      expect(current.dashboardLoading).toBe(false);
      expect(current.dashboardError).not.toBe('');
    } finally {
      await act(async () => {
        renderer.unmount();
        secondary.resolve();
        await flush();
      });
    }
  });

  it('delivers an authoritative empty library before enrichment finishes', async () => {
    const secondary = deferred();
    const normalGet = mockGet.getMockImplementation()!;
    mockGet.mockImplementation(async (route: string) => {
      const value = await normalGet(route);
      if (route === 'learning/courses') value.data.data.items = [];
      if (route === 'streaks') await secondary.promise;
      return value;
    });
    let current!: ReturnType<typeof useMyCornerData>;
    const Harness = () => {
      current = useMyCornerData(mockBoundary.scope);
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(withQueryClient(<Harness />, client));
        await flush();
      });
      expect(current.dashboard?.courses).toEqual([]);
      expect(current.learningOwnershipFresh).toBe(true);
      expect(current.dashboardLoading).toBe(false);
      expect(current.dashboardError).toBe('');
    } finally {
      await act(async () => {
        renderer.unmount();
        secondary.resolve();
        await flush();
      });
    }
  });

  it('retires old enrichment after refreshing a delivered library and keeps one active primary read', async () => {
    const oldStreak = deferred();
    const normalGet = mockGet.getMockImplementation()!;
    let delayFirstStreak = true;
    mockGet.mockImplementation(async (route: string) => {
      const value = await normalGet(route);
      if (route === 'streaks' && delayFirstStreak) {
        delayFirstStreak = false;
        value.data.data.current_streak = 77;
        await oldStreak.promise;
      }
      return value;
    });
    let current!: ReturnType<typeof useMyCornerData>;
    const Harness = () => {
      current = useMyCornerData(mockBoundary.scope);
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(withQueryClient(<Harness />, client));
        await flush();
      });
      expect(current.learningOwnershipFresh).toBe(true);
      mockCourseTitle = 'الكورس بعد التحديث';
      await act(async () => {
        current.reload();
        current.reload();
        await flush();
      });
      expect(
        mockGet.mock.calls.filter(([route]) => route === 'learning/courses'),
      ).toHaveLength(2);
      expect(current.dashboard?.courses[0].title).toBe(mockCourseTitle);
      expect(current.learningOwnershipFresh).toBe(true);
      await act(async () => {
        oldStreak.resolve();
        await flush();
      });
      expect(current.dashboard?.courses[0].title).toBe(mockCourseTitle);
      expect(current.dashboard?.currentStreakDays).toBe(0);
      expect(current.dashboardLoading).toBe(false);
      expect(savedDashboard()).toMatchObject({
        courses: [{title: mockCourseTitle}],
        currentStreakDays: 0,
      });
    } finally {
      await act(async () => {
        renderer.unmount();
        oldStreak.resolve();
        await flush();
      });
    }
  });

  it.each(['read', 'write', 'read-failed-network'] as const)(
    'keeps API ownership authoritative when cache %s never settles',
    async kind => {
      const storage = deferred();
      if (kind.startsWith('read'))
        jest.mocked(AsyncStorage.getItem).mockImplementation(async key => {
          if (key.startsWith('@rokn/query-cache/account'))
            await storage.promise;
          return rawGet(key);
        });
      else
        jest
          .mocked(AsyncStorage.setItem)
          .mockImplementation(async (key, value) => {
            if (key.startsWith('@rokn/query-cache/account'))
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
          renderer = TestRenderer.create(withQueryClient(<Harness />, client));
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

  // Snapshot write ordering (a stalled older write lands before the newer
  // one) and the logout race (a late write is undone) are proven against the
  // persister in queryPersistence.test.tsx. Two reads of one dashboard can
  // no longer race: one query key has one live fetch, and a replaced fetch's
  // response is discarded (see the enrichment retirement test above).

  it('retains separate project activity and video resume timestamps through the real dashboard cache and still reads older snapshots', async () => {
    const normalGet = mockGet.getMockImplementation()!;
    mockGet.mockImplementation(async (route: string) => {
      const response = await normalGet(route);
      if (route === 'learning/courses') {
        const original = response.data.data.items[0];
        const resume = {
          available: true,
          lesson_id: 31,
          lesson_title: 'المقطع السابق',
          position_seconds: 34,
          duration_seconds: 120,
          watched_at: '2026-10-01T12:00:00Z',
        };
        response.data.data.items = [
          {
            ...original,
            last_activity_at: '2026-10-05T12:00:00Z',
            resume,
            next_section: {id: 30, title: 'المشروع التالي', type: 'project'},
          },
          {
            ...original,
            course_id: 4,
            last_activity_at: '2026-10-04T12:00:00Z',
            resume: {...resume, watched_at: '2026-10-04T12:00:00Z'},
          },
        ];
      }
      return response;
    });
    let current!: ReturnType<typeof useMyCornerData>;
    const Harness = () => {
      current = useMyCornerData(mockBoundary.scope);
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(withQueryClient(<Harness />, client));
      await flush();
      await jest.advanceTimersByTimeAsync(100);
    });
    expect(current.dashboard?.courses).toHaveLength(2);
    await act(async () => {
      renderer.unmount();
      for (let tick = 0; tick < 5; tick += 1) {
        await jest.advanceTimersByTimeAsync(100);
        await flush();
      }
    });
    // A cold start restores what the previous process saved.
    const restoreFromDevice = async () => {
      const coldStart = createTestQueryClient();
      const persistence = attachQueryPersistence(coldStart);
      await persistence.activateAccountScope(mockBoundary.scope);
      persistence.dispose();
      return coldStart.getQueryData<LearningDashboard>(
        accountQueryKeys(mockBoundary.scope).learningDashboard(),
      );
    };
    const cached = await restoreFromDevice();
    expect(cached?.courses[0]).toMatchObject({
      lastActivityAt: '2026-10-05T12:00:00Z',
      lastWatchedAt: '2026-10-01T12:00:00Z',
      resumePositionSeconds: 34,
    });
    const model = buildMyCornerModel({
      dashboard: cached ?? null,
      selectedPathId: null,
      signedIn: true,
    });
    expect(model.orderedCourses.map(course => course.id)).toEqual(['3', '4']);
    expect(learningResumeTarget(model.orderedCourses[0], true)).toEqual({
      courseId: '3',
      projectId: '30',
    });

    const key = `@rokn/query-cache/account/v1:${mockBoundary.scope}`;
    const oldSnapshot = JSON.parse((await rawGet(key))!);
    oldSnapshot.clientState.queries[0].state.data.courses.forEach(
      (course: {lastActivityAt?: string}) => delete course.lastActivityAt,
    );
    await rawSet(key, JSON.stringify(oldSnapshot));
    const legacyModel = buildMyCornerModel({
      dashboard: (await restoreFromDevice()) ?? null,
      selectedPathId: null,
      signedIn: true,
    });
    expect(legacyModel.orderedCourses.map(course => course.id)).toEqual([
      '4',
      '3',
    ]);
  });
});
