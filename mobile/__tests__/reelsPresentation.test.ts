import {
  buildAccessibleFeed,
  buildPreviewFeed,
  PLAYBACK_PREFERENCE_BITRATE_KBPS,
  resolveReelsFeedAnchor,
  resolveReelsFrameWidth,
  updateProjectStatusOnly,
} from '../src/screens/reels/presentation';
import {
  buildPlaybackEvidence,
  markReelCompleted,
  nextLearningTitle,
  reelCompletionNeedsLearningMapRefresh,
} from '../src/screens/reels/progress';
import type {
  CourseLearningData,
  VideoQuality,
} from '../src/components/VideoPlayer/types';
import {
  parseProjectReportStatus,
  parseProjectSubmissionStatus,
} from '../src/components/VideoPlayer/courseLearning/projects';
import {courseLearningProgress} from '../src/components/VideoPlayer/courseLearning/sequence';

describe('reels presentation policy', () => {
  it('accepts only the canonical project submission contract', () => {
    expect(parseProjectSubmissionStatus('draft')).toBe('draft');
    expect(parseProjectSubmissionStatus('evaluating', true)).toBe('evaluating');
    expect(parseProjectSubmissionStatus('needs_changes', true)).toBe(
      'needs_changes',
    );
    expect(parseProjectSubmissionStatus('passed', true)).toBe('passed');
    expect(parseProjectSubmissionStatus('', false)).toBe('draft');
    expect(() => parseProjectSubmissionStatus('reviewing', true)).toThrow(
      'PROJECT_SUBMISSION_CONTRACT_INVALID',
    );
    expect(parseProjectReportStatus('queued')).toBe('queued');
    expect(parseProjectReportStatus('ready')).toBe('ready');
    expect(parseProjectReportStatus('failed')).toBe('failed');
    expect(() => parseProjectReportStatus('processing')).toThrow(
      'PROJECT_REPORT_CONTRACT_INVALID',
    );
  });
  it('keeps entitled source-less reels reachable so their manifest can load', () => {
    const course = fixture();
    course.modules[0].reels[1].videoUrl = '';

    expect(buildAccessibleFeed(course).map(item => item.key)).toEqual([
      'reel-reel-1',
      'reel-reel-2',
      'project-project-1',
    ]);

    course.modules[0].reels[1].videoUrl = 'https://cdn.example/2.m3u8';
    expect(buildAccessibleFeed(course).map(item => item.key)).toEqual([
      'reel-reel-1',
      'reel-reel-2',
      'project-project-1',
    ]);
  });

  it('never lets old local completion bypass the current published gate', () => {
    const course = fixture();
    const gatedReel = course.modules[0].reels[1];
    gatedReel.isCompleted = true;
    gatedReel.isLocked = true;
    gatedReel.lockReason = 'module_project_not_passed';

    expect(buildAccessibleFeed(course).map(item => item.key)).toEqual([
      'reel-reel-1',
    ]);

    gatedReel.lockReason = 'course_purchase_required';
    expect(buildAccessibleFeed(course).map(item => item.key)).toEqual([
      'reel-reel-1',
    ]);
  });

  it('uses only previews marked by the canonical course graph', () => {
    const course = fixture();
    course.modules[0].reels[1].isPreview = true;

    const feed = buildPreviewFeed(course);

    expect(feed.map(item => item.key)).toEqual(['reel-reel-2']);
    expect(feed[0].type === 'reel' && feed[0].reel.isLocked).toBe(false);

    course.modules[0].reels[1].isPreview = false;
    expect(buildPreviewFeed(course)).toEqual([]);
  });

  it('resolves reel lesson and project route identities without conflating them', () => {
    const course = fixture();
    course.modules[0].reels[0].lessonId = 'lesson-91';
    const feed = buildAccessibleFeed(course);

    expect(resolveReelsFeedAnchor(feed, {reelId: 'reel-1'})).toMatchObject({
      index: 0,
      item: {key: 'reel-reel-1'},
    });
    expect(resolveReelsFeedAnchor(feed, {lessonId: 'lesson-91'})).toMatchObject(
      {
        index: 0,
        item: {key: 'reel-reel-1'},
      },
    );
    expect(
      resolveReelsFeedAnchor(feed, {
        reelId: 'stale-reel-id',
        lessonId: 'lesson-91',
      }),
    ).toMatchObject({index: 0, item: {key: 'reel-reel-1'}});
    expect(
      resolveReelsFeedAnchor(feed, {projectId: 'project-1'}),
    ).toMatchObject({item: {key: 'project-project-1'}});
    expect(resolveReelsFeedAnchor(feed, {lessonId: 'reel-1'})).toBeNull();
  });

  it('updates only the reviewed project status', () => {
    const course = fixture();
    const next = updateProjectStatusOnly(course, 'project-1', 'evaluating');

    expect(next.modules[0].projects?.[0]?.status).toBe('evaluating');
    expect(next.modules[1]).toEqual(course.modules[1]);
    expect(course.modules[0].projects?.[0]?.status).toBe('draft');
  });

  it('keeps phone width and caps wide layouts by video aspect', () => {
    expect(resolveReelsFrameWidth({width: 390, height: 844})).toBe(390);
    expect(resolveReelsFrameWidth({width: 640, height: 360})).toBe(225);
    expect(resolveReelsFrameWidth({width: 844, height: 390})).toBe(244);
    expect(resolveReelsFrameWidth({width: 1024, height: 800})).toBe(500);
    expect(resolveReelsFrameWidth({width: 0, height: 800})).toBe(0);
    expect(PLAYBACK_PREFERENCE_BITRATE_KBPS['360p']).toBe(750);
  });

  it('keeps playback evidence mapping identical across progress paths', () => {
    expect(
      buildPlaybackEvidence(
        {playbackSessionId: 'session-1'},
        {
          effectiveQuality: '720p',
          effectiveBitrateKbps: 2800,
          recoveryCount: 2,
          bufferCount: 3,
          bufferDurationMs: 1200,
          startupLatencyMs: 450,
          diagnostics: {stage: 'playing'},
        },
        1.25,
      ),
    ).toEqual({
      playbackSessionId: 'session-1',
      effectiveQuality: '720p',
      effectiveBitrateKbps: 2800,
      playbackRate: 1.25,
      recoveryCount: 2,
      bufferCount: 3,
      bufferDurationMs: 1200,
      startupLatencyMs: 450,
      diagnostics: {stage: 'playing'},
    });
  });

  it('marks the reel complete and unlocks only its immediate successor', () => {
    const course = fixture();
    course.modules[0].reels[0].isCompleted = false;
    course.modules[0].reels[1].isLocked = true;
    course.modules[0].reels[1].lockReason = 'previous_section_incomplete';

    const next = markReelCompleted(course, course.modules[0].reels[0]);

    expect(next.modules[0].reels[0].isCompleted).toBe(true);
    expect(next.modules[0].reels[1].isLocked).toBe(false);
    expect(course.modules[0].reels[0].isCompleted).toBe(false);
  });

  it('places a crossing project before its lesson and waits for its refreshed contract', () => {
    const course = fixture();
    const first = course.modules[0].reels[0];
    const second = course.modules[0].reels[1];
    const project = course.modules[0].projects![0];
    first.sectionOrder = 1;
    project.sectionOrder = 2;
    project.isLocked = true;
    project.lockReason = 'previous_section_incomplete';
    second.sectionOrder = 3;
    second.isLocked = true;

    expect(buildAccessibleFeed(course).map(item => item.key)).toEqual([
      'reel-reel-1',
    ]);
    expect(nextLearningTitle(course, first)).toBe(project.title);

    const afterReel = markReelCompleted(course, first, false);
    expect(afterReel.modules[0].projects?.[0]?.isLocked).toBe(true);
    expect(afterReel.modules[0].reels[1].isLocked).toBe(true);
    expect(buildAccessibleFeed(afterReel).map(item => item.key)).toEqual([
      'reel-reel-1',
    ]);
    expect(reelCompletionNeedsLearningMapRefresh(course, first)).toBe(true);
    const acknowledgedBeforeRefresh = markReelCompleted(course, first, false);
    expect(acknowledgedBeforeRefresh.modules[0].reels[0].isCompleted).toBe(
      true,
    );
    expect(acknowledgedBeforeRefresh.modules[0].projects?.[0]?.isLocked).toBe(
      true,
    );

    const refreshed = fixture();
    refreshed.modules[0].reels[0].sectionOrder = 1;
    refreshed.modules[0].projects![0].sectionOrder = 2;
    refreshed.modules[0].reels[1].sectionOrder = 3;
    refreshed.modules[0].projects![0].status = 'passed';
    refreshed.modules[0].projects![0].isLocked = false;
    refreshed.modules[0].reels[1].isLocked = false;
    expect(buildAccessibleFeed(refreshed).map(item => item.key)).toEqual([
      'reel-reel-1',
      'project-project-1',
      'reel-reel-2',
    ]);
  });

  it('refreshes only at a private project boundary', () => {
    const course = fixture();
    expect(
      reelCompletionNeedsLearningMapRefresh(course, course.modules[0].reels[0]),
    ).toBe(false);
    expect(
      reelCompletionNeedsLearningMapRefresh(course, course.modules[0].reels[1]),
    ).toBe(true);

    course.modules[0].projects = [];
    expect(
      reelCompletionNeedsLearningMapRefresh(course, course.modules[0].reels[1]),
    ).toBe(false);
  });

  it('does not fabricate a following-module project before refresh', () => {
    const course = fixture();
    course.modules[0].projects = [];
    course.modules[1].projects = [
      {
        id: 'project-2',
        sectionId: 'project-section-2',
        moduleId: 'module-2',
        title: 'Project 2',
        requirements: '',
        status: 'draft',
        sectionOrder: 1,
        isLocked: true,
        isGraduationProject: false,
      },
    ];
    course.modules[1].reels[0].sectionOrder = 2;
    course.modules[1].reels[0].isLocked = true;

    const next = markReelCompleted(course, course.modules[0].reels[1], false);

    expect(next.modules[1].isLocked).toBe(true);
    expect(next.modules[1].projects?.[0]?.isLocked).toBe(true);
    expect(next.modules[1].reels[0].isLocked).toBe(true);
  });

  it('does not report complete progress while a project gate is unfinished', () => {
    const course = fixture();

    expect(courseLearningProgress(course.modules)).toEqual({
      completed: 2,
      total: 4,
    });

    course.modules[0].projects![0].status = 'passed';
    expect(courseLearningProgress(course.modules)).toEqual({
      completed: 3,
      total: 4,
    });
  });

  it('keeps non-progression locks server-owned after reel completion', () => {
    const course = fixture();
    course.modules[0].reels[1].isLocked = true;
    course.modules[0].reels[1].lockReason = 'media_not_ready';

    const next = markReelCompleted(course, course.modules[0].reels[0]);

    expect(next.modules[0].reels[0].isCompleted).toBe(true);
    expect(next.modules[0].reels[1].isLocked).toBe(true);
    expect(next.modules[0].reels[1].lockReason).toBe('media_not_ready');
  });
});

const fixture = (): CourseLearningData => ({
  id: 'course-1',
  title: 'Course',
  totalReels: 3,
  attachments: [],
  modules: [
    {
      id: 'module-1',
      title: 'Module 1',
      order: 1,
      isLocked: false,
      reels: [
        reel('reel-1', 'module-1', true),
        reel('reel-2', 'module-1', true),
      ],
      projects: [
        {
          id: 'project-1',
          sectionId: 'project-section-1',
          moduleId: 'module-1',
          title: 'Project',
          requirements: 'Ship it',
          status: 'draft',
          isGraduationProject: false,
        },
      ],
    },
    {
      id: 'module-2',
      title: 'Module 2',
      order: 2,
      isLocked: true,
      reels: [reel('reel-3', 'module-2', false)],
    },
  ],
});

const reel = (id: string, moduleId: string, isCompleted: boolean) => ({
  id,
  lessonId: id,
  sectionId: `section-${id}`,
  moduleId,
  title: id,
  caption: '',
  videoUrl: `https://cdn.example/${id}.m3u8`,
  availableQualities: ['auto'] as VideoQuality[],
  isPreview: false,
  isLocked: false,
  isCompleted,
  reelNumber: Number(id.slice(-1)),
});
