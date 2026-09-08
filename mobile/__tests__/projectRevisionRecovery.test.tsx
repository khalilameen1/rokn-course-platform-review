import React, {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import ReactTestRenderer from 'react-test-renderer';

const mockGet = jest.fn();
const mockPost = jest.fn();
const mockLoadCourse = jest.fn();
let mockEpoch = 1;

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    scope: 'learner',
    epoch: mockEpoch,
  })),
  assertAccountSessionBoundary: (boundary: {epoch: number}) => {
    if (boundary.epoch !== mockEpoch)
      throw new Error('ACCOUNT_SESSION_CHANGED');
  },
}));
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.fn(async course => course),
  loadCourseLearningData: (...args: unknown[]) => mockLoadCourse(...args),
  subscribeCourseRevisionChanges: jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/playbackRevision',
  ).subscribeCourseRevisionChanges,
}));
jest.mock('../src/components/VideoPlayer/courseLearning/projects', () => ({
  ...jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/projectRemote',
  ),
  retryPendingProjectSubmissions: jest.fn(async () => []),
  subscribeProjectSubmissionRecovery: jest.fn(() => () => undefined),
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));

import type {
  CourseLearningData,
  ProjectStatus,
} from '../src/components/VideoPlayer/types';
import {loadProjectResolution} from '../src/components/VideoPlayer/courseLearning/projectRemote';
import {subscribeCourseRevisionChanges} from '../src/components/VideoPlayer/courseLearning/playbackRevision';
import {useProjectReview} from '../src/screens/reels/useProjectReview';
import {useReelsCourseRevision} from '../src/screens/reels/useReelsCourseRevision';
import type {CourseReloadTarget} from '../src/screens/reels/useReelsCourseLoader';

const courseWithProject = (
  id: string,
  status: ProjectStatus,
): CourseLearningData => ({
  id: '7',
  title: 'الكورس',
  totalReels: 0,
  attachments: [],
  certificateAvailable: status === 'passed',
  modules: [
    {
      id: '8',
      title: 'الوحدة',
      order: 1,
      isLocked: false,
      reels: [],
      projects: [
        {
          id,
          sectionId: `${id}0`,
          moduleId: '8',
          title: 'المشروع',
          requirements: 'نفذ المشروع',
          status,
          isGraduationProject: true,
          canContinue: status === 'passed',
          reportStatus: 'not_included',
        },
      ],
    },
  ],
});

const resolutionResponse = (status: ProjectStatus) => ({
  data: {
    data: {
      latest_submission: {
        id: '11111111-1111-4111-8111-111111111111',
        submission_status: status,
        can_submit: status === 'needs_changes',
        can_continue: status === 'passed',
        report_status: 'not_included',
        feedback_level: 'pass_only',
      },
    },
  },
});

// The API interceptor rejects this backend response directly, not an Error.
const changedRevision = {
  status: 409,
  data: {
    code: 'course_revision_changed',
    data: {
      course_id: 7,
      published_revision: 2,
      reload_endpoint: '/api/v1/courses/7/details',
    },
  },
};

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return {promise, resolve, reject};
};

const flush = async () => {
  for (let tick = 0; tick < 12; tick += 1) await Promise.resolve();
};

const mountJourney = async (
  initial = courseWithProject('11', 'evaluating'),
) => {
  let current = initial;
  let review!: ReturnType<typeof useProjectReview>;
  let watchedProject!: React.MutableRefObject<string | null>;
  let replaceCourse!: (course: CourseLearningData) => void;
  const reload = jest.fn();
  const Harness = ({active = true}: {active?: boolean}) => {
    const [course, updateCourse] = useState<CourseLearningData | null>(initial);
    const loadedCourse = useRef<CourseLearningData | null>(initial);
    const mounted = useRef(true);
    const ownerGeneration = useRef(1);
    const reviewWatcher = useRef(0);
    const watched = useRef<string | null>(null);
    watchedProject = watched;
    const refs = useMemo(
      () => ({
        loadedCourse,
        mounted,
        ownerGeneration,
        reviewWatcher,
        watchedProject: watched,
      }),
      [],
    );
    const setCourse = useCallback<
      React.Dispatch<React.SetStateAction<CourseLearningData | null>>
    >(value => {
      updateCourse(previous => {
        const next = typeof value === 'function' ? value(previous) : value;
        loadedCourse.current = next;
        return next;
      });
    }, []);
    replaceCourse = setCourse;
    const load = useCallback(
      async (target?: CourseReloadTarget) => {
        reload(target);
        const result = await mockLoadCourse('7');
        if (!mounted.current) return;
        setCourse(result.course);
        target?.onResult?.(true);
      },
      [setCourse],
    );
    useReelsCourseRevision({
      activeReel: useRef(undefined),
      closedSessions: useRef(new Set()),
      currentIndex: useRef(0),
      invalidateManifests: useCallback(() => {}, []),
      load,
      loadedCourse,
      mounted,
      pending: useRef(false),
      reloadFlight: useRef(null),
      setConnectionNote: useCallback(() => {}, []),
      setRefreshing: useCallback(() => {}, []),
    });
    review = useProjectReview({
      active,
      course,
      previewMode: false,
      refs,
      setCourse,
    });
    useEffect(
      () => () => {
        mounted.current = false;
      },
      [],
    );
    current = course!;
    return null;
  };
  let renderer!: ReactTestRenderer.ReactTestRenderer;
  await ReactTestRenderer.act(async () => {
    renderer = ReactTestRenderer.create(<Harness />);
    await flush();
  });
  return {
    reload,
    renderer,
    setActive: (active: boolean) =>
      renderer.update(<Harness active={active} />),
    replaceCourse: (course: CourseLearningData) => replaceCourse(course),
    course: () => current,
    review: () => review,
    watched: () => watchedProject.current,
  };
};

