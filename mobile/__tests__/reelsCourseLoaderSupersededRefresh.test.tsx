import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockLoadCourse = jest.fn();

jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: async (course: unknown) => course,
  getLocalLearningState: async () => ({positions: {}, savedLessons: []}),
  loadCourseLearningData: (...args: unknown[]) => mockLoadCourse(...args),
  reconcileServerSavedLessons: async () => [],
  retryPendingSectionCompletions: async () => undefined,
}));
jest.mock(
  '../src/components/VideoPlayer/courseLearning/navigationHandoff',
  () => ({learningNavigationHandoff: {take: () => null}}),
);
jest.mock('../src/services/learningPlayerState', () => ({
  emptyLocalLearningState: () => ({positions: {}, savedLessons: []}),
  overlayLocalLearningState: (course: unknown) => course,
}));
jest.mock('../src/services/roknApi', () => ({hasSession: async () => true}));
jest.mock('../src/screens/reels/presentation', () => ({
  buildAccessibleFeed: () => [],
  buildPreviewFeed: () => [],
  resolveReelsFeedAnchor: () => null,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'learner', epoch: 1}),
  assertAccountSessionBoundary: () => undefined,
}));

import {
  useReelsCourseLoader,
  type CourseReloadTarget,
} from '../src/screens/reels/useReelsCourseLoader';

const course = {id: 'course-1', accessType: 'full', modules: []};
const deferred = () => {
  let resolve!: (value: unknown) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};

// Let a started load reach its course read before the next one supersedes it.
const flush = async () => {
  for (let tick = 0; tick < 12; tick += 1) await Promise.resolve();
};

const renderLoader = async () => {
  let load!: (target?: CourseReloadTarget) => Promise<void>;
  const refs = {
    closedPlaybackSessions: {current: new Set<string>()},
    loadRequest: {current: 0},
    loadAbort: {current: null},
    loadedCourse: {current: null},
    loadedCourseOwner: {current: ''},
    playbackDurations: {current: {}},
    playbackRuntime: {current: {}},
    positions: {current: {}},
  };
  const props = {
    navigation: {replace: jest.fn()},
    identityKey: 'learner',
    params: {courseId: 'course-1'},
    previewMode: false,
    refs,
    setConnectionNote: jest.fn(),
    setCourse: jest.fn(),
    setLoadError: jest.fn(),
    setLoading: jest.fn(),
    setPreviewGateVisible: jest.fn(),
    requestInitialPosition: jest.fn(),
    setSavedLessons: jest.fn(),
    setServerSession: jest.fn(),
  };
  const Harness = () => {
    load = useReelsCourseLoader(props as never);
    return null;
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
  });
  return {load: () => load, renderer};
};

describe('reels course loader superseded refresh', () => {
  beforeEach(() => {
    mockLoadCourse.mockReset().mockResolvedValue({course});
  });

  it('reports the winning load to a refresh that a newer load superseded', async () => {
    const {load, renderer} = await renderLoader();
    const older = deferred();
    const newer = deferred();
    mockLoadCourse
      .mockReturnValueOnce(older.promise)
      .mockReturnValueOnce(newer.promise);
    const olderResult = jest.fn();
    const newerResult = jest.fn();

    let olderFlight!: Promise<void>;
    let newerFlight!: Promise<void>;
    await act(async () => {
      olderFlight = load()({index: 2, onResult: olderResult});
      await flush();
      newerFlight = load()({index: 2, onResult: newerResult});
    });
    await act(async () => {
      older.reject(Object.assign(new Error('canceled'), {code: 'ERR_CANCELED'}));
      await Promise.resolve();
    });
    expect(olderResult).not.toHaveBeenCalled();

    await act(async () => {
      newer.resolve({course});
      await Promise.all([olderFlight, newerFlight]);
    });
    expect(newerResult).toHaveBeenCalledWith(true, undefined);
    expect(olderResult).toHaveBeenCalledWith(true, undefined);
    renderer.unmount();
  });

  it('uses an already settled winner immediately', async () => {
    const {load, renderer} = await renderLoader();
    const older = deferred();
    mockLoadCourse
      .mockReturnValueOnce(older.promise)
      .mockResolvedValueOnce({course});
    const olderResult = jest.fn();

    await act(async () => {
      const olderFlight = load()({index: 1, onResult: olderResult});
      await flush();
      await load()({index: 1});
      older.resolve({course});
      await olderFlight;
    });
    expect(olderResult).toHaveBeenCalledWith(true, undefined);
    renderer.unmount();
  });

  it('passes a winning failure through instead of inventing success', async () => {
    const {load, renderer} = await renderLoader();
    const older = deferred();
    mockLoadCourse
      .mockReturnValueOnce(older.promise)
      .mockRejectedValueOnce(Object.assign(new Error('x'), {status: 500}));
    const olderResult = jest.fn();

    await act(async () => {
      const olderFlight = load()({index: 1, onResult: olderResult});
      await flush();
      await load()({index: 1});
      older.resolve({course});
      await olderFlight;
    });
    expect(olderResult).toHaveBeenCalledWith(false, undefined);
    renderer.unmount();
  });

  it('releases a superseded waiter as failed when the screen unmounts', async () => {
    const {load, renderer} = await renderLoader();
    const older = deferred();
    const newer = deferred();
    mockLoadCourse
      .mockReturnValueOnce(older.promise)
      .mockReturnValueOnce(newer.promise);
    const olderResult = jest.fn();
    let olderFlight!: Promise<void>;
    await act(async () => {
      olderFlight = load()({index: 1, onResult: olderResult});
      await flush();
      void load()({index: 1});
      older.resolve({course});
      await Promise.resolve();
    });
    expect(olderResult).not.toHaveBeenCalled();
    // Passive unmount cleanups flush when the act scope closes.
    await act(async () => {
      renderer.unmount();
    });
    await olderFlight;
    expect(olderResult).toHaveBeenCalledWith(false, undefined);
  });
});
