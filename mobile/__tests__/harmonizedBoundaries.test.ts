import {
  isApiRecord,
  isResourceListPayload,
  payload,
  resourceList,
  responseEnvelope,
} from '../src/services/api/common';
import {courseChatErrorCode} from '../src/components/VideoPlayer/courseChat/policy';
import {nativeVideoErrorCode} from '../src/components/VideoPlayer/video/eventHandlers';
import {
  applyPlaybackManifest,
  disableLessonPlayback,
  playbackFeatureErrorCode,
} from '../src/screens/reels/playbackCourseState';
import type {CourseLearningData} from '../src/components/VideoPlayer/types';

jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));

describe('API data boundaries', () => {
  it('unwraps nested payloads and resource collections without assuming shape', () => {
    expect(payload({data: {data: {id: 7}}})).toEqual({id: 7});
    expect(payload({data: {data: [{id: 7}]}})).toEqual([{id: 7}]);
    expect(payload({data: {data: null}})).toBeNull();
    expect(payload({data: {data: 0}})).toBe(0);
    expect(payload({data: [{id: 7}]})).toEqual([{id: 7}]);
    expect(responseEnvelope({data: {data: [], pagination: {total: 4}}}))
      .toMatchObject({pagination: {total: 4}});
    expect(responseEnvelope({data: '<html>unavailable</html>'})).toEqual({});
    expect(resourceList({data: [{id: 1}]})).toEqual([{id: 1}]);
    expect(resourceList('invalid')).toEqual([]);
    expect(isResourceListPayload({data: []})).toBe(true);
    expect(isResourceListPayload('<html>unavailable</html>')).toBe(false);
    expect(isApiRecord([])).toBe(false);
    expect(isApiRecord({id: 1})).toBe(true);
  });
});

describe('native boundary error codes', () => {
  it('normalizes direct and nested failures without leaking arbitrary payloads', () => {
    expect(courseChatErrorCode({data: {code: 'insufficient_coins'}})).toBe(
      'insufficient_coins',
    );
    expect(
      courseChatErrorCode({response: {data: {code: 'course_access_required'}}}),
    ).toBe('course_access_required');
    expect(nativeVideoErrorCode({error: {errorCode: 'decoder_failed'}})).toBe(
      'decoder_failed',
    );
    expect(nativeVideoErrorCode({code: 'network'})).toBe('network');
    expect(playbackFeatureErrorCode({code: 'FEATURE_PLAYBACK_DISABLED'})).toBe(
      'FEATURE_PLAYBACK_DISABLED',
    );
  });
});

describe('reels playback course state', () => {
  it('applies a manifest only to the expected lesson and session', () => {
    const course = courseFixture();
    const next = applyPlaybackManifest(course, {
      courseId: course.id,
      lessonId: 'lesson-1',
      expectedSessionId: 'session-old',
      manifest: {
        playbackSessionId: 'session-new',
        sourceUrl: 'https://cdn.example/new.m3u8',
        fallbackUrl: 'https://cdn.example/fallback.mp4',
        protocol: 'hls',
        availableQualities: ['auto', '720p'],
        qualitySources: {'720p': 'https://cdn.example/720.mp4'},
        mediaStatus: 'ready',
      },
      revision: 42,
    });

    expect(next).not.toBe(course);
    expect(next?.modules[0].reels[0]).toMatchObject({
      playbackSessionId: 'session-new',
      playbackManifestRevision: 42,
      videoUrl: 'https://cdn.example/new.m3u8',
    });
    expect(next?.modules[0].reels[1]).toBe(course.modules[0].reels[1]);

    const stale = applyPlaybackManifest(course, {
      courseId: course.id,
      lessonId: 'lesson-1',
      expectedSessionId: 'stale-session',
      manifest: {
        playbackSessionId: 'ignored',
        sourceUrl: 'https://cdn.example/ignored.m3u8',
        protocol: 'hls',
        availableQualities: ['auto'],
        qualitySources: {},
        mediaStatus: 'ready',
      },
      revision: 43,
    });
    expect(stale).toBe(course);
  });

  it('disables only the failed lesson while retaining course metadata', () => {
    const course = courseFixture();
    const next = disableLessonPlayback(
      course,
      course.id,
      'lesson-1',
      'course_purchase_required',
    );

    expect(next?.title).toBe(course.title);
    expect(next?.modules[0].reels[0]).toMatchObject({
      videoUrl: '',
      mediaStatus: 'failed',
      isLocked: true,
      lockReason: 'course_purchase_required',
    });
    expect(next?.modules[0].reels[0].playbackSessionId).toBeUndefined();
    expect(next?.modules[0].reels[1]).toBe(course.modules[0].reels[1]);
  });
});

const courseFixture = (): CourseLearningData => ({
  id: 'course-1',
  title: 'Course',
  totalReels: 2,
  attachments: [],
  modules: [
    {
      id: 'module-1',
      title: 'Module',
      order: 1,
      isLocked: false,
      reels: [
        {
          id: 'reel-1',
          lessonId: 'lesson-1',
          sectionId: 'section-1',
          moduleId: 'module-1',
          title: 'Lesson 1',
          caption: '',
          videoUrl: 'https://cdn.example/old.m3u8',
          availableQualities: ['auto'],
          playbackSessionId: 'session-old',
          isPreview: false,
          isLocked: false,
          isCompleted: false,
          reelNumber: 1,
        },
        {
          id: 'reel-2',
          lessonId: 'lesson-2',
          sectionId: 'section-2',
          moduleId: 'module-1',
          title: 'Lesson 2',
          caption: '',
          videoUrl: 'https://cdn.example/2.m3u8',
          availableQualities: ['auto'],
          isPreview: false,
          isLocked: false,
          isCompleted: false,
          reelNumber: 2,
        },
      ],
    },
  ],
});
