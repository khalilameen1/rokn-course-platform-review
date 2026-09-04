import type {PlaybackEvidenceContext} from '../../components/VideoPlayer/courseLearningApi';
import type {PlaybackRuntimeMetrics} from '../../components/VideoPlayer/playbackTelemetry';
import type {
  CourseLearningData,
  CourseReel,
} from '../../components/VideoPlayer/types';
import {courseStepAfterReel} from '../../components/VideoPlayer/courseLearning/sequence';
import {advanceAfterReel} from '../../components/VideoPlayer/courseLearning/projectProgression';

/**
 * Locked projects intentionally arrive without their private
 * content. Once the preceding reel is accepted, refresh that one boundary
 * before opening it instead of displaying a locally-unlocked empty shell.
 */
export const reelCompletionNeedsLearningMapRefresh = (
  course: CourseLearningData,
  reel: CourseReel,
): boolean => {
  const next = courseStepAfterReel(course, reel)?.step;
  return next?.type === 'project';
};

export const buildPlaybackEvidence = (
  reel: Pick<CourseReel, 'playbackSessionId'>,
  runtime: PlaybackRuntimeMetrics | undefined,
  playbackRate: number,
): PlaybackEvidenceContext => ({
  playbackSessionId: reel.playbackSessionId,
  effectiveQuality: runtime?.effectiveQuality,
  effectiveBitrateKbps: runtime?.effectiveBitrateKbps,
  playbackRate,
  recoveryCount: runtime?.recoveryCount,
  bufferCount: runtime?.bufferCount,
  bufferDurationMs: runtime?.bufferDurationMs,
  startupLatencyMs: runtime?.startupLatencyMs,
  diagnostics: runtime?.diagnostics,
});

export const nextLearningTitle = (
  course: CourseLearningData,
  reel: CourseReel,
) => {
  const nextStep = courseStepAfterReel(course, reel)?.step;
  return nextStep?.type === 'reel'
    ? nextStep.reel.title
    : nextStep?.project.title;
};

export const markReelCompleted = (
  course: CourseLearningData,
  reel: CourseReel,
  unlockSuccessor = true,
): CourseLearningData => advanceAfterReel(course, reel, unlockSuccessor);
