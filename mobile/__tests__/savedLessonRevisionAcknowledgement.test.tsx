import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';

let mockPlayerState = {
  savedLessons: [] as string[],
  savedFolderLessons: {} as Record<string, string[]>,
};
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn(), delete: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({scope: 'account-a', epoch: 1}),
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: async () => true,
  removeSavedFolderFromCache: jest.fn(),
  removeSavedLessonEverywhereFromCache: jest.fn(),
  removeSavedLessonFromCache: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/persistence', () => ({
  updatePlayerStateForScope: async (
    _scope: string,
    update: (value: typeof mockPlayerState) => typeof mockPlayerState,
  ) => {
    mockPlayerState = update(mockPlayerState);
    return mockPlayerState;
  },
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () =>
  jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/savedCollections',
  ),
);

import {publicRequest} from '../src/constants/api';
import {useReelsSavedLessons} from '../src/screens/reels/useReelsSavedLessons';
import type {
  CourseLearningData,
  CourseReel,
} from '../src/components/VideoPlayer/types';

// The backend's real API regression asserts this entire response after saving
// historical lesson10 as current lesson20, including its committed DB row.
const response = require('./fixtures/savedLessonRevisionAcknowledgement.json');

describe('saved reel acknowledgement after publication', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    await AsyncStorage.setItem('@rokn/watch-later-folder-id/v2:account-a', '1');
    mockPlayerState = {savedLessons: [], savedFolderLessons: {}};
    jest.mocked(publicRequest.post).mockResolvedValue({data: response});
  });

  it.each([
    {name: 'the selected folder', folder: {id: '1', name: 'Folder 1'}},
    {name: 'watch later', folder: undefined},
  ])(
    'keeps the visible historical reel saved in $name after the server commits its replacement',
    async ({folder}) => {
      let controller!: ReturnType<typeof useReelsSavedLessons>;
      const setConnectionNote = jest.fn();
      const loadedCourse = {current: {id: '1'} as CourseLearningData};
      const mounted = {current: true};
      const ownerGeneration = {current: 1};
      const Harness = () => {
        controller = useReelsSavedLessons({
          loadedCourse,
          mounted,
          ownerGeneration,
          scopeKey: 'account-a:1',
          setConnectionNote,
        });
        return null;
      };
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });

      try {
        await act(async () => {
          await expect(
            controller.toggleSaved({lessonId: '10'} as CourseReel, folder),
          ).resolves.toBeUndefined();
        });
        expect(controller.savedLessons.has('10')).toBe(true);
        expect(controller.savingLessons.size).toBe(0);
        expect(mockPlayerState.savedFolderLessons).toEqual({'1': ['10']});
        expect(setConnectionNote).not.toHaveBeenCalled();
        expect(publicRequest.post).toHaveBeenCalledTimes(1);
        expect(publicRequest.post).toHaveBeenCalledWith(
          'saved-folders/1/lessons',
          {lesson_id: '10'},
        );
      } finally {
        await act(async () => renderer.unmount());
      }
    },
  );
});
