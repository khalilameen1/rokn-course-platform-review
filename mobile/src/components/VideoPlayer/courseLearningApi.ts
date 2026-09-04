export {
  applyLocalLearningState,
  clearLocalWatchHistory,
  getLocalLearningState,
  isWatchHistoryEnabled,
  migrateGuestLearningState,
  readPlayerStateForScope,
  WATCH_HISTORY_ENABLED_KEY,
} from './courseLearning/persistence';
export {
  loadCourseLearningData,
  mapCoursePayload,
} from './courseLearning/mapping';
export {
  flushPendingPlaybackPositions,
  markSectionComplete,
  openPlaybackSession,
  persistLocalPlaybackPosition,
  reportPlaybackSessionEvent,
  retryPendingPlaybackPositions,
  retryPendingSectionCompletions,
  savePlaybackPosition,
  subscribeCourseRevisionChanges,
} from './courseLearning/playback';
export type {
  CourseRevisionChange,
  PlaybackClientPreference,
  PlaybackEvidenceContext,
  PlaybackManifest,
  PlaybackSessionEvent,
} from './courseLearning/playback';
export {
  createSavedFolderOption,
  deleteSavedFolderOption,
  getSavedFolderOptions,
  reconcileServerSavedLessons,
  removeLessonFromSavedFolder,
  saveLessonToFolder,
  toggleWatchLater,
} from './courseLearning/savedCollections';
export type {SavedFolderOption} from './courseLearning/savedCollections';
export {
  clearCurrentAccountLearningFiles,
  quiesceLearningRuntime,
  retryPendingProjectSubmissions,
  loadProjectFeedbackThread,
  loadProjectResolution,
  retryProjectReport,
  watchProjectResolution,
  openProjectInputAttachment,
  sendProjectFeedbackMessage,
  uploadProjectFeedbackAttachment,
  submitProjectAttempt,
} from './courseLearning/projects';
export type {
  ProjectSubmissionOutcome,
  ProjectSubmissionRetryOutcome,
} from './courseLearning/projects';
export {
  askCourseAssistant,
  courseIncludesAssistant,
  loadCourseAssistantHistory,
  pollCourseAssistantTurn,
  uploadCourseAssistantAttachment,
  cancelCourseAssistantTurn,
  openCourseAssistantAttachment,
} from './courseLearning/assistant';
