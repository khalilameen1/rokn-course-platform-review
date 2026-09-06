import React from 'react';
import ReactTestRenderer from 'react-test-renderer';

const mockLoadCourseLearningData = jest.fn();
const mockWatchProjectResolution = jest.fn();
const mockRetryPendingProjectSubmissions = jest.fn(async () => []);
let recoveryListener:
  | ((outcomes: ReadonlyArray<Record<string, unknown>>) => void)
  | undefined;

jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.fn(async course => course),
  loadCourseLearningData: (...args: unknown[]) =>
    mockLoadCourseLearningData(...args),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/projects', () => ({
  loadProjectResolution: jest.fn(),
  retryPendingProjectSubmissions: () => mockRetryPendingProjectSubmissions(),
  subscribeProjectSubmissionRecovery: jest.fn(listener => {
    recoveryListener = listener;
    return () => {
      recoveryListener = undefined;
    };
  }),
  watchProjectResolution: (...args: unknown[]) =>
    mockWatchProjectResolution(...args),
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'user-a',
  })),
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: jest.fn(() => true),
}));
jest.mock('../src/screens/reels/presentation', () => ({
  buildAccessibleFeed: jest.fn(course =>
    course.modules.flatMap((module: {id: string; projects?: unknown[]}) =>
      (module.projects || []).map(project => ({
        key: `project-${(project as {id: string}).id}`,
        type: 'project',
        moduleId: module.id,
        project,
      })),
    ),
  ),
}));

import type {
  CourseLearningData,
  ProjectStatus,
} from '../src/components/VideoPlayer/types';
import {useProjectReview} from '../src/screens/reels/useProjectReview';
import {loadProjectResolution} from '../src/components/VideoPlayer/courseLearning/projects';

const courseWithStatus = (status: ProjectStatus): CourseLearningData => ({
  id: 'course-1',
  title: 'الكورس',
  totalReels: 0,
  attachments: [],
  modules: [
    {
      id: 'module-1',
      title: 'الوحدة',
      order: 1,
      isLocked: false,
      reels: [],
      projects: [
        {
          id: 'project-1',
          sectionId: 'section-1',
          moduleId: 'module-1',
          title: 'المشروع',
          requirements: 'نفذ المشروع',
          status,
          isGraduationProject: false,
        },
      ],
    },
  ],
});

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(next => {
    resolve = next;
  });
  return {promise, resolve};
};

