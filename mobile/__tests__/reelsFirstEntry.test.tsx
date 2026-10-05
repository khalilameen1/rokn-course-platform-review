import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockLoad = jest.fn();
const mockLocalState = jest.fn();
const mockRetryCompletions = jest.fn(async () => undefined);
const mockSavedLessons = jest.fn();
let mockBoundary = {epoch: 1, scope: 'learner-7'};
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.epoch !== mockBoundary.epoch ||
      boundary.scope !== mockBoundary.scope
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: async () => !mockBoundary.scope.startsWith('guest-'),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  getLocalLearningState: (...args: unknown[]) => mockLocalState(...args),
  loadCourseLearningData: (...args: unknown[]) => mockLoad(...args),
  applyLocalLearningState: jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/persistence',
  ).applyLocalLearningState,
  reconcileServerSavedLessons: (...args: unknown[]) =>
    mockSavedLessons(...args),
  retryPendingSectionCompletions: () => mockRetryCompletions(),
}));

import type {CourseLearningData} from '../src/components/VideoPlayer/types';
import {
  createLearningNavigationHandoff,
  learningNavigationHandoff,
} from '../src/components/VideoPlayer/courseLearning/navigationHandoff';
import {emptyLocalLearningState} from '../src/components/VideoPlayer/courseLearning/persistence';
import {useReelsCourseLoader} from '../src/screens/reels/useReelsCourseLoader';

const makeCourse = (): CourseLearningData => ({
  id: '3',
  title: 'الكورس',
  totalReels: 2,
  accessType: 'paid',
  attachments: [],
  modules: [
    {
      id: '1',
      title: 'الوحدة',
      order: 1,
      isLocked: false,
      reels: [1, 2].map(index => ({
        id: String(index),
        lessonId: String(index),
        sectionId: String(index),
        moduleId: '1',
        title: 'مقطع',
        caption: '',
        videoUrl: '',
        availableQualities: ['auto'],
        isPreview: false,
        isLocked: index === 2,
        isCompleted: false,
        reelNumber: index,
      })),
    },
  ],
});

describe('fresh one-use learning navigation', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    mockBoundary = {epoch: 1, scope: 'learner-7'};
  });
  afterEach(() => jest.useRealTimers());

  it('consumes the exact course/owner once without issuing media or unlocking gates', () => {
    const handoff = createLearningNavigationHandoff();
    const course = makeCourse();
    const key = handoff.prepare({
      course,
      boundary: mockBoundary,
      receivedAt: Date.now(),
    });
    expect(handoff.take(key, '3', mockBoundary)).toBe(course);
    expect(course.modules[0].reels[0].videoUrl).toBe('');
    expect(course.modules[0].reels[1].isLocked).toBe(true);
    expect(handoff.take(key, '3', mockBoundary)).toBeNull();
  });

  it.each(['account', 'epoch', 'course', 'age', 'clear'])(
    'does not transfer a read after %s changes',
    reason => {
      const handoff = createLearningNavigationHandoff();
      const key = handoff.prepare({
        course: makeCourse(),
        boundary: {...mockBoundary},
        receivedAt: Date.now(),
      });
      if (reason === 'account') mockBoundary = {epoch: 2, scope: 'learner-8'};
      if (reason === 'epoch') mockBoundary = {...mockBoundary, epoch: 2};
      if (reason === 'age') jest.advanceTimersByTime(30_000);
      if (reason === 'clear') handoff.clear();
      expect(
        handoff.take(key, reason === 'course' ? '4' : '3', mockBoundary),
      ).toBeNull();
    },
  );

  it('never renews freshness at navigation time', () => {
    const handoff = createLearningNavigationHandoff();
    expect(
      handoff.prepare({
        course: makeCourse(),
        boundary: mockBoundary,
        receivedAt: Date.now() - 30_000,
      }),
    ).toBeUndefined();
  });
});

