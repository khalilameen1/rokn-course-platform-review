import React, {useRef} from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockLoadCourse = jest.fn();
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.fn(async course => course),
  getLocalLearningState: jest.fn(async () => ({
    positions: {},
    savedLessons: [],
  })),
  loadCourseLearningData: (...args: unknown[]) => mockLoadCourse(...args),
  reconcileServerSavedLessons: jest.fn(async () => []),
  subscribeCourseRevisionChanges: jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/playbackRevision',
  ).subscribeCourseRevisionChanges,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    scope: 'user-1',
    epoch: 1,
  })),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(async () => true),
}));

import type {
  CourseLearningData,
  CourseReel,
} from '../src/components/VideoPlayer/types';
import {publishCourseRevisionChange} from '../src/components/VideoPlayer/courseLearning/playbackRevision';
import {useReelsCourseLoader} from '../src/screens/reels/useReelsCourseLoader';
import {useReelsCourseRevision} from '../src/screens/reels/useReelsCourseRevision';

const course = (projectId: string, reelCount: number): CourseLearningData => ({
  id: '7',
  title: 'الكورس',
  totalReels: reelCount,
  accessType: 'paid',
  attachments: [],
  modules: [
    {
      id: '8',
      title: 'الوحدة',
      order: 1,
      isLocked: false,
      reels: Array.from({length: reelCount}, (_, index) => ({
        id: `r${index + 1}`,
        lessonId: `l${index + 1}`,
        sectionId: `s${index + 1}`,
        moduleId: '8',
        sectionOrder: index + 1,
        title: 'المقطع',
        caption: '',
        videoUrl: 'https://cdn.example/lesson.m3u8',
        availableQualities: ['auto'],
        isPreview: false,
        isLocked: false,
        isCompleted: true,
        reelNumber: index + 1,
      })),
      projects: [
        {
          id: projectId,
          sectionId: `${projectId}0`,
          moduleId: '8',
          sectionOrder: reelCount + 1,
          title: 'المشروع',
          requirements: 'المطلوب الحالي',
          status: 'draft',
          isGraduationProject: false,
        },
      ],
    },
  ],
});

it.each([
  {viewingProject: true, sourceId: 11, expected: {key: 'project-22'}},
  {viewingProject: false, sourceId: 11, expected: {key: 'reel-r1'}},
  {viewingProject: true, sourceId: 99, expected: {index: 1}},
  {viewingProject: true, sourceId: 11, latestProjectId: '33', expected: null},
  {viewingProject: true, sourceId: 11, latestProjectId: '', expected: null},
  {viewingProject: true, sourceId: 11, accessRevoked: true, expected: null},
  {viewingProject: true, sourceId: 11, priorGate: true, expected: {index: 1}},
])(
  'anchors only the currently viewed replacement project ($viewingProject / $sourceId)',
  async ({
    viewingProject,
    sourceId,
    expected,
    latestProjectId,
    accessRevoked,
    priorGate,
  }) => {
    const previous = course('11', 1);
    const current = course(latestProjectId ?? '22', 3);
    if (latestProjectId === '') current.modules[0].projects = [];
    if (accessRevoked) current.accessType = 'none';
    if (priorGate) {
      const target = current.modules[0].projects?.[0];
      if (!target) throw new Error('Missing test project');
      current.modules[0].projects = [
        {...target, id: '21', sectionId: '210', sectionOrder: 0},
        target,
      ];
    }
    mockLoadCourse
      .mockReset()
      .mockResolvedValueOnce({course: previous})
      .mockResolvedValueOnce({course: current});
    const requestPosition = jest.fn();
    const setCourse = jest.fn();
    const setRefreshing = jest.fn();
    const setConnectionNote = jest.fn();
    const replace = jest.fn();
    const Harness = () => {
      const loadedCourse = useRef<CourseLearningData | null>(previous);
      const mounted = useRef(true);
      const currentIndex = useRef(viewingProject ? 1 : 0);
      const activeReel = useRef<CourseReel | undefined>(
        viewingProject ? undefined : previous.modules[0].reels[0],
      );
      const closedPlaybackSessions = useRef(new Set<string>());
      const load = useReelsCourseLoader({
        navigation: {replace},
        identityKey: 'user-1',
        params: {courseId: '7'},
        previewMode: false,
        refs: {
          closedPlaybackSessions,
          loadedCourse,
          loadRequest: useRef(0),
          loadAbort: useRef(null),
          loadedCourseOwner: useRef('user-1'),
          playbackDurations: useRef({}),
          playbackRuntime: useRef({}),
          positions: useRef({}),
        },
        requestInitialPosition: requestPosition,
        setConnectionNote,
        setCourse,
        setLoadError: jest.fn(),
        setLoading: jest.fn(),
        setPreviewGateVisible: jest.fn(),
        setSavedLessons: jest.fn(),
        setServerSession: jest.fn(),
      });
      useReelsCourseRevision({
        activeReel,
        closedSessions: closedPlaybackSessions,
        currentIndex,
        invalidateManifests: jest.fn(),
        load,
        loadedCourse,
        mounted,
        pending: useRef(false),
        reloadFlight: useRef(null),
        setConnectionNote,
        setRefreshing,
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    requestPosition.mockClear();
    setCourse.mockClear();
    try {
      await act(async () => {
        publishCourseRevisionChange({
          response: {
            data: {
              code: 'course_revision_changed',
              data: {
                course_id: 7,
                source_project_id: sourceId,
                current_project_id: 22,
                current_section_id: 220,
              },
            },
          },
        });
      });
      expect(mockLoadCourse).toHaveBeenCalledTimes(2);
      if (expected) {
        expect(requestPosition).toHaveBeenLastCalledWith(expected);
        expect(setCourse).toHaveBeenLastCalledWith(current);
      } else {
        expect(requestPosition).not.toHaveBeenCalled();
        expect(setCourse).not.toHaveBeenCalled();
        if (accessRevoked) {
          expect(replace).toHaveBeenCalledWith('CourseDetails', {
            courseId: '7',
          });
        } else {
          expect(replace).not.toHaveBeenCalled();
          expect(setRefreshing).toHaveBeenLastCalledWith(false);
          expect(setConnectionNote).toHaveBeenLastCalledWith(
            'تغيّر المشروع مرة أخرى\nراجع المشروع المحدّث',
          );
        }
      }
    } finally {
      await act(async () => renderer.unmount());
    }
  },
);
