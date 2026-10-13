import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
const mockGet = jest.fn();
const mockLearningCourses = jest.fn();
const mockCertificates = jest.fn();
const mockLearningMap = jest.fn();
jest.mock('@react-navigation/native', () => ({
  useIsFocused: () => true,
  useFocusEffect: (effect: () => void | (() => void)) => {
    const ReactModule = require('react') as typeof React;
    ReactModule.useEffect(effect, [effect]);
  },
}));
jest.mock('react-redux', () => ({
  useSelector: () => ({api_token: 'test', user: {id: 7, name: 'طالب ركن'}}),
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'user-7', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
  accountScopedStorageKey: async (key: string) => `${key}:user-7`,
  extractUserProfile: () => ({id: 7, name: 'طالب ركن'}),
  sessionIdentityKey: () => 'user-7',
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));
jest.mock('../src/services/api/courses', () => ({
  getLearningCourses: () => mockLearningCourses(),
}));
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/learning'),
  getCertificates: () => mockCertificates(),
  getCachedCertificates: async () => [],
  getLearningCourses: () => mockLearningCourses(),
  hasSession: async () => true,
  issueCertificate: jest.fn(),
  recoverCertificate: jest.fn(),
}));
jest.mock('../src/services/systemActions', () => ({}));
jest.mock('../src/components/VideoPlayer/attachmentActions', () => ({}));
jest.mock('../src/components/VideoPlayer/attachmentDownloadNotice', () => ({
  useAttachmentDownloadCancellation: () => () => undefined,
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/persistence',
  ).applyLocalLearningState,
  getLocalLearningState: jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/persistence',
  ).getLocalLearningState,
  loadCourseLearningData: (...args: unknown[]) => mockLearningMap(...args),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/projects', () => ({
  loadProjectResolution: jest.fn(),
  retryPendingProjectSubmissions: async () => [],
  subscribeProjectSubmissionRecovery: () => () => undefined,
  watchProjectResolution: () => () => undefined,
}));
import {useMyCornerData} from '../src/screens/myCorner/useMyCornerData';
import {useCertificatesController} from '../src/screens/Profile/certificates/useCertificatesController';
import {useProjectReview} from '../src/screens/reels/useProjectReview';
import type {CourseLearningData} from '../src/components/VideoPlayer/types';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const response = (data: unknown) => ({data: {data}});
const course = {
  id: '3',
  title: 'كورس ركن',
  progress: 50,
  totalSections: 2,
  completedSections: 1,
};
const tick = async () => {
  for (let i = 0; i < 40; i++) await Promise.resolve();
};

describe('Primary results — remaining reproductions and prepared repair acceptance', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGet.mockImplementation(async (route: string) =>
      response(route === 'user/paths' ? [] : {}),
    );
    mockLearningCourses.mockResolvedValue([course]);
  });
  afterEach(() => jest.restoreAllMocks());

  it('shows fresh owned courses before an unrelated streak read finishes', async () => {
    const streak = deferred<ReturnType<typeof response>>();
    mockGet.mockImplementation((route: string) =>
      route === 'streaks'
        ? streak.promise
        : Promise.resolve(response(route === 'user/paths' ? [] : {})),
    );
    let current!: ReturnType<typeof useMyCornerData>;
    const Probe = () => {
      current = useMyCornerData('user-7');
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Probe />);
        await tick();
      });
      expect(mockLearningCourses).toHaveBeenCalledTimes(1);
      expect(current.dashboard?.courses).toEqual([course]);
      expect(current.dashboardLoading).toBe(false);
      expect(current.learningOwnershipFresh).toBe(true);
      await act(async () => {
        streak.resolve(response({}));
        await tick();
      });
      expect(current.dashboard?.courses).toEqual([course]);
      expect(current.learningOwnershipFresh).toBe(true);
    } finally {
      if (renderer) await act(async () => renderer.unmount());
    }
  });

  it('shows an already-issued fresh certificate before learning eligibility finishes', async () => {
    const eligibility = deferred<(typeof course)[]>();
    mockLearningCourses.mockReturnValue(eligibility.promise);
    const certificate = {
      publicId: 'audit-issued-7',
      courseId: '3',
      status: 'active',
    };
    mockCertificates.mockResolvedValue([certificate]);
    let current!: ReturnType<typeof useCertificatesController>;
    const Probe = () => {
      current = useCertificatesController();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Probe />);
        await tick();
      });
      expect(mockCertificates).toHaveBeenCalledTimes(1);
      expect(current.certificates).toEqual([certificate]);
      expect(current.loading).toBe(false);
      expect(current.issueReady).toBe(false);
      await act(async () => {
        eligibility.resolve([]);
        await tick();
      });
      expect(current.certificates).toEqual([certificate]);
      expect(current.loading).toBe(false);
    } finally {
      if (renderer) await act(async () => renderer.unmount());
    }
  });

  it('bounds the optional player-state read when publishing the fresh passed-project map', async () => {
    jest.useFakeTimers();
    const localRead = deferred<string | null>();
    jest.spyOn(AsyncStorage, 'getItem').mockReturnValueOnce(localRead.promise);
    const learning: CourseLearningData = {
      id: '3',
      title: 'ركن',
      totalReels: 0,
      attachments: [],
      modules: [
        {
          id: '4',
          title: 'الوحدة',
          order: 1,
          isLocked: false,
          reels: [],
          projects: [
            {
              id: '7',
              sectionId: '9',
              moduleId: '4',
              title: 'مشروع',
              requirements: 'صمم',
              status: 'evaluating',
              isGraduationProject: false,
              canSubmit: false,
              canContinue: false,
            },
          ],
        },
      ],
    };
    const fresh: CourseLearningData = {
      ...learning,
      modules: [
        {
          ...learning.modules[0],
          projects: [{...learning.modules[0].projects![0], status: 'passed'}],
        },
      ],
    };
    mockLearningMap.mockResolvedValue({course: fresh});
    const publish = jest.fn();
    const refs = {
      loadedCourse: {current: learning},
      mounted: {current: true},
      ownerGeneration: {current: 1},
      reviewWatcher: {current: 0},
      watchedProject: {current: null as string | null},
    };
    let current!: ReturnType<typeof useProjectReview>;
    const Probe = () => {
      current = useProjectReview({
        active: true,
        course: learning,
        previewMode: false,
        refs,
        setCourse: publish,
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    let refresh!: ReturnType<typeof current.refreshProjectState>;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Probe />);
        await tick();
      });
      await act(async () => {
        refresh = current.refreshProjectState('7');
        await tick();
      });
      expect(mockLearningMap).toHaveBeenCalledTimes(1);
      expect(AsyncStorage.getItem).toHaveBeenCalledTimes(1);
      expect(publish).not.toHaveBeenCalled();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(250);
        await refresh;
      });
      expect(publish).toHaveBeenCalledWith(fresh);
      await act(async () => {
        localRead.resolve(null);
        await tick();
      });
      expect(publish).toHaveBeenCalledTimes(1);
    } finally {
      localRead.resolve(null);
      if (renderer) await act(async () => renderer.unmount());
      jest.useRealTimers();
    }
  });
});
