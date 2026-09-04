import fs from 'node:fs';
import path from 'node:path';

const mockGet = jest.fn();
let mockSessionSnapshot: {
  ready: boolean;
  session: unknown;
  epoch: number;
};

jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
  digestStringAsync: jest.fn(async () => 'a'.repeat(64)),
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: (...args: unknown[]) => mockGet(...args)},
}));
jest.mock('../src/services/secureSession', () => {
  const actual = jest.requireActual('../src/services/secureSession');
  return {...actual, peekSecureSession: () => mockSessionSnapshot};
});

import {getLearningCourses} from '../src/services/api/courses';
import {getProfile} from '../src/services/api/profile';
import {
  learningResumeTarget,
  ownedWatchHistory,
} from '../src/screens/myCorner/model';
import type {LearningCourse, WatchHistoryItem} from '../src/services/roknApi';

const source = (relativePath: string) =>
  fs.readFileSync(path.resolve(__dirname, '..', relativePath), 'utf8');

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(resolvePromise => {
    resolve = resolvePromise;
  });
  return {promise, resolve};
};

const learningCoursesResponse = {
  data: {
    data: {
      items: [
        {
          course_id: 52,
          title: 'كورس ركن',
          image: null,
          progress_percentage: 20,
          completed_sections: 1,
          total_sections: 5,
          learning_started: true,
          access_type: 'paid',
          chat_available: true,
          certificate_available: false,
          resume: {
            available: true,
            lesson_id: 11,
            lesson_title: 'المقطع الأول',
            position_seconds: 8,
            watched_at: '2026-09-04T08:00:00Z',
          },
          next_section: {id: 71, title: 'مشروع العبور', type: 'project'},
          tags: [],
        },
      ],
      pagination: {has_more: false, next_cursor: null},
    },
  },
};