describe('accepted project review after a course revision is published', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockEpoch = 1;
  });
  afterEach(() => {
    jest.useRealTimers();
  });

  it('reloads the canonical course on old-project 409 and retires its watcher after the passed replacement arrives', async () => {
    const fresh = deferred<{course: CourseLearningData}>();
    mockGet.mockRejectedValue(changedRevision);
    mockLoadCourse.mockReturnValue(fresh.promise);
    const journey = await mountJourney();
    try {
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(3000);
        await flush();
      });
      expect(journey.reload).toHaveBeenCalledTimes(1);
      expect(mockLoadCourse).toHaveBeenCalledWith('7');
      expect(journey.course().modules[0].projects?.[0].status).toBe(
        'evaluating',
      );
      expect(journey.course().certificateAvailable).toBe(false);
      await ReactTestRenderer.act(async () => {
        fresh.resolve({course: courseWithProject('22', 'passed')});
        await flush();
      });
      expect(journey.course().modules[0].projects?.[0]).toMatchObject({
        id: '22',
        status: 'passed',
      });
      expect(journey.course().certificateAvailable).toBe(true);
      expect(journey.watched()).toBeNull();
      // A foreground submission callback can finish after the new map mounts.
      journey.review().watchProjectUntilResolved('11');
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(60000);
        await flush();
      });
      expect(mockGet).toHaveBeenCalledTimes(1);
      expect(mockPost).not.toHaveBeenCalled();
    } finally {
      await ReactTestRenderer.act(async () => journey.renderer.unmount());
    }
  });

  it('watches the current project if publication arrives before its original submission review finishes', async () => {
    mockGet
      .mockRejectedValueOnce(changedRevision)
      .mockResolvedValue(resolutionResponse('passed'));
    mockLoadCourse
      .mockResolvedValueOnce({course: courseWithProject('22', 'evaluating')})
      .mockResolvedValue({course: courseWithProject('22', 'passed')});
    const journey = await mountJourney();
    try {
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(3000);
        await flush();
      });
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(3000);
        await flush();
      });
      expect(mockGet.mock.calls.map(call => call[0])).toEqual([
        'projects/11',
        'projects/22',
      ]);
      expect(journey.course().modules[0].projects?.[0].status).toBe('passed');
      expect(mockPost).not.toHaveBeenCalled();
    } finally {
      await ReactTestRenderer.act(async () => journey.renderer.unmount());
    }
  });

  it('keeps the authoritative current map when the old project passes just before IDs change', async () => {
    mockGet.mockResolvedValue(resolutionResponse('passed'));
    mockLoadCourse.mockResolvedValue({
      course: courseWithProject('22', 'passed'),
    });
    const journey = await mountJourney();
    try {
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(3000);
        await flush();
      });
      expect(journey.course().modules[0].projects?.[0]).toMatchObject({
        id: '22',
        status: 'passed',
      });
      expect(journey.course().certificateAvailable).toBe(true);
      expect(journey.watched()).toBeNull();
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(60000);
        await flush();
      });
      expect(mockGet).toHaveBeenCalledTimes(1);
      expect(mockPost).not.toHaveBeenCalled();
    } finally {
      await ReactTestRenderer.act(async () => journey.renderer.unmount());
    }
  });

  it('retries ordinary network failure without pretending that the project revision changed', async () => {
    mockGet
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValue(resolutionResponse('passed'));
    mockLoadCourse.mockResolvedValue({
      course: courseWithProject('11', 'passed'),
    });
    const journey = await mountJourney();
    try {
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(3000);
        await flush();
      });
      expect(journey.reload).not.toHaveBeenCalled();
      expect(mockLoadCourse).not.toHaveBeenCalled();
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(5000);
        await flush();
      });
      expect(journey.course().modules[0].projects?.[0].status).toBe('passed');
      expect(mockGet).toHaveBeenCalledTimes(2);
      expect(mockPost).not.toHaveBeenCalled();
    } finally {
      await ReactTestRenderer.act(async () => journey.renderer.unmount());
    }
  });

  it('cannot replace a newer published map with an older in-flight review map', async () => {
    const oldMap = deferred<{course: CourseLearningData}>();
    mockGet.mockResolvedValue(resolutionResponse('passed'));
    mockLoadCourse.mockReturnValue(oldMap.promise);
    const journey = await mountJourney();
    try {
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(3000);
        await flush();
      });
      expect(mockLoadCourse).toHaveBeenCalledTimes(1);
      await ReactTestRenderer.act(async () => {
        journey.replaceCourse(courseWithProject('22', 'passed'));
        await flush();
      });
      await ReactTestRenderer.act(async () => {
        oldMap.resolve({course: courseWithProject('11', 'passed')});
        await flush();
      });
      expect(journey.course().modules[0].projects?.[0].id).toBe('22');
      expect(journey.watched()).toBeNull();
      expect(mockPost).not.toHaveBeenCalled();
    } finally {
      await ReactTestRenderer.act(async () => journey.renderer.unmount());
    }
  });

  it('does not signal a replacement account from a late old-owner revision response', async () => {
    const request = deferred<unknown>();
    mockGet.mockReturnValue(request.promise);
    const observer = jest.fn();
    const unsubscribe = subscribeCourseRevisionChanges(observer);
    try {
      const read = loadProjectResolution('11').catch(error => error);
      await flush();
      mockEpoch = 2;
      request.reject(changedRevision);
      expect(await read).toMatchObject({message: 'ACCOUNT_SESSION_CHANGED'});
      expect(observer).not.toHaveBeenCalled();
    } finally {
      unsubscribe();
    }
  });

  it('resumes the evaluating replacement on refocus after an old map response was discarded', async () => {
    const oldMap = deferred<{course: CourseLearningData}>();
    mockGet
      .mockResolvedValueOnce(resolutionResponse('passed'))
      .mockResolvedValue(resolutionResponse('evaluating'));
    mockLoadCourse.mockReturnValue(oldMap.promise);
    const journey = await mountJourney();
    try {
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(3000);
        await flush();
      });
      expect(mockLoadCourse).toHaveBeenCalledTimes(1);
      await ReactTestRenderer.act(async () => {
        journey.replaceCourse(courseWithProject('22', 'evaluating'));
        await flush();
      });
      await ReactTestRenderer.act(async () => {
        oldMap.resolve({course: courseWithProject('11', 'passed')});
        await flush();
      });
      await ReactTestRenderer.act(async () => {
        journey.setActive(false);
        await flush();
      });
      await ReactTestRenderer.act(async () => {
        journey.setActive(true);
        await flush();
      });
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(3000);
        await flush();
      });
      expect(mockGet.mock.calls.map(call => call[0])).toEqual([
        'projects/11',
        'projects/22',
      ]);
      expect(journey.watched()).toBe('22');
      expect(journey.course().modules[0].projects?.[0]).toMatchObject({
        id: '22',
        status: 'evaluating',
      });
      expect(mockPost).not.toHaveBeenCalled();
    } finally {
      await ReactTestRenderer.act(async () => journey.renderer.unmount());
    }
  });

  it('does not begin a course-map refresh for an old project response after its watcher was retired', async () => {
    const oldRead = deferred<unknown>();
    mockGet
      .mockReturnValueOnce(oldRead.promise)
      .mockResolvedValue(resolutionResponse('evaluating'));
    const journey = await mountJourney();
    try {
      await ReactTestRenderer.act(async () => {
        jest.advanceTimersByTime(3000);
        await flush();
      });
      await ReactTestRenderer.act(async () => {
        journey.replaceCourse(courseWithProject('22', 'evaluating'));
        await flush();
      });
      await ReactTestRenderer.act(async () => {
        oldRead.resolve(resolutionResponse('passed'));
        await flush();
      });
      expect(mockLoadCourse).not.toHaveBeenCalled();
      expect(journey.watched()).toBe('22');
      expect(mockPost).not.toHaveBeenCalled();
    } finally {
      await ReactTestRenderer.act(async () => journey.renderer.unmount());
    }
  });

  it('does not reload an unmounted course when its old project read finishes late', async () => {
    const request = deferred<unknown>();
    mockGet.mockReturnValue(request.promise);
    const journey = await mountJourney();
    await ReactTestRenderer.act(async () => {
      jest.advanceTimersByTime(3000);
      await flush();
    });
    await ReactTestRenderer.act(async () => journey.renderer.unmount());
    request.reject(changedRevision);
    await flush();
    expect(journey.reload).not.toHaveBeenCalled();
    expect(mockLoadCourse).not.toHaveBeenCalled();
    expect(mockPost).not.toHaveBeenCalled();
  });
});
