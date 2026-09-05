jest.mock('react-native-fs', () => ({
  CachesDirectoryPath: '/cache',
  copyFile: jest.fn(),
  mkdir: jest.fn(),
  unlink: jest.fn(),
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: jest.fn(),
    post: jest.fn(),
  },
}));

jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(),
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  flushPendingPlaybackPositions,
  mapCoursePayload as mapCoursePayloadContract,
  retryPendingPlaybackPositions,
  savePlaybackPosition,
  WATCH_HISTORY_ENABLED_KEY,
} from '../src/components/VideoPlayer/courseLearningApi';
import {resetPlaybackRuntimeState} from '../src/components/VideoPlayer/courseLearning/playback';
import {publicRequest} from '../src/constants/api';
import {accountScopedStorageKey} from '../src/constants/helpers';
import {hasSession} from '../src/services/roknApi';
import {buildAccessibleFeed} from '../src/screens/reels/presentation';

type FixtureRecord = Record<string, any>;

const courseDetailsResponse = (fixture: FixtureRecord) => {
  const envelope = fixture.data || {};
  const {course, ...entitlement} = envelope;
  const value = {...entitlement, ...(course || {})};
  return {
    data: {
      ...value,
      modules: (value.modules || []).map((module: FixtureRecord) => ({
        ...module,
        sections: (module.sections || []).map((section: FixtureRecord) => ({
          ...section,
          content_id: section.content_id ?? section.content?.id,
          is_preview: section.is_preview ?? false,
          is_locked: section.is_locked ?? false,
          lock_reason: section.lock_reason ?? null,
        })),
      })),
    },
  };
};

const mapCoursePayload = (fixture: FixtureRecord) =>
  mapCoursePayloadContract(courseDetailsResponse(fixture));

const apiPost = publicRequest.post as jest.MockedFunction<
  typeof publicRequest.post
>;
const sessionAvailable = hasSession as jest.MockedFunction<typeof hasSession>;

