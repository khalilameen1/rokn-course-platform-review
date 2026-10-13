import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
import type {
  CourseLearningData,
  ProjectStatus,
} from '../src/components/VideoPlayer/types';

let mockBoundary = {scope: 'user-project-map', epoch: 1};
const mockLoadMap = jest.fn();
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${key}:${boundary.scope}`,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  getLocalLearningState: jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/persistence',
  ).getLocalLearningState,
  loadCourseLearningData: (...args: unknown[]) => mockLoadMap(...args),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/projects', () => ({
  loadProjectResolution: jest.fn(),
  retryPendingProjectSubmissions: async () => [],
  subscribeProjectSubmissionRecovery: () => () => undefined,
  watchProjectResolution: () => () => undefined,
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));

import {useProjectReview} from '../src/screens/reels/useProjectReview';

const map = (status: ProjectStatus, locked = false): CourseLearningData => ({
  id: '3',
  title: 'كورس ركن',
  totalReels: 1,
  attachments: [],
  accessType: 'paid',
  modules: [
    {
      id: '4',
      title: 'المشروع',
      order: 1,
      isLocked: false,
      reels: [],
      projects: [
        {
          id: '7',
          sectionId: '9',
          moduleId: '4',
          title: 'المشروع',
          requirements: 'صمم',
          status,
          isGraduationProject: false,
          canContinue: status === 'passed',
        },
      ],
    },
    {
      id: '8',
      title: 'الخطوة التالية',
      order: 2,
      isLocked: locked,
      projects: [],
      reels: [
        {
          id: '12',
          lessonId: '12',
          sectionId: '13',
          moduleId: '8',
          title: 'الدرس التالي',
          caption: '',
          videoUrl: locked ? '' : 'https://cdn.example.com/next.m3u8',
          availableQualities: ['auto'],
          isPreview: false,
          isLocked: locked,
          isCompleted: false,
          reelNumber: 1,
          sectionOrder: 1,
        },
      ],
    },
  ],
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const tick = async () => {
  for (let i = 0; i < 40; i++) await Promise.resolve();
};
const rawGet = jest.mocked(AsyncStorage.getItem).getMockImplementation()!;

describe('server project map with bounded optional local overlay', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let review!: ReturnType<typeof useProjectReview>;
  let publish: jest.Mock;
  const refs = {
    loadedCourse: {current: map('evaluating') as CourseLearningData | null},
    mounted: {current: true},
    ownerGeneration: {current: 1},
    reviewWatcher: {current: 0},
    watchedProject: {current: null as string | null},
  };
  const Probe = () => {
    review = useProjectReview({
      active: false,
      previewMode: false,
      course: refs.loadedCourse.current,
      refs,
      setCourse: publish,
    });
    return null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Probe />);
    });
  };
  beforeEach(async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    jest.mocked(AsyncStorage.getItem).mockReset().mockImplementation(rawGet);
    await AsyncStorage.clear();
    mockBoundary = {scope: 'user-project-map', epoch: 1};
    refs.loadedCourse.current = map('evaluating');
    refs.mounted.current = true;
    refs.ownerGeneration.current = 1;
    refs.watchedProject.current = null;
    publish = jest.fn();
    mockLoadMap.mockReset().mockResolvedValue({course: map('passed')});
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.mocked(AsyncStorage.getItem).mockReset().mockImplementation(rawGet);
    jest.useRealTimers();
  });

  it('reads cache in parallel and publishes after the local budget without a second read or late replacement', async () => {
    const cache = deferred<string | null>();
    jest.mocked(AsyncStorage.getItem).mockReturnValueOnce(cache.promise);
    await mount();
    let result!: ReturnType<typeof review.refreshProjectState>;
    try {
      await act(async () => {
        result = review.refreshProjectState('7');
        await tick();
      });
      expect(mockLoadMap).toHaveBeenCalledTimes(1);
      expect(AsyncStorage.getItem).toHaveBeenCalledTimes(1);
      expect(publish).not.toHaveBeenCalled();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(250);
      });
      await expect(result).resolves.toEqual({
        status: 'passed',
        canContinue: true,
      });
      expect(publish).toHaveBeenCalledWith(map('passed'));
      await act(async () => {
        cache.resolve(JSON.stringify({completedSections: ['13']}));
        await tick();
      });
      expect(publish).toHaveBeenCalledTimes(1);
    } finally {
      cache.resolve(null);
      await tick();
    }
  });

  it('preserves server locks even when local cache marks the locked lesson completed', async () => {
    await AsyncStorage.setItem(
      '@rokn/course-player/v3:user-project-map',
      JSON.stringify({completedSections: ['13']}),
    );
    mockLoadMap.mockResolvedValueOnce({course: map('passed', true)});
    await mount();
    let result!: Awaited<ReturnType<typeof review.refreshProjectState>>;
    await act(async () => {
      result = await review.refreshProjectState('7');
    });
    expect(result).toEqual({status: 'passed', canContinue: false});
    const fresh = publish.mock.calls[0][0] as CourseLearningData;
    expect(fresh.modules[1].isLocked).toBe(true);
    expect(fresh.modules[1].reels[0].isLocked).toBe(true);
    expect(fresh.modules[1].reels[0].videoUrl).toBe('');
  });

  it('does not start another cache wait after a slower server response arrives', async () => {
    const cache = deferred<string | null>();
    const remote = deferred<{course: CourseLearningData}>();
    jest.mocked(AsyncStorage.getItem).mockReturnValueOnce(cache.promise);
    mockLoadMap.mockReturnValueOnce(remote.promise);
    await mount();
    let result!: ReturnType<typeof review.refreshProjectState>;
    try {
      await act(async () => {
        result = review.refreshProjectState('7');
        await tick();
      });
      expect(mockLoadMap).toHaveBeenCalledTimes(1);
      await act(async () => {
        await jest.advanceTimersByTimeAsync(250);
      });
      expect(publish).not.toHaveBeenCalled();
      await act(async () => {
        remote.resolve({course: map('passed')});
        await result;
      });
      expect(publish).toHaveBeenCalledWith(map('passed'));
      expect(AsyncStorage.getItem).toHaveBeenCalledTimes(1);
    } finally {
      cache.resolve(null);
      remote.resolve({course: map('passed')});
      await tick();
    }
  });

  it('does not publish an old account map after the cache deadline', async () => {
    const cache = deferred<string | null>();
    jest.mocked(AsyncStorage.getItem).mockReturnValueOnce(cache.promise);
    await mount();
    let result!: ReturnType<typeof review.refreshProjectState>;
    try {
      await act(async () => {
        result = review.refreshProjectState('7');
        await tick();
      });
      mockBoundary = {scope: 'user-other', epoch: 2};
      await act(async () => {
        await jest.advanceTimersByTimeAsync(250);
      });
      await expect(result).resolves.toBeNull();
      expect(publish).not.toHaveBeenCalled();
    } finally {
      cache.resolve(null);
      await tick();
    }
  });

  it('keeps a newer server map when an older response completes last', async () => {
    const oldMap = deferred<{course: CourseLearningData}>();
    mockLoadMap
      .mockReturnValueOnce(oldMap.promise)
      .mockResolvedValueOnce({course: map('passed')});
    await mount();
    let older!: ReturnType<typeof review.refreshProjectState>;
    await act(async () => {
      older = review.refreshProjectState('7');
      await tick();
    });
    await act(async () => {
      await review.refreshProjectState('7');
    });
    await act(async () => {
      oldMap.resolve({course: map('evaluating')});
      await older;
    });
    expect(publish).toHaveBeenCalledTimes(1);
    expect(publish).toHaveBeenCalledWith(map('passed'));
  });
});
