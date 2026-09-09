import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
import type {CourseLearningData} from '../src/components/VideoPlayer/types';

let mockBoundary = {scope: 'user-1', epoch: 0};
let mockPlayerState = {
  savedLessons: [] as string[],
  savedFolderLessons: {} as Record<string, string[]>,
};
let mockServerSaved = false;
const mockCourse: CourseLearningData = {
  id: '3',
  title: 'الكورس',
  totalReels: 1,
  accessType: 'paid',
  attachments: [],
  modules: [
    {
      id: '1',
      title: 'الوحدة',
      order: 1,
      isLocked: false,
      reels: [
        {
          id: '44',
          lessonId: '44',
          sectionId: '5',
          moduleId: '1',
          title: 'المقطع',
          caption: '',
          videoUrl: 'https://cdn.example.test/44.m3u8',
          availableQualities: ['auto'],
          isPreview: true,
          isLocked: false,
          isCompleted: false,
          reelNumber: 1,
        },
      ],
    },
  ],
};

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn(), delete: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(async () => true),
  removeSavedFolderFromCache: jest.fn(async () => undefined),
  removeSavedLessonFromCache: jest.fn(async () => undefined),
  removeSavedLessonEverywhereFromCache: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/persistence', () => ({
  updatePlayerStateForScope: jest.fn(
    async (
      _scope: string,
      update: (current: typeof mockPlayerState) => typeof mockPlayerState,
    ) => {
      mockPlayerState = update(mockPlayerState);
      return mockPlayerState;
    },
  ),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  ...jest.requireActual('../src/components/VideoPlayer/courseLearning/savedCollections'),
  applyLocalLearningState: async (course: CourseLearningData) => course,
  getLocalLearningState: async () => ({...mockPlayerState, positions: {}}),
  loadCourseLearningData: async () => ({course: mockCourse}),
}));

import {publicRequest} from '../src/constants/api';
import {useReelsCourseLoader} from '../src/screens/reels/useReelsCourseLoader';
import {useReelsSavedLessons} from '../src/screens/reels/useReelsSavedLessons';
import {
  deleteSavedFolderOption,
  removeLessonFromSavedFolder,
} from '../src/components/VideoPlayer/courseLearning/savedCollections';

const api = jest.mocked(publicRequest);
const response = (data: unknown) => ({data: {data}});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const drain = async () => {
  for (let index = 0; index < 80; index += 1) await Promise.resolve();
};
const mountCourse = async () => {
  const refs = {
    closedPlaybackSessions: {current: new Set<string>()},
    loadRequest: {current: 0},
    loadAbort: {current: null},
    loadedCourse: {current: null as CourseLearningData | null},
    loadedCourseOwner: {current: mockBoundary.scope},
    playbackDurations: {current: {}},
    playbackRuntime: {current: {}},
    positions: {current: {}},
  };
  const mounted = {current: true};
  const ownerGeneration = {current: 1};
  const navigation = {replace: jest.fn()};
  const setConnectionNote = jest.fn();
  const setters = {
    requestInitialPosition: jest.fn(),
    setConnectionNote,
    setCourse: jest.fn(),
    setLoadError: jest.fn(),
    setLoading: jest.fn(),
    setPreviewGateVisible: jest.fn(),
    setServerSession: jest.fn(),
  };
  let saved!: ReturnType<typeof useReelsSavedLessons>;
  const Harness = () => {
    saved = useReelsSavedLessons({
      loadedCourse: refs.loadedCourse,
      mounted,
      ownerGeneration,
      scopeKey: `${mockBoundary.scope}:3`,
      setConnectionNote,
    });
    useReelsCourseLoader({
      navigation,
      identityKey: mockBoundary.scope,
      params: {courseId: '3'},
      previewMode: false,
      refs,
      ...setters,
      setSavedLessons: saved.setSavedLessons,
    });
    return null;
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
    await drain();
  });
  return {
    renderer,
    Harness,
    refs,
    ownerGeneration,
    get current() {
      return saved;
    },
  };
};

beforeEach(async () => {
  jest.clearAllMocks();
  await AsyncStorage.clear();
  mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
  mockPlayerState = {savedLessons: [], savedFolderLessons: {}};
  mockServerSaved = false;
  await AsyncStorage.setItem('@rokn/watch-later-folder-id/v2:user-1', '7');
  api.get.mockImplementation(async route => {
    if (route === 'saved-lessons/state')
      return response({saved_lesson_ids: mockServerSaved ? [44] : []});
    return response([{id: 7, name: 'المشاهدة لاحقًا'}]);
  });
});

