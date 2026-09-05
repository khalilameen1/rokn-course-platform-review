import React from 'react';
import ReactTestRenderer from 'react-test-renderer';

const mockLoadCourseLearningData = jest.fn();

jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.fn(async course => course),
  loadCourseLearningData: (...args: unknown[]) =>
    mockLoadCourseLearningData(...args),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/projects', () => ({
  loadProjectResolution: jest.fn(),
  retryPendingProjectSubmissions: jest.fn(async () => []),
  subscribeProjectSubmissionRecovery: jest.fn(() => () => undefined),
  watchProjectResolution: jest.fn(),
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppActiveState: jest.fn(() => true),
}));
jest.mock('../src/screens/reels/presentation', () => ({
  buildAccessibleFeed: jest.fn(() => []),
}));

import type {
  CourseLearningData,
  ProjectStatus,
} from '../src/components/VideoPlayer/types';
import {useProjectReview} from '../src/screens/reels/useProjectReview';

const courseWithStatus = (status: ProjectStatus): CourseLearningData => ({
  id: '1',
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

it('does not let an older project-map response replace a newer one', async () => {
  const evaluating = courseWithStatus('evaluating');
  const passed = courseWithStatus('passed');
  const older = deferred<{course: CourseLearningData}>();
  const newer = deferred<{course: CourseLearningData}>();
  mockLoadCourseLearningData
    .mockReturnValueOnce(older.promise)
    .mockReturnValueOnce(newer.promise);

  const setCourse = jest.fn();
  const refs = {
    loadedCourse: {current: evaluating},
    mounted: {current: true},
    ownerGeneration: {current: 1},
    reviewWatcher: {current: 0},
    watchedProject: {current: null},
  };
  let refreshProjectState!: (projectId: string) => Promise<unknown>;
  const Harness = () => {
    refreshProjectState = useProjectReview({
      active: false,
      course: evaluating,
      previewMode: false,
      refs,
      setCourse,
    }).refreshProjectState;
    return null;
  };

  let renderer!: ReactTestRenderer.ReactTestRenderer;
  await ReactTestRenderer.act(async () => {
    renderer = ReactTestRenderer.create(<Harness />);
  });

  const olderRefresh = refreshProjectState('project-1');
  const newerRefresh = refreshProjectState('project-1');
  await ReactTestRenderer.act(async () => {
    newer.resolve({course: passed});
    await newerRefresh;
  });
  await ReactTestRenderer.act(async () => {
    older.resolve({course: evaluating});
    await olderRefresh;
  });

  const lastMap = setCourse.mock.calls.at(-1)?.[0] as CourseLearningData;
  expect(lastMap.modules[0].projects?.[0].status).toBe('passed');

  await ReactTestRenderer.act(async () => renderer.unmount());
});
