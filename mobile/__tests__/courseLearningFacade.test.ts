jest.mock('react-native-fs', () => ({
  CachesDirectoryPath: '/cache',
  copyFile: jest.fn(),
  exists: jest.fn(),
  mkdir: jest.fn(),
  readDir: jest.fn(),
  stat: jest.fn(),
  unlink: jest.fn(),
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    delete: jest.fn(),
    get: jest.fn(),
    post: jest.fn(),
  },
}));

jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(),
}));

import * as facade from '../src/components/VideoPlayer/courseLearningApi';
import * as assistant from '../src/components/VideoPlayer/courseLearning/assistant';
import * as mapping from '../src/components/VideoPlayer/courseLearning/mapping';
import * as persistence from '../src/components/VideoPlayer/courseLearning/persistence';
import * as playback from '../src/components/VideoPlayer/courseLearning/playback';
import * as projects from '../src/components/VideoPlayer/courseLearning/projects';
import * as savedCollections from '../src/components/VideoPlayer/courseLearning/savedCollections';

describe('course learning facade', () => {
  it('keeps the established runtime exports without exposing internals', () => {
    const expected = {
      WATCH_HISTORY_ENABLED_KEY: persistence.WATCH_HISTORY_ENABLED_KEY,
      applyLocalLearningState: persistence.applyLocalLearningState,
      askCourseAssistant: assistant.askCourseAssistant,
      cancelCourseAssistantTurn: assistant.cancelCourseAssistantTurn,
      clearCurrentAccountLearningFiles:
        projects.clearCurrentAccountLearningFiles,
      clearLocalWatchHistory: persistence.clearLocalWatchHistory,
      courseIncludesAssistant: assistant.courseIncludesAssistant,
      createSavedFolderOption: savedCollections.createSavedFolderOption,
      deleteSavedFolderOption: savedCollections.deleteSavedFolderOption,
      flushPendingPlaybackPositions: playback.flushPendingPlaybackPositions,
      getLocalLearningState: persistence.getLocalLearningState,
      getSavedFolderOptions: savedCollections.getSavedFolderOptions,
      isWatchHistoryEnabled: persistence.isWatchHistoryEnabled,
      loadCourseAssistantHistory: assistant.loadCourseAssistantHistory,
      loadCourseLearningData: mapping.loadCourseLearningData,
      loadProjectFeedbackThread: projects.loadProjectFeedbackThread,
      loadProjectResolution: projects.loadProjectResolution,
      mapCoursePayload: mapping.mapCoursePayload,
      markSectionComplete: playback.markSectionComplete,
      migrateGuestLearningState: persistence.migrateGuestLearningState,
      openPlaybackSession: playback.openPlaybackSession,
      openCourseAssistantAttachment: assistant.openCourseAssistantAttachment,
      openProjectInputAttachment: projects.openProjectInputAttachment,
      persistLocalPlaybackPosition: playback.persistLocalPlaybackPosition,
      pollCourseAssistantTurn: assistant.pollCourseAssistantTurn,
      quiesceLearningRuntime: projects.quiesceLearningRuntime,
      readPlayerStateForScope: persistence.readPlayerStateForScope,
      reconcileServerSavedLessons: savedCollections.reconcileServerSavedLessons,
      removeLessonFromSavedFolder: savedCollections.removeLessonFromSavedFolder,
      reportPlaybackSessionEvent: playback.reportPlaybackSessionEvent,
      retryPendingPlaybackPositions: playback.retryPendingPlaybackPositions,
      retryPendingProjectSubmissions: projects.retryPendingProjectSubmissions,
      retryProjectReport: projects.retryProjectReport,
      retryPendingSectionCompletions: playback.retryPendingSectionCompletions,
      saveLessonToFolder: savedCollections.saveLessonToFolder,
      savePlaybackPosition: playback.savePlaybackPosition,
      subscribeCourseRevisionChanges: playback.subscribeCourseRevisionChanges,
      sendProjectFeedbackMessage: projects.sendProjectFeedbackMessage,
      submitProjectAttempt: projects.submitProjectAttempt,
      toggleWatchLater: savedCollections.toggleWatchLater,
      uploadCourseAssistantAttachment:
        assistant.uploadCourseAssistantAttachment,
      uploadProjectFeedbackAttachment: projects.uploadProjectFeedbackAttachment,
      watchProjectResolution: projects.watchProjectResolution,
    };

    expect(facade).toMatchObject(expected);
    expect(Object.keys(facade).sort()).toEqual(Object.keys(expected).sort());
  });
});
