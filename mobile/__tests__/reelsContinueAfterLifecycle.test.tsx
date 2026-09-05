import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockLoadCourseLearningData = jest.fn();
const mockAssertAccountSessionBoundary = jest.fn();
const mockCaptureAccountSessionBoundary = jest.fn();
let mockActiveIdentity = 'user-1';

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
  assertAccountSessionBoundary: (boundary: {identity: string}) =>
    mockAssertAccountSessionBoundary(boundary),
  captureAccountSessionBoundary: () => mockCaptureAccountSessionBoundary(),
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(async () => true),
}));

import type {CourseLearningData} from '../src/components/VideoPlayer/types';
import {useReelsCourseLoader} from '../src/screens/reels/useReelsCourseLoader';

beforeEach(() => {
  jest.clearAllMocks();
  mockLoadCourseLearningData.mockReset();
  mockActiveIdentity = 'user-1';
  mockCaptureAccountSessionBoundary.mockImplementation(async () => ({
    identity: mockActiveIdentity,
  }));
  mockAssertAccountSessionBoundary.mockImplementation(
    (boundary: {identity: string}) => {
      if (boundary.identity !== mockActiveIdentity) {
        throw new Error('ACCOUNT_SESSION_CHANGED');
      }
    },
  );
});

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

const courseWithEntitlement = (
  level: 'pass_only' | 'enhanced',
): CourseLearningData => {
  const value = course(0);
  const upgraded = level === 'enhanced';
  value.chatAvailable = upgraded;
  value.chatAttachmentsEnabled = upgraded;
  value.chatAttachmentMaxFiles = upgraded ? 5 : 0;
  value.modules[0].projects = [
    {
      id: 'project-1',
      sectionId: 'project-section-1',
      sectionOrder: 4,
      moduleId: 'module-1',
      title: 'مشروع العبور',
      requirements: 'نفذ المشروع',
      status: 'draft',
      isGraduationProject: false,
      feedbackLevel: level,
      outputEnabled: upgraded,
      reportEnabled: upgraded,
      replyEnabled: upgraded,
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

it('refreshes the current course entitlement aggregate without blanking or moving it', async () => {
  const previous = courseWithEntitlement('pass_only');
  const refreshed = courseWithEntitlement('enhanced');
  mockLoadCourseLearningData.mockResolvedValue({course: refreshed});
  const requestInitialPosition = jest.fn();
  const setConnectionNote = jest.fn();
  const setCourse = jest.fn();
  const setLoading = jest.fn();
  const refs = {
    closedPlaybackSessions: {current: new Set<string>()},
    loadRequest: {current: 0},
    loadAbort: {current: null},
    loadedCourse: {current: previous as CourseLearningData | null},
    loadedCourseOwner: {current: 'user-1'},
    playbackDurations: {current: {}},
    playbackRuntime: {current: {}},
    positions: {current: {}},
  };
  let reload!: ReturnType<typeof useReelsCourseLoader>;
  const Harness = () => {
    reload = useReelsCourseLoader({
      navigation: {replace: jest.fn()},
      identityKey: 'user-1',
      params: {courseId: 'course-1'},
      previewMode: false,
      refs,
      requestInitialPosition,
      setConnectionNote,
      setCourse,
      setLoadError: jest.fn(),
      setLoading,
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
  setCourse.mockClear();
  setLoading.mockClear();
  requestInitialPosition.mockClear();
  refs.loadedCourse.current = previous;

  await act(async () => {
    await reload({index: 1});
  });

  expect(refs.loadedCourse.current).toBe(refreshed);
  expect(setCourse).toHaveBeenLastCalledWith(refreshed);
  expect(setCourse).not.toHaveBeenCalledWith(null);
  expect(setLoading).not.toHaveBeenCalledWith(true);
  expect(requestInitialPosition).toHaveBeenLastCalledWith({index: 1});
  expect(refs.loadedCourse.current?.chatAttachmentsEnabled).toBe(true);
  expect(refs.loadedCourse.current?.modules[0].projects?.[0]).toMatchObject({
    feedbackLevel: 'enhanced',
    reportEnabled: true,
    replyEnabled: true,
  });

  await act(async () => renderer.unmount());
});

it('keeps the current aggregate visible when its entitlement refresh fails', async () => {
  const current = courseWithEntitlement('pass_only');
  mockLoadCourseLearningData
    .mockResolvedValueOnce({course: current})
    .mockRejectedValueOnce(new Error('offline'));
  const setConnectionNote = jest.fn();
  const setCourse = jest.fn();
  const setLoading = jest.fn();
  const refs = {
    closedPlaybackSessions: {current: new Set<string>()},
    loadRequest: {current: 0},
    loadAbort: {current: null},
    loadedCourse: {current: current as CourseLearningData | null},
    loadedCourseOwner: {current: 'user-1'},
    playbackDurations: {current: {}},
    playbackRuntime: {current: {}},
    positions: {current: {}},
  };
  let reload!: ReturnType<typeof useReelsCourseLoader>;
  const Harness = () => {
    reload = useReelsCourseLoader({
      navigation: {replace: jest.fn()},
      identityKey: 'user-1',
      params: {courseId: 'course-1'},
      previewMode: false,
      refs,
      requestInitialPosition: jest.fn(),
      setConnectionNote,
      setCourse,
      setLoadError: jest.fn(),
      setLoading,
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
  setConnectionNote.mockClear();
  setCourse.mockClear();
  setLoading.mockClear();

  await act(async () => {
    await reload({index: 1});
  });

  expect(refs.loadedCourse.current).toBe(current);
  expect(setCourse).not.toHaveBeenCalled();
  expect(setLoading).not.toHaveBeenCalledWith(true);
  expect(setConnectionNote).toHaveBeenCalledWith(expect.any(String));

  await act(async () => renderer.unmount());
});

it('does not apply an entitlement response after the account changes', async () => {
  const current = courseWithEntitlement('pass_only');
  const refreshed = courseWithEntitlement('enhanced');
  let finishRefresh!: (value: {course: CourseLearningData}) => void;
  mockLoadCourseLearningData
    .mockResolvedValueOnce({course: current})
    .mockReturnValueOnce(
      new Promise(resolve => {
        finishRefresh = resolve;
      }),
    );
  const setCourse = jest.fn();
  const refs = {
    closedPlaybackSessions: {current: new Set<string>()},
    loadRequest: {current: 0},
    loadAbort: {current: null},
    loadedCourse: {current: current as CourseLearningData | null},
    loadedCourseOwner: {current: 'user-1'},
    playbackDurations: {current: {}},
    playbackRuntime: {current: {}},
    positions: {current: {}},
  };
  let reload!: ReturnType<typeof useReelsCourseLoader>;
  const Harness = () => {
    reload = useReelsCourseLoader({
      navigation: {replace: jest.fn()},
      identityKey: 'user-1',
      params: {courseId: 'course-1'},
      previewMode: false,
      refs,
      requestInitialPosition: jest.fn(),
      setConnectionNote: jest.fn(),
      setCourse,
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
  setCourse.mockClear();
  let pending!: Promise<void>;
  await act(async () => {
    pending = reload({index: 1});
    await Promise.resolve();
  });
  mockActiveIdentity = 'user-2';
  refs.loadedCourseOwner.current = 'user-2';
  await act(async () => {
    finishRefresh({course: refreshed});
    await pending;
  });

  expect(refs.loadedCourse.current).toBe(current);
  expect(setCourse).not.toHaveBeenCalled();

  await act(async () => renderer.unmount());
});