describe('first reel metadata critical path', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let reload: ReturnType<typeof useReelsCourseLoader>;
  let params: Parameters<typeof useReelsCourseLoader>[0];
  let deliveredCourse: CourseLearningData | null;
  const Harness = () => {
    reload = useReelsCourseLoader(params);
    return null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  beforeEach(() => {
    jest.useFakeTimers();
    mockBoundary = {epoch: 1, scope: 'learner-7'};
    learningNavigationHandoff.clear();
    mockLoad.mockReset().mockResolvedValue({course: makeCourse()});
    mockLocalState.mockReset().mockResolvedValue(emptyLocalLearningState());
    mockRetryCompletions.mockClear();
    mockSavedLessons.mockReset().mockResolvedValue([]);
    deliveredCourse = null;
    params = {
      navigation: {replace: jest.fn()},
      identityKey: 'learner-7',
      params: {courseId: '3'},
      previewMode: false,
      refs: {
        closedPlaybackSessions: {current: new Set()},
        loadRequest: {current: 0},
        loadAbort: {current: null},
        loadedCourse: {current: null},
        loadedCourseOwner: {current: 'learner-7'},
        playbackDurations: {current: {}},
        playbackRuntime: {current: {}},
        positions: {current: {}},
      },
      setConnectionNote: jest.fn(),
      setCourse: jest.fn(update => {
        deliveredCourse =
          typeof update === 'function' ? update(deliveredCourse) : update;
      }),
      setLoadError: jest.fn(),
      setLoading: jest.fn(),
      setPreviewGateVisible: jest.fn(),
      requestInitialPosition: jest.fn(),
      savedLessonsVersion: {current: 0},
      setSavedLessons: jest.fn(() => {
        params.savedLessonsVersion!.current += 1;
      }),
      setServerSession: jest.fn(),
    };
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer?.unmount());
    renderer = undefined;
    learningNavigationHandoff.clear();
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it('uses a fresh details handoff then goes back to authoritative reads on reload', async () => {
    params.params.learningHandoffKey = learningNavigationHandoff.prepare({
      course: makeCourse(),
      boundary: mockBoundary,
      receivedAt: Date.now(),
    });
    await mount();
    expect(mockLoad).not.toHaveBeenCalled();
    expect(mockRetryCompletions).toHaveBeenCalledTimes(1);
    expect(mockLocalState).toHaveBeenCalledTimes(1);
    expect(params.setCourse).toHaveBeenCalledWith(
      expect.objectContaining({id: '3'}),
    );
    await act(async () => {
      await reload();
    });
    expect(mockLoad).toHaveBeenCalledTimes(1);
  });

  it('falls back to a new server read when a transition expires', async () => {
    params.params.learningHandoffKey = learningNavigationHandoff.prepare({
      course: makeCourse(),
      boundary: mockBoundary,
      receivedAt: Date.now(),
    });
    jest.advanceTimersByTime(30_000);
    await mount();
    expect(mockLoad).toHaveBeenCalledTimes(1);
  });

  it('starts the server read alongside optional storage and never stalls on it', async () => {
    mockLocalState.mockReturnValue(new Promise(() => undefined));
    await mount();
    expect(mockLoad).toHaveBeenCalledTimes(1);
    expect(params.setCourse).not.toHaveBeenCalledWith(
      expect.objectContaining({id: '3'}),
    );
    await act(async () => {
      jest.advanceTimersByTime(250);
    });
    expect(params.setCourse).toHaveBeenCalledWith(
      expect.objectContaining({id: '3'}),
    );
    expect(params.setLoading).toHaveBeenLastCalledWith(false);
    expect(mockLocalState).toHaveBeenCalledTimes(1);
  });

  it('preserves timely resume positions and completion without unlocking a paid gate', async () => {
    mockLocalState.mockResolvedValue({
      ...emptyLocalLearningState(),
      positions: {'3:1': 12},
      completedSections: ['2'],
      savedLessons: ['1'],
    });
    await mount();
    expect(params.refs.positions.current).toEqual({'3:1': 12});
    expect(params.refs.loadedCourse.current?.modules[0].reels[1]).toMatchObject(
      {isCompleted: true, isLocked: true, videoUrl: ''},
    );
  });

  it('does not turn a fresh public outline into owned learning access', async () => {
    const course = {...makeCourse(), accessType: 'none'};
    params.params.learningHandoffKey = learningNavigationHandoff.prepare({
      course,
      boundary: mockBoundary,
      receivedAt: Date.now(),
    });
    await mount();
    expect(params.navigation.replace).toHaveBeenCalledWith('CourseDetails', {
      courseId: '3',
    });
    expect(params.refs.loadedCourse.current).toBeNull();
  });

  it('restores an eventual local read without moving the feed or replacing newer progress', async () => {
    let finish!: (value: ReturnType<typeof emptyLocalLearningState>) => void;
    mockLocalState.mockReturnValueOnce(
      new Promise(resolve => {
        finish = resolve;
      }),
    );
    mockSavedLessons.mockReturnValue(new Promise(() => undefined));
    await mount();
    await act(async () => {
      jest.advanceTimersByTime(250);
    });
    params.refs.positions.current['3:1'] = 5;
    await act(async () => {
      finish({
        ...emptyLocalLearningState(),
        positions: {'3:1': 12, '3:2': 8},
        savedLessons: ['1'],
        completedSections: ['2'],
      });
    });
    expect(params.refs.positions.current).toEqual({'3:1': 5, '3:2': 8});
    expect(params.requestInitialPosition).toHaveBeenCalledTimes(1);
    expect(deliveredCourse?.modules[0].reels[1]).toMatchObject({
      isCompleted: true,
      isLocked: true,
    });
    expect(params.setSavedLessons).toHaveBeenLastCalledWith(new Set(['1']));
  });

  it('does not let a late device read replace already reconciled server bookmarks', async () => {
    let finish!: (value: ReturnType<typeof emptyLocalLearningState>) => void;
    mockLocalState.mockReturnValueOnce(
      new Promise(resolve => {
        finish = resolve;
      }),
    );
    mockSavedLessons.mockResolvedValue(['2']);
    await mount();
    await act(async () => {
      jest.advanceTimersByTime(250);
    });
    await act(async () => {
      finish({...emptyLocalLearningState(), savedLessons: ['1']});
    });
    expect(params.setSavedLessons).toHaveBeenLastCalledWith(new Set(['2']));
  });

  it('does not undo a bookmark command admitted after first paint', async () => {
    let finish!: (value: ReturnType<typeof emptyLocalLearningState>) => void;
    mockLocalState.mockReturnValueOnce(
      new Promise(resolve => {
        finish = resolve;
      }),
    );
    mockSavedLessons.mockReturnValue(new Promise(() => undefined));
    await mount();
    await act(async () => {
      jest.advanceTimersByTime(250);
    });
    params.setSavedLessons(new Set(['2']));
    await act(async () => {
      finish({...emptyLocalLearningState(), savedLessons: ['1']});
    });
    expect(params.setSavedLessons).toHaveBeenLastCalledWith(new Set(['2']));
  });

  it('keeps live positions and bookmarks during a refresh with delayed storage', async () => {
    await mount();
    params.refs.positions.current = {'3:1': 24};
    params.setSavedLessons(new Set(['2']));
    let finish!: (value: ReturnType<typeof emptyLocalLearningState>) => void;
    mockLocalState.mockReturnValueOnce(
      new Promise(resolve => {
        finish = resolve;
      }),
    );
    mockSavedLessons.mockReturnValue(new Promise(() => undefined));
    let reloadFlight!: Promise<void>;
    await act(async () => {
      reloadFlight = reload({index: 0});
    });
    await act(async () => {
      jest.advanceTimersByTime(250);
      await reloadFlight;
    });
    expect(params.refs.positions.current).toEqual({'3:1': 24});
    expect(params.setSavedLessons).toHaveBeenLastCalledWith(new Set(['2']));
    await act(async () => {
      finish({
        ...emptyLocalLearningState(),
        positions: {'3:1': 12, '3:2': 8},
        savedLessons: ['1'],
      });
    });
    expect(params.refs.positions.current).toEqual({'3:1': 24, '3:2': 8});
    expect(params.setSavedLessons).toHaveBeenLastCalledWith(new Set(['2']));
  });

  it('does not let a refresh replace a bookmark command made during its metadata read', async () => {
    await mount();
    let finishMetadata!: (value: {course: CourseLearningData}) => void;
    mockLoad.mockReturnValueOnce(
      new Promise(resolve => {
        finishMetadata = resolve;
      }),
    );
    mockLocalState.mockResolvedValueOnce({
      ...emptyLocalLearningState(),
      savedLessons: ['1'],
    });
    mockSavedLessons.mockResolvedValue(['1']);
    let reloadFlight!: Promise<void>;
    await act(async () => {
      reloadFlight = reload({index: 0});
    });
    params.setSavedLessons(new Set(['2']));
    await act(async () => {
      finishMetadata({course: makeCourse()});
      await reloadFlight;
    });
    expect(params.setSavedLessons).toHaveBeenLastCalledWith(new Set(['2']));
  });

  it('retires late local work after an authoritative reload', async () => {
    let finish!: (value: ReturnType<typeof emptyLocalLearningState>) => void;
    mockLocalState.mockReturnValueOnce(
      new Promise(resolve => {
        finish = resolve;
      }),
    );
    await mount();
    await act(async () => {
      jest.advanceTimersByTime(250);
    });
    await act(async () => {
      await reload({index: 0});
    });
    await act(async () => {
      finish({...emptyLocalLearningState(), positions: {'3:2': 17}});
    });
    expect(params.refs.positions.current).toEqual({});
    expect(params.requestInitialPosition).toHaveBeenCalledTimes(2);
  });
});