it.each(
  ['save', 'folder-save', 'remove'].flatMap(mutation =>
    ['before-ack', 'after-ack'].map(arrival => [mutation, arrival]),
  ),
)(
  'keeps the accepted %s when the old course saved-state response arrives %s',
  async (mutation, arrival) => {
    const initiallySaved = mutation === 'remove';
    mockServerSaved = initiallySaved;
    mockPlayerState = {
      savedLessons: initiallySaved ? ['44'] : [],
      savedFolderLessons: initiallySaved ? {'7': ['44']} : {},
    };
    const oldRead = deferred<ReturnType<typeof response>>();
    const ack = deferred<void>();
    api.get.mockReturnValueOnce(oldRead.promise);
    api.post.mockImplementation(async () => {
      await ack.promise;
      mockServerSaved = true;
      return response({folder_id: 7, lesson_id: 44, is_saved: true});
    });
    api.delete.mockImplementation(async () => {
      await ack.promise;
      mockServerSaved = false;
      return response(null);
    });
    const view = await mountCourse();
    let changing: Promise<void> | undefined;
    try {
      expect(api.get).toHaveBeenCalledWith('saved-lessons/state', {
        params: {lesson_ids: ['44']},
      });
      await act(async () => {
        changing = view.current.toggleSaved(
          mockCourse.modules[0].reels[0],
          initiallySaved
            ? null
            : mutation === 'folder-save'
            ? {id: '7', name: 'قائمتي'}
            : undefined,
        );
        await drain();
      });
      const oldSnapshot = response({saved_lesson_ids: initiallySaved ? [44] : []});
      if (arrival === 'before-ack') {
        await act(async () => {
          oldRead.resolve(oldSnapshot);
          await drain();
        });
      }
      await act(async () => {
        ack.resolve();
        await changing;
        await drain();
      });
      if (arrival === 'after-ack') {
        await act(async () => {
          oldRead.resolve(oldSnapshot);
          await drain();
        });
      }
      expect([...view.current.savedLessons]).toEqual(initiallySaved ? [] : ['44']);
      expect(mockPlayerState.savedLessons).toEqual(initiallySaved ? [] : ['44']);
      expect(view.current.savingLessons.size).toBe(0);
      expect(api.post).toHaveBeenCalledTimes(initiallySaved ? 0 : 1);
      expect(api.delete).toHaveBeenCalledTimes(initiallySaved ? 1 : 0);
      expect(api.get.mock.calls.filter(([path]) => path === 'saved-lessons/state')).toHaveLength(
        arrival === 'after-ack' ? 2 : 1,
      );
    } finally {
      ack.resolve();
      oldRead.resolve(response({saved_lesson_ids: mockServerSaved ? [44] : []}));
      await changing;
      await act(async () => {
        await drain();
        view.renderer.unmount();
      });
    }
  },
);

it.each([false, true])(
  'accepts an unchanged authoritative saved response (%s) instead of keeping a local guess',
  async serverSaved => {
    mockServerSaved = serverSaved;
    mockPlayerState = {
      savedLessons: serverSaved ? [] : ['44'],
      savedFolderLessons: serverSaved ? {} : {'7': ['44']},
    };
    const view = await mountCourse();
    try {
      expect([...view.current.savedLessons]).toEqual(serverSaved ? ['44'] : []);
      expect(mockPlayerState.savedLessons).toEqual(serverSaved ? ['44'] : []);
      expect(api.get).toHaveBeenCalledTimes(1);
      expect(api.post).not.toHaveBeenCalled();
      expect(api.delete).not.toHaveBeenCalled();
    } finally {
      act(() => view.renderer.unmount());
    }
  },
);

it.each(['http-failure', 'malformed-ack'])(
  'rolls back a save without treating %s as a confirmed mutation',
  async failure => {
    const view = await mountCourse();
    if (failure === 'http-failure')
      api.post.mockRejectedValueOnce(new Error('offline'));
    else api.post.mockResolvedValueOnce(response({folder_id: 7, lesson_id: 44}));
    try {
      await act(async () => {
        await expect(
          view.current.toggleSaved(mockCourse.modules[0].reels[0]),
        ).rejects.toThrow();
        await drain();
      });
      expect([...view.current.savedLessons]).toEqual([]);
      expect(mockPlayerState.savedLessons).toEqual([]);
      expect(view.current.savingLessons.size).toBe(0);
      expect(api.get).toHaveBeenCalledTimes(1);
      expect(api.post).toHaveBeenCalledTimes(1);
    } finally {
      act(() => view.renderer.unmount());
    }
  },
);

