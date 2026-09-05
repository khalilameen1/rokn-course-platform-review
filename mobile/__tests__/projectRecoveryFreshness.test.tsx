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
    expect(
      setCourse.mock.calls[0][0].modules[0].projects[0].status,
    ).toBe('passed');
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
});