describe('signed-in daily journey', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockSessionSnapshot = {
      ready: true,
      session: {user: {id: 7}, api_token: 'token-seven'},
      epoch: 1,
    };
  });

  it('rejects an owned-course response that settles after an account switch', async () => {
    const request = deferred<typeof learningCoursesResponse>();
    mockGet.mockReturnValue(request.promise);

    const flight = getLearningCourses();
    await Promise.resolve();
    mockSessionSnapshot = {
      ready: true,
      session: {user: {id: 8}, api_token: 'token-eight'},
      epoch: 2,
    };
    request.resolve(learningCoursesResponse);

    await expect(flight).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
  });

  it('keeps the next learning section canonical instead of labelling every gate a lesson', async () => {
    mockGet.mockResolvedValue(learningCoursesResponse);

    await expect(getLearningCourses()).resolves.toEqual([
      expect.objectContaining({
        id: '52',
        started: true,
        resumePositionSeconds: 8,
        nextSectionId: '71',
        nextSectionTitle: 'مشروع العبور',
        nextSectionType: 'project',
      }),
    ]);
  });

  it('requires the server-owned learning-start decision', async () => {
    const invalid = JSON.parse(JSON.stringify(learningCoursesResponse));
    delete invalid.data.data.items[0].learning_started;
    mockGet.mockResolvedValue(invalid);

    await expect(getLearningCourses()).rejects.toThrow(
      'LEARNING_COURSES_CONTRACT_INVALID',
    );
  });

  it('loads every owned-course cursor page without hiding older courses', async () => {
    mockGet
      .mockResolvedValueOnce({
        data: {
          data: {
            ...learningCoursesResponse.data.data,
            pagination: {has_more: true, next_cursor: 'older-courses'},
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            items: [
              {
                ...learningCoursesResponse.data.data.items[0],
                course_id: 53,
                title: 'كورس أقدم',
              },
            ],
            pagination: {has_more: false, next_cursor: null},
          },
        },
      });

    await expect(getLearningCourses()).resolves.toEqual([
      expect.objectContaining({id: '52'}),
      expect.objectContaining({id: '53'}),
    ]);
    expect(mockGet).toHaveBeenNthCalledWith(
      2,
      'learning/courses',
      expect.objectContaining({
        params: {per_page: 100, cursor: 'older-courses'},
      }),
    );
  });

  it('rejects a profile response that settles after an account switch', async () => {
    const request = deferred<{
      data: {data: {id: number; name: string; email: string}};
    }>();
    mockGet.mockReturnValue(request.promise);

    const flight = getProfile();
    await Promise.resolve();
    mockSessionSnapshot = {
      ready: true,
      session: {user: {id: 8}, api_token: 'token-eight'},
      epoch: 2,
    };
    request.resolve({
      data: {data: {id: 7, name: 'الحساب الأول', email: 'one@example.com'}},
    });

    await expect(flight).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
  });

  it('reloads My Corner on account change and keeps resume separate from course details', () => {
    const myCorner = source('src/screens/MyCorner.tsx');
    const courseShelf = source('src/screens/myCorner/CourseShelf.tsx');
    const watchHistory = source('src/screens/myCorner/WatchHistorySection.tsx');
    const myCornerData = source('src/screens/myCorner/useMyCornerData.ts');

    expect(myCornerData).toContain('}, [appIsActive, identityKey]),');
    expect(myCornerData).toContain('ownerRef.current !== identityKey');
    expect(myCorner).toContain("navigation.navigate('CourseDetails'");
    expect(courseShelf).toContain('`عرض تفاصيل ${course.title}');
    expect(watchHistory).toContain('`استكمال ${item.lessonTitle}`');
    expect(myCorner).toContain('initialPositionSeconds: item.positionSeconds');
    expect(myCorner).toContain("navigation.navigate('Reels', target)");
    expect(myCorner).toContain(
      'data.learningOwnershipFresh && data.watchHistoryFresh',
    );
  });

  it('uses the canonical next section and only reuses position for that lesson', () => {
    const course: LearningCourse = {
      id: '52',
      title: 'كورس ركن',
      progress: 20,
      started: true,
      completedSections: 1,
      totalSections: 5,
      category: 'freelance',
      accessType: 'paid',
      chatAvailable: true,
      certificateAvailable: false,
      lastLessonId: '11',
      lastLessonTitle: 'المقطع الأول',
      resumePositionSeconds: 8,
      nextSectionId: '71',
      nextSectionTitle: 'مشروع العبور',
      nextSectionType: 'project',
    };

    expect(learningResumeTarget(course, true)).toEqual({
      courseId: '52',
      projectId: '71',
    });
    expect(
      learningResumeTarget(
        {...course, nextSectionId: '11', nextSectionType: 'lesson'},
        true,
      ),
    ).toEqual({
      courseId: '52',
      lessonId: '11',
      initialPositionSeconds: 8,
    });
    expect(
      learningResumeTarget(
        {...course, nextSectionId: '12', nextSectionType: 'lesson'},
        true,
      ),
    ).toEqual({
      courseId: '52',
      lessonId: '12',
      initialPositionSeconds: undefined,
    });
    expect(learningResumeTarget(course, false)).toBeNull();
    expect(learningResumeTarget({...course, started: false}, true)).toBeNull();
    expect(learningResumeTarget({...course, progress: 100}, true)).toBeNull();
  });

  it('never offers stale or no-longer-owned watch history as continue', () => {
    const courses: LearningCourse[] = [
      {
        id: '52',
        title: 'كورس ركن',
        progress: 20,
        started: true,
        completedSections: 1,
        totalSections: 5,
        category: 'freelance',
        accessType: 'paid',
        chatAvailable: true,
        certificateAvailable: false,
      },
    ];
    const item = (courseId: string): WatchHistoryItem => ({
      id: `history-${courseId}`,
      courseId,
      courseTitle: 'كورس',
      lessonId: '11',
      lessonTitle: 'مقطع',
      positionSeconds: 8,
      progress: 20,
      completed: false,
    });

    expect(ownedWatchHistory([item('52'), item('99')], courses, true)).toEqual([
      item('52'),
    ]);
    expect(ownedWatchHistory([item('52')], courses, false)).toEqual([]);
    expect(
      ownedWatchHistory([item('52')], [{...courses[0], started: false}], true),
    ).toEqual([]);
  });

  it('uses the session epoch for profile and certificate reads', () => {
    const profileApi = source('src/services/api/accountProfile.ts');
    const profileController = source(
      'src/screens/Profile/useProfileOverview.ts',
    );
    const certificateController = source(
      'src/screens/Profile/certificates/useCertificatesController.ts',
    );

    expect(profileApi).toMatch(
      /getProfile = async[\s\S]*captureAccountSessionBoundary\(\)[\s\S]*publicRequest\.get\('user\/profile'[\s\S]*assertAccountSessionBoundary\(boundary\)/,
    );
    expect(profileController).toContain(
      'const boundary = await captureAccountSessionBoundary();',
    );
    expect(profileController).not.toContain('getCurrentAccountStorageScope()');
    expect(certificateController).toContain(
      'const boundary = await captureAccountSessionBoundary();',
    );
    expect(certificateController).toContain(
      'assertAccountSessionBoundary(boundary);',
    );
  });
});