describe('course progression boundaries', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    sessionAvailable.mockResolvedValue(true);
    apiPost.mockResolvedValue({} as any);
  });

  it('keeps locked lesson metadata when its media URL is withheld', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'course-1',
          title: 'Course',
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              order: 1,
              sections: [
                {
                  id: 'section-1',
                  type: 'lesson',
                  order: 1,
                  title: 'Available lesson',
                  content: {
                    id: 'lesson-1',
                    bunny_video_url: 'https://cdn.example/lesson-1.m3u8',
                  },
                },
                {
                  id: 'section-2',
                  type: 'lesson',
                  order: 2,
                  title: 'Locked lesson title',
                  is_locked: true,
                  content: {id: 'lesson-2'},
                },
                {
                  id: 'section-3',
                  type: 'lesson',
                  order: 3,
                  title: 'Later lesson',
                  content: {
                    id: 'lesson-3',
                    bunny_video_url: 'https://cdn.example/lesson-3.m3u8',
                  },
                },
                {
                  id: 'project-1',
                  type: 'project',
                  order: 4,
                  title: 'Crossing project',
                  content: {id: 'project-content-1'},
                },
              ],
            },
          ],
        },
      },
    });

    expect(course).not.toBeNull();
    expect(course?.totalReels).toBe(3);
    expect(course?.modules[0].reels).toHaveLength(3);
    expect(course?.modules[0].reels[1]).toMatchObject({
      title: 'Locked lesson title',
      videoUrl: '',
      isLocked: true,
      isPreview: false,
    });
    expect(course?.modules[0].reels[2]).toMatchObject({
      title: 'Later lesson',
      isLocked: false,
    });
    expect(course?.modules[0].projects?.[0]?.title).toBe('Crossing project');
  });

  it('keeps server completion and access independent across devices', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'cross-device-course',
          title: 'Course',
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              sections: [
                {
                  id: 'section-1',
                  type: 'lesson',
                  is_completed: true,
                  content: {
                    id: 'lesson-1',
                    bunny_video_url: 'https://cdn.example/1.m3u8',
                  },
                },
                {
                  id: 'section-2',
                  type: 'lesson',
                  is_completed: false,
                  is_locked: false,
                  content: {
                    id: 'lesson-2',
                    bunny_video_url: 'https://cdn.example/2.m3u8',
                  },
                },
              ],
            },
          ],
        },
      },
    });

    expect(course?.modules[0].reels[0].isCompleted).toBe(true);
    expect(course?.modules[0].reels[1].isCompleted).toBe(false);
    expect(buildAccessibleFeed(course!).map(item => item.key)).toEqual([
      'reel-lesson-1',
      'reel-lesson-2',
    ]);
  });

  it('shows progression projects but never turns a purchase boundary into one', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'course-project-boundaries',
          title: 'Course',
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              sections: [
                {
                  id: 'lesson-section',
                  type: 'lesson',
                  content: {
                    id: 'lesson-1',
                    bunny_video_url: 'https://cdn.example/lesson.m3u8',
                  },
                },
                {
                  id: 'progression-project',
                  content_id: 'project-1',
                  type: 'project',
                  title: 'مشروع العبور',
                  is_locked: true,
                  lock_reason: 'previous_section_incomplete',
                },
                {
                  id: 'purchase-project',
                  content_id: 'project-2',
                  type: 'project',
                  title: 'ليس مشروعًا متاحًا بعد',
                  is_locked: true,
                  lock_reason: 'course_purchase_required',
                },
              ],
            },
          ],
        },
      },
    });

    expect(course?.modules[0].projects).toEqual([
      expect.objectContaining({
        sectionId: 'progression-project',
        title: 'مشروع العبور',
        isLocked: true,
        lockReason: 'previous_section_incomplete',
      }),
    ]);
  });

  it('does not hide a first crossing project because every later reel is locked', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'project-first-course',
          title: 'Course',
          access_type: 'paid',
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              sections: [
                {
                  id: 'project-section',
                  type: 'project',
                  order: 1,
                  content: {id: 'project-1', title: 'مشروع العبور'},
                },
                {
                  id: 'lesson-section',
                  type: 'lesson',
                  order: 2,
                  is_locked: true,
                  lock_reason: 'module_project_not_passed',
                  content: {id: 'lesson-1'},
                },
              ],
            },
          ],
        },
      },
    });

    expect(course?.modules[0].isLocked).toBe(false);
    expect(buildAccessibleFeed(course!).map(item => item.key)).toEqual([
      'project-project-1',
    ]);
  });

  it('rejects the retired quiz shape from the course contract', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'course-with-quiz',
          title: 'Course',
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              sections: [
                {
                  id: 'lesson-section',
                  type: 'lesson',
                  is_completed: true,
                  content: {
                    id: 'lesson-1',
                    bunny_video_url: 'https://cdn.example/1.m3u8',
                  },
                },
                {
                  id: 'quiz-section',
                  type: 'quiz',
                  title: 'اختبار الوحدة',
                  content: {id: 'quiz-1', time_minutes: 5, is_passed: false},
                },
              ],
            },
          ],
        },
      },
    });

    expect(course).toBeNull();
  });

  it('rejects retired course and section aliases instead of guessing a map', () => {
    const canonicalCourse = {
      id: 'course-1',
      title: 'Course',
      modules: [
        {
          id: 'module-1',
          title: 'Module',
          sections: [
            {
              id: 'section-1',
              type: 'lesson',
              content_id: 'lesson-1',
              is_preview: false,
              is_locked: false,
              lock_reason: null,
              content: {id: 'lesson-1'},
            },
          ],
        },
      ],
    };

    expect(mapCoursePayloadContract(canonicalCourse)).toBeNull();
    expect(
      mapCoursePayloadContract({data: {course: canonicalCourse}}),
    ).toBeNull();
    expect(
      mapCoursePayloadContract({
        data: {
          ...canonicalCourse,
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              sections: [
                {
                  id: 'section-1',
                  type: 'video',
                  lesson_id: 'lesson-1',
                  isPreview: false,
                  isLocked: false,
                  lockReason: null,
                  sectionable: {id: 'lesson-1'},
                },
              ],
            },
          ],
        },
      }),
    ).toBeNull();
  });

  it('maps the dashboard attachment discovery contract without inventing files', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'course-attachments',
          title: 'Course',
          attachment_prompt: {
            enabled: true,
            at_seconds: 17,
            title: 'ملفات التطبيق',
            body: 'حمّل القالب قبل تنفيذ الخطوة.',
            button_text: 'افتح الملفات',
          },
          attachments: [
            {
              id: 'attachment-1',
              title: 'قالب العمل',
              download_url: 'https://api.example/signed/template.pdf',
              download_only: true,
              download_url_is_temporary: true,
              platform: 'computer',
            },
          ],
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              order: 1,
              attachments_link: 'https://cdn.example/legacy-public-link.zip',
              attachments: [
                {
                  id: 'module-only-attachment',
                  title: 'ملف وحدة قديم',
                  download_url: 'https://api.example/signed/legacy.pdf',
                  download_only: true,
                  download_url_is_temporary: true,
                  platform: 'mobile',
                },
                {
                  id: 'legacy-attachment',
                  title: 'رابط قديم',
                  url: 'https://cdn.example/legacy-template.pdf',
                },
              ],
              sections: [
                {
                  id: 'section-1',
                  type: 'lesson',
                  content: {
                    id: 'lesson-1',
                    bunny_video_url: 'https://cdn.example/lesson.m3u8',
                  },
                },
              ],
            },
          ],
        },
      },
    });

    expect(course?.attachmentPrompt).toEqual({
      enabled: true,
      atSeconds: 17,
      title: 'ملفات التطبيق',
      body: 'حمّل القالب قبل تنفيذ الخطوة.',
      buttonText: 'افتح الملفات',
      frequency: 'once_per_course',
    });
    expect(course?.attachments).toHaveLength(1);
    expect(course?.attachments[0].platform).toBe('computer');
    expect(course?.attachments[0].url).toBe(
      'https://api.example/signed/template.pdf',
    );
    expect(course?.attachments[0].temporary).toBe(true);
    expect(course?.attachments[0]).not.toHaveProperty('moduleId');
    expect(course?.attachments).not.toEqual(
      expect.arrayContaining([
        expect.objectContaining({id: 'module-only-attachment'}),
      ]),
    );
  });

  it('keeps reel titles and captions separate and allows theory modules without projects', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'theory-course',
          title: 'كورس نظري',
          modules: [
            {
              id: 'module-1',
              title: 'الوحدة الأولى',
              order: 1,
              sections: [
                {
                  id: 'section-1',
                  type: 'lesson',
                  order: 1,
                  title: 'العنوان الظاهر على الريل',
                  content: {
                    id: 'lesson-1',
                    title: 'عنوان التخزين الداخلي',
                    description: 'هذا هو الكابشن المستقل أسفل الفيديو',
                    bunny_video_url: 'https://cdn.example/lesson-1.m3u8',
                  },
                },
              ],
            },
            {
              id: 'module-2',
              title: 'الوحدة الثانية',
              order: 2,
              sections: [
                {
                  id: 'section-2',
                  type: 'lesson',
                  order: 1,
                  title: 'مقطع بلا مشروع قبله',
                  content: {
                    id: 'lesson-2',
                    bunny_video_url: 'https://cdn.example/lesson-2.m3u8',
                  },
                },
              ],
            },
          ],
        },
      },
    });

    expect(course?.modules[0].reels[0]).toMatchObject({
      title: 'العنوان الظاهر على الريل',
      caption: 'هذا هو الكابشن المستقل أسفل الفيديو',
    });
    expect(course?.modules[0].projects).toEqual([]);
    expect(course?.modules[1].projects).toEqual([]);
    expect(buildAccessibleFeed(course!)).toHaveLength(2);
  });

  it('maps the project delivery policy chosen in the course studio', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'project-policy-course',
          title: 'Course',
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              sections: [
                {
                  id: 'policy-lesson-section',
                  type: 'lesson',
                  content: {
                    id: 'policy-lesson-1',
                    bunny_video_url: 'https://cdn.example/policy-lesson.m3u8',
                  },
                },
                {
                  id: 'project-section',
                  type: 'project',
                  content: {
                    id: 'project-1',
                    submission_text_enabled: false,
                    submission_files_enabled: true,
                    submission_max_files: 2,
                    submission_allowed_mime_types: [
                      'image/jpeg',
                      'application/pdf',
                    ],
                  },
                },
              ],
            },
          ],
        },
      },
    });

    expect(course?.modules[0].projects?.[0]).toMatchObject({
      submissionTextEnabled: false,
      submissionFilesEnabled: true,
      submissionMaxFiles: 2,
      submissionAllowedMimeTypes: ['image/jpeg', 'application/pdf'],
    });
  });

  it('does not expose project AI output when the enrolled plan did not grant it', () => {
    const course = mapCoursePayload({
      data: {
        access_type: 'free',
        chat_available: false,
        course: {
          id: 'free-course',
          title: 'Free course',
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              sections: [
                {
                  id: 'lesson-section',
                  type: 'lesson',
                  content: {
                    id: 'lesson-1',
                    bunny_video_url: 'https://cdn.example/1.m3u8',
                  },
                },
                {
                  id: 'project-section',
                  type: 'project',
                  content: {
                    id: 'project-1',
                    project_feedback: {
                      level: 'pass_only',
                      report_enabled: false,
                      output_enabled: false,
                    },
                    feedback_thread: {
                      id: 'thread-that-must-not-leak',
                      feedback_level: 'report',
                      can_reply: true,
                      messages: [],
                    },
                  },
                },
              ],
            },
          ],
        },
      },
    });

    expect(course?.chatAvailable).toBe(false);
    expect(course?.modules[0].projects?.[0]).toMatchObject({
      feedbackLevel: 'pass_only',
      outputEnabled: false,
      reportEnabled: false,
      feedbackThread: undefined,
    });
  });

  it('uses the canonical submission when a legacy evaluation disagrees', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'canonical-project-course',
          title: 'Course',
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              sections: [
                {
                  id: 'lesson-section',
                  type: 'lesson',
                  content: {
                    id: 'lesson-1',
                    bunny_video_url: 'https://cdn.example/lesson.m3u8',
                  },
                },
                {
                  id: 'project-section',
                  type: 'project',
                  content: {
                    id: 'project-1',
                    status: 'passed',
                    user_evaluation: {status: 'passed', passed: true},
                    latest_submission: {
                      id: 'submission-1',
                      status: 'pending',
                      submission_status: 'evaluating',
                      passed: false,
                      can_submit: false,
                      can_continue: false,
                      report_status: 'not_included',
                    },
                  },
                },
              ],
            },
          ],
        },
      },
    });

    expect(course?.modules[0].projects?.[0]?.status).toBe('evaluating');
  });

  it('maps the server retry decision and durable files for project feedback', () => {
    const course = mapCoursePayload({
      data: {
        course: {
          id: 'feedback-retry-course',
          title: 'Course',
          modules: [
            {
              id: 'module-1',
              title: 'Module',
              sections: [
                {
                  id: 'feedback-retry-lesson-section',
                  type: 'lesson',
                  content: {
                    id: 'feedback-retry-lesson',
                    bunny_video_url:
                      'https://cdn.example/feedback-retry.m3u8',
                  },
                },
                {
                  id: 'project-section',
                  type: 'project',
                  content: {
                    id: 'project-1',
                    latest_submission: {
                      id: 'submission-1',
                      submission_status: 'passed',
                      can_submit: false,
                      can_continue: true,
                      feedback_level: 'enhanced',
                      report_enabled: true,
                      report_status: 'ready',
                      reply_enabled: true,
                      feedback_thread: {
                        id: 'thread-1',
                        feedback_level: 'enhanced',
                        can_reply: true,
                        remaining_messages: 3,
                        messages: [
                          {
                            id: 'message-1',
                            role: 'user',
                            status: 'failed',
                            error_code: 'provider_unavailable',
                            can_retry: true,
                            text: 'راجع المرفق',
                            attachments: [
                              {
                                id: 'attachment-1',
                                name: 'project.pdf',
                                mime_type: 'application/pdf',
                                size_bytes: 1200,
                              },
                            ],
                          },
                        ],
                      },
                    },
                  },
                },
              ],
            },
          ],
        },
      },
    });

    const mappedProject = course?.modules[0].projects?.[0];
    expect(mappedProject).toBeDefined();
    expect(mappedProject?.feedbackThread?.messages[0]).toMatchObject({
      errorCode: 'provider_unavailable',
      canRetry: true,
      attachments: [
        expect.objectContaining({
          serverId: 'attachment-1',
          uri: '',
        }),
      ],
    });
  });

  it('rejects an accepted submission whose canonical decision is absent', () => {
    expect(() =>
      mapCoursePayload({
        data: {
          course: {
            id: 'pending-project-course',
            title: 'Course',
            modules: [
              {
                id: 'module-1',
                title: 'Module',
                sections: [
                  {
                    id: 'lesson-section',
                    type: 'lesson',
                    content: {
                      id: 'lesson-1',
                      bunny_video_url: 'https://cdn.example/lesson.m3u8',
                    },
                  },
                  {
                    id: 'project-section',
                    type: 'project',
                    content: {
                      id: 'project-1',
                      latest_submission: {id: 'submission-1'},
                    },
                  },
                ],
              },
            ],
          },
        },
      }),
    ).toThrow('PROJECT_SUBMISSION_CONTRACT_INVALID');
  });

  it('keeps resume local and batches remote watch-history samples', async () => {
    await savePlaybackPosition('course-1', 'reel-1', 15, '101', 120);
    await savePlaybackPosition('course-1', 'reel-1', 25, '101', 120);

    expect(apiPost).toHaveBeenCalledTimes(1);
    await flushPendingPlaybackPositions();
    expect(apiPost).toHaveBeenCalledTimes(2);
    expect(apiPost).toHaveBeenLastCalledWith('user/watch-history', {
      lesson_id: 101,
      position_seconds: 25,
      duration_seconds: 120,
      is_completed: false,
      event_type: 'heartbeat',
    });
  });

  it('keeps required learning evidence flowing when optional history is off', async () => {
    await AsyncStorage.setItem(
      await accountScopedStorageKey(WATCH_HISTORY_ENABLED_KEY),
      JSON.stringify(false),
    );

    await savePlaybackPosition('course-2', 'reel-2', 20, '202', 90);
    await flushPendingPlaybackPositions();

    expect(apiPost).toHaveBeenCalledWith('user/watch-history', {
      lesson_id: 202,
      position_seconds: 20,
      duration_seconds: 90,
      is_completed: false,
      event_type: 'heartbeat',
    });
  });

  it('durably retries the latest evidence after a network failure', async () => {
    apiPost.mockRejectedValueOnce(new Error('offline'));
    await savePlaybackPosition('course-3', 'reel-3', 12, '303', 60);

    expect(
      (await AsyncStorage.getAllKeys()).some(key =>
        key.startsWith('@rokn/watch-evidence/v1:'),
      ),
    ).toBe(true);

    apiPost.mockResolvedValue({} as any);
    await retryPendingPlaybackPositions();

    expect(apiPost).toHaveBeenLastCalledWith('user/watch-history', {
      lesson_id: 303,
      position_seconds: 12,
      duration_seconds: 60,
      is_completed: false,
      event_type: 'heartbeat',
    });
    expect(
      (await AsyncStorage.getAllKeys()).some(key =>
        key.startsWith('@rokn/watch-evidence/v1:'),
      ),
    ).toBe(false);
  });

  it('restores the durable session sequence before sending newer evidence', async () => {
    apiPost.mockRejectedValueOnce(new Error('offline'));
    await savePlaybackPosition('course-4', 'reel-4', 12, '404', 60, false, {
      playbackSessionId: 'session-404',
    });

    resetPlaybackRuntimeState();
    apiPost.mockResolvedValue({} as any);
    await retryPendingPlaybackPositions();
    expect(apiPost).toHaveBeenLastCalledWith(
      'user/watch-history',
      expect.objectContaining({
        playback_session_id: 'session-404',
        position_seconds: 12,
        sequence: 1,
      }),
    );

    await savePlaybackPosition('course-4', 'reel-4', 24, '404', 60, false, {
      playbackSessionId: 'session-404',
    });
    await flushPendingPlaybackPositions();
    expect(apiPost).toHaveBeenLastCalledWith(
      'user/watch-history',
      expect.objectContaining({
        playback_session_id: 'session-404',
        position_seconds: 24,
        sequence: 2,
      }),
    );
  });
});