describe('project recovery freshness', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    recoveryListener = undefined;
  });

  it('publishes unavailable review without a course reload and resumes the same watcher only after explicit retry', async () => {
    const current = courseWithStatus('evaluating');
    const setCourse = jest.fn();
    const refs = {
      loadedCourse: {current},
      mounted: {current: true},
      ownerGeneration: {current: 1},
      reviewWatcher: {current: 0},
      watchedProject: {current: null as string | null},
    };
    let review!: ReturnType<typeof useProjectReview>;
    const Harness = () => {
      review = useProjectReview({
        active: true,
        course: current,
        previewMode: false,
        refs,
        setCourse,
      });
      return null;
    };
    let renderer!: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(async () => {
      renderer = ReactTestRenderer.create(<Harness />);
    });
    const unavailable = {
      status: 'review_unavailable' as const,
      canSubmit: false,
      canContinue: false,
      feedbackLevel: 'pass_only' as const,
      reportEnabled: false,
      reportStatus: 'not_included' as const,
      replyEnabled: false,
      canRetryReport: false,
      canRetryReview: true,
      reviewRetryEndpoint:
        '/api/v1/project-submissions/11111111-1111-4111-8111-111111111111/review/retry',
      reviewFailureCategory: 'provider_unavailable',
      reviewFeedback: undefined,
      reportRetryEndpoint: undefined,
      feedbackThread: null,
    };
    jest.mocked(loadProjectResolution).mockResolvedValueOnce(unavailable);
    const watcher = mockWatchProjectResolution.mock.calls[0][0];
    try {
      await ReactTestRenderer.act(async () => {
        const result = await watcher.resolve('project-1');
        watcher.onResolution(result);
      });
      expect(mockLoadCourseLearningData).not.toHaveBeenCalled();
      const updated = setCourse.mock.calls[0][0](current);
      expect(updated.modules[0].projects[0]).toMatchObject({
        status: 'review_unavailable',
        canRetryReview: true,
        canContinue: false,
      });
      expect(refs.watchedProject.current).toBeNull();
      await ReactTestRenderer.act(async () => {
        review.applyReviewResolution('project-1', {
          ...unavailable,
          status: 'evaluating',
          canRetryReview: false,
        });
      });
      expect(mockWatchProjectResolution).toHaveBeenCalledTimes(2);
      expect(
        setCourse.mock.calls[1][0](current).modules[0].projects[0].status,
      ).toBe('evaluating');
    } finally {
      await ReactTestRenderer.act(async () => renderer.unmount());
    }
  });

  it('treats a replay as a refresh signal and waits for the current server map', async () => {
    const current = courseWithStatus('passed');
    const fresh = courseWithStatus('passed');
    const request = deferred<{course: CourseLearningData}>();
    mockLoadCourseLearningData.mockReturnValue(request.promise);
    const setCourse = jest.fn();
    const refs = {
      loadedCourse: {current},
      mounted: {current: true},
      ownerGeneration: {current: 1},
      reviewWatcher: {current: 0},
      watchedProject: {current: null},
    };
    const Harness = () => {
      useProjectReview({
        active: true,
        course: current,
        previewMode: false,
        refs,
        setCourse,
      });
      return null;
    };

    let renderer: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(async () => {
      renderer = ReactTestRenderer.create(<Harness />);
      await Promise.resolve();
    });
    expect(recoveryListener).toBeDefined();

    await ReactTestRenderer.act(async () => {
      recoveryListener?.([
        {
          projectId: 'project-1',
          submissionStatus: 'needs_changes',
          accepted: true,
          canContinue: false,
        },
      ]);
      await Promise.resolve();
    });
    expect(setCourse).not.toHaveBeenCalled();

    await ReactTestRenderer.act(async () => {
      request.resolve({course: fresh});
      await request.promise;
      await Promise.resolve();
    });
    expect(setCourse).toHaveBeenCalledWith(fresh);
    expect(setCourse.mock.calls[0][0].modules[0].projects[0].status).toBe(
      'passed',
    );
    await ReactTestRenderer.act(async () => renderer!.unmount());
  });

  it('never replaces a passed map when the recovery refresh fails', async () => {
    const current = courseWithStatus('passed');
    mockLoadCourseLearningData.mockRejectedValue(new Error('offline'));
    const setCourse = jest.fn();
    const refs = {
      loadedCourse: {current},
      mounted: {current: true},
      ownerGeneration: {current: 1},
      reviewWatcher: {current: 0},
      watchedProject: {current: null},
    };
    const Harness = () => {
      useProjectReview({
        active: true,
        course: current,
        previewMode: false,
        refs,
        setCourse,
      });
      return null;
    };

    let renderer: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(async () => {
      renderer = ReactTestRenderer.create(<Harness />);
      await Promise.resolve();
    });
    await ReactTestRenderer.act(async () => {
      recoveryListener?.([
        {
          projectId: 'project-1',
          submissionStatus: 'needs_changes',
          accepted: true,
          canContinue: false,
        },
      ]);
      for (let tick = 0; tick < 5; tick += 1) await Promise.resolve();
    });

    expect(setCourse).not.toHaveBeenCalled();
    expect(mockWatchProjectResolution).toHaveBeenCalledTimes(1);
    await ReactTestRenderer.act(async () => renderer!.unmount());
  });

  it('stops native-page review timers on exit and starts a fresh reader on return', async () => {
    const current = courseWithStatus('evaluating');
    const firstStop = jest.fn();
    const secondStop = jest.fn();
    mockWatchProjectResolution
      .mockReturnValueOnce(firstStop)
      .mockReturnValueOnce(secondStop);
    const refs = {
      loadedCourse: {current},
      mounted: {current: true},
      ownerGeneration: {current: 1},
      reviewWatcher: {current: 0},
      watchedProject: {current: null},
    };
    const setCourse = jest.fn();
    const Harness = ({active}: {active: boolean}) => {
      useProjectReview({
        active,
        course: current,
        previewMode: false,
        refs,
        setCourse,
      });
      return null;
    };
    let renderer!: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(async () => {
      renderer = ReactTestRenderer.create(<Harness active />);
    });
    expect(mockWatchProjectResolution).toHaveBeenCalledTimes(1);
    await ReactTestRenderer.act(async () =>
      renderer.update(<Harness active={false} />),
    );
    expect(firstStop).toHaveBeenCalledTimes(1);
    expect(refs.watchedProject.current).toBeNull();
    await ReactTestRenderer.act(async () =>
      renderer.update(<Harness active />),
    );
    expect(mockWatchProjectResolution).toHaveBeenCalledTimes(2);
    await ReactTestRenderer.act(async () => renderer.unmount());
    expect(secondStop).toHaveBeenCalledTimes(1);
  });
});