it.each(['folder', 'membership'])(
  'does not revive the last saved membership when %s deletion overtakes the state read',
  async kind => {
    mockServerSaved = true;
    mockPlayerState = {savedLessons: ['44'], savedFolderLessons: {'7': ['44']}};
    const oldRead = deferred<ReturnType<typeof response>>();
    api.get.mockReturnValueOnce(oldRead.promise);
    api.delete.mockImplementation(async () => {
      mockServerSaved = false;
      return response(null);
    });
    const view = await mountCourse();
    try {
      await act(async () => {
        if (kind === 'folder') await deleteSavedFolderOption('7');
        else await removeLessonFromSavedFolder('44', '7');
        oldRead.resolve(response({saved_lesson_ids: [44]}));
        await drain();
      });
      expect([...view.current.savedLessons]).toEqual([]);
      expect(mockPlayerState.savedLessons).toEqual([]);
      expect(api.get).toHaveBeenCalledTimes(2);
      expect(api.delete).toHaveBeenCalledTimes(1);
    } finally {
      oldRead.resolve(response({saved_lesson_ids: []}));
      act(() => view.renderer.unmount());
    }
  },
);

it.each(['offline', 'second-mutation'])(
  'keeps accepted state when the bounded fresh read encounters %s',
  async outcome => {
    const first = deferred<ReturnType<typeof response>>();
    const second = deferred<ReturnType<typeof response>>();
    api.get.mockReturnValueOnce(first.promise);
    if (outcome === 'offline') api.get.mockRejectedValueOnce(new Error('offline'));
    else api.get.mockReturnValueOnce(second.promise);
    api.post.mockImplementation(async () => {
      mockServerSaved = true;
      return response({is_saved: true, folder_id: 7, lesson_id: 44});
    });
    api.delete.mockImplementation(async () => {
      mockServerSaved = false;
      return response(null);
    });
    const view = await mountCourse();
    try {
      await act(async () => {
        await view.current.toggleSaved(mockCourse.modules[0].reels[0]);
        first.resolve(response({saved_lesson_ids: []}));
        await drain();
      });
      if (outcome === 'second-mutation') {
        await act(async () => {
          await view.current.toggleSaved(mockCourse.modules[0].reels[0], null);
          second.resolve(response({saved_lesson_ids: [44]}));
          await drain();
        });
      }
      const expected = outcome === 'offline' ? ['44'] : [];
      expect([...view.current.savedLessons]).toEqual(expected);
      expect(mockPlayerState.savedLessons).toEqual(expected);
      expect(api.get).toHaveBeenCalledTimes(2);
      expect(api.post).toHaveBeenCalledTimes(1);
      expect(api.delete).toHaveBeenCalledTimes(outcome === 'offline' ? 0 : 1);
    } finally {
      first.resolve(response({saved_lesson_ids: []}));
      second.resolve(response({saved_lesson_ids: []}));
      act(() => view.renderer.unmount());
    }
  },
);

it('does not apply or retry an old account state read after the same course opens for another account', async () => {
  const oldRead = deferred<ReturnType<typeof response>>();
  api.get.mockReturnValueOnce(oldRead.promise);
  const view = await mountCourse();
  try {
    mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
    mockPlayerState = {savedLessons: ['55'], savedFolderLessons: {'8': ['55']}};
    await act(async () => {
      view.ownerGeneration.current += 1;
      view.renderer.update(<view.Harness />);
      await drain();
    });
    await act(async () => {
      oldRead.resolve(response({saved_lesson_ids: [44]}));
      await drain();
    });
    expect([...view.current.savedLessons]).toEqual([]);
    expect(mockPlayerState.savedLessons).toEqual(['55']);
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(api.post).not.toHaveBeenCalled();
    expect(api.delete).not.toHaveBeenCalled();
  } finally {
    oldRead.resolve(response({saved_lesson_ids: []}));
    act(() => view.renderer.unmount());
  }
});
