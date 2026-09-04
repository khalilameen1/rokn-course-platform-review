export type {
  PlaybackClientPreference,
  PlaybackEvidenceContext,
  PlaybackManifest,
  PlaybackSessionEvent,
} from './playbackSession';
export {
  openPlaybackSession,
  reportPlaybackSessionEvent,
} from './playbackSession';
export type {CourseRevisionChange} from './playbackRevision';
export {subscribeCourseRevisionChanges} from './playbackRevision';
export {
  flushPendingPlaybackPositions,
  persistLocalPlaybackPosition,
  retryPendingPlaybackPositions,
  savePlaybackPosition,
} from './playbackProgress';
export {
  markSectionComplete,
  retryPendingSectionCompletions,
} from './sectionCompletion';

import {resetPlaybackProgressRuntime} from './playbackProgress';
import {resetPlaybackSessionRuntime} from './playbackSession';
import {resetSectionCompletionRuntime} from './sectionCompletion';

export const resetPlaybackRuntimeState = () => {
  resetPlaybackProgressRuntime();
  resetPlaybackSessionRuntime();
  resetSectionCompletionRuntime();
};
