import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockLoadCourseLearningData = jest.fn();

jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.fn(async course => course),
  getLocalLearningState: jest.fn(async () => ({
    positions: {},
    savedLessons: [],
  })),
  loadCourseLearningData: (...args: unknown[]) =>
    mockLoadCourseLearningData(...args),
  reconcileServerSavedLessons: jest.fn(async () => []),
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({identity: 'user-1'})),
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(async () => true),
}));

import type {CourseLearningData} from '../src/components/VideoPlayer/types';
import {useReelsCourseLoader} from '../src/screens/reels/useReelsCourseLoader';

const course = (completedReels: number): CourseLearningData => ({
  id: 'course-1',
  title: 'الكورس',
  totalReels: 3,
  accessType: 'paid',
  attachments: [],
  modules: [
    {
      id: 'module-1',
      title: 'الوحدة',
      order: 1,
      isLocked: false,
      reels: ['reel-1', 'reel-2', 'reel-3'].map((id, index) => ({
        id,
        lessonId: `lesson-${index + 1}`,
        sectionId: `section-${index + 1}`,
        moduleId: 'module-1',
        title: id,
        caption: '',
        videoUrl: `https://cdn.example/${id}.m3u8`,
        availableQualities: ['auto'],
        isPreview: index === 0,
        isLocked: false,
        isCompleted: index < completedReels,
        reelNumber: index + 1,
      })),
    },
  ],
});

const courseWithProjectBeforePreview = (): CourseLearningData => {
  const value = course(0);
  value.modules[0].reels[0].sectionOrder = 2;
  value.modules[0].reels[1].sectionOrder = 3;
  value.modules[0].reels[1].isPreview = true;
  value.modules[0].projects = [
    {
      id: 'project-1',
      sectionId: 'project-section-1',
      sectionOrder: 1,
      moduleId: 'module-1',
      title: 'مشروع العبور',
      requirements: 'نفذ المشروع',
      status: 'draft',
      isGraduationProject: false,
    },
  ];
  return value;
};

it('consumes continue-after-preview once and does not pull a later reload backwards', async () => {
  mockLoadCourseLearningData
    .mockResolvedValueOnce({course: course(1)})
    .mockResolvedValueOnce({course: course(2)});
  const requestInitialPosition = jest.fn();
  const refs = {
    closedPlaybackSessions: {current: new Set<string>()},
    loadRequest: {current: 0},
    loadAbort: {current: null},
    loadedCourse: {current: null as CourseLearningData | null},
    loadedCourseOwner: {current: 'user-1'},
    playbackDurations: {current: {}},
    playbackRuntime: {current: {}},
    positions: {current: {}},
  };
  let reload!: () => Promise<void>;
  const Harness = () => {
    reload = useReelsCourseLoader({
      navigation: {replace: jest.fn()},
      identityKey: 'user-1',
      params: {
        courseId: 'course-1',
        continueAfterReelId: 'reel-1',
      },
      previewMode: false,
      refs,
      requestInitialPosition,
      setConnectionNote: jest.fn(),
      setCourse: jest.fn(),
      setLoadError: jest.fn(),
      setLoading: jest.fn(),
      setPreviewGateVisible: jest.fn(),
      setSavedLessons: jest.fn(),
      setServerSession: jest.fn(),
    });
    return null;
  };

  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
  });
  expect(requestInitialPosition).toHaveBeenLastCalledWith({key: 'reel-reel-2'});

  await act(async () => {
    await reload();
  });
  expect(requestInitialPosition).toHaveBeenLastCalledWith({index: 2});

  await act(async () => renderer.unmount());
});

it('stops at the required project when the preview source sits beyond it', async () => {
  mockLoadCourseLearningData.mockResolvedValueOnce({
    course: courseWithProjectBeforePreview(),
  });
  const requestInitialPosition = jest.fn();
  const refs = {
    closedPlaybackSessions: {current: new Set<string>()},
    loadRequest: {current: 0},
    loadAbort: {current: null},
    loadedCourse: {current: null as CourseLearningData | null},
    loadedCourseOwner: {current: 'user-1'},
    playbackDurations: {current: {}},
    playbackRuntime: {current: {}},
    positions: {current: {}},
  };
  const Harness = () => {
    useReelsCourseLoader({
      navigation: {replace: jest.fn()},
      identityKey: 'user-1',
      params: {
        courseId: 'course-1',
        continueAfterReelId: 'reel-2',
      },
      previewMode: false,
      refs,
      requestInitialPosition,
      setConnectionNote: jest.fn(),
      setCourse: jest.fn(),
      setLoadError: jest.fn(),
      setLoading: jest.fn(),
      setPreviewGateVisible: jest.fn(),
      setSavedLessons: jest.fn(),
      setServerSession: jest.fn(),
    });
    return null;
  };

  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
  });

  expect(requestInitialPosition).toHaveBeenLastCalledWith({index: 0});
  await act(async () => renderer.unmount());
});
