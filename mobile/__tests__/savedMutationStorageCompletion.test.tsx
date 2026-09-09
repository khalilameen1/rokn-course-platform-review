import React from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';

let mockBoundary = {scope: 'saved-completion-1', epoch: 1};
jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    require('react').useEffect(effect, [effect]);
  },
}));
jest.mock('react-redux', () => ({
  useSelector: (selector: (state: unknown) => unknown) =>
    selector({auth: {userData: {id: 1}}}),
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn(), delete: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string, boundary = mockBoundary) =>
    `${key}:${boundary.scope}`,
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  getCurrentAccountStorageScope: async () => mockBoundary.scope,
  sessionIdentityKey: () => mockBoundary.scope,
}));
jest.mock('../src/services/roknApi', () => ({
  ...jest.requireActual('../src/services/api/savedLessons'),
  hasSession: async () => true,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () =>
  jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/savedCollections',
  ),
);
jest.mock('../src/services/networkExperience', () => ({
  friendlyNetworkMessage: () => 'تعذّر الاتصال',
}));

import {publicRequest} from '../src/constants/api';
import {useSavedLibrary} from '../src/screens/Profile/saved/useSavedLibrary';
import {getSavedLessonsPage} from '../src/services/api/savedLessons';
import {
  deleteSavedFolderOption,
  saveLessonToFolder,
  toggleWatchLater,
} from '../src/components/VideoPlayer/courseLearning/savedCollections';

const api = jest.mocked(publicRequest);
const originalSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const originalGet = jest.mocked(AsyncStorage.getItem).getMockImplementation()!;
const originalRemove = jest
  .mocked(AsyncStorage.removeItem)
  .getMockImplementation()!;
const response = (data: unknown) => ({data: {data}});
const folder = {id: 7, name: 'للمراجعة', lessons_count: 1};
const lesson = {
  id: 11,
  title: 'المقطع',
  duration_seconds: 60,
  course: {id: 9, title: 'الكورس'},
  folder_memberships: [folder],
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const flush = async () => {
  for (let index = 0; index < 100; index += 1) await Promise.resolve();
};
const observe = <T,>(operation: Promise<T>) => {
  const result: {settled: boolean; value?: T; error?: unknown} = {
    settled: false,
  };
  const promise = operation.then(
    value => {
      result.settled = true;
      result.value = value;
    },
    error => {
      result.settled = true;
      result.error = error;
    },
  );
  return {result, promise};
};

describe('accepted saved mutations versus native cache completion', () => {
  let library!: ReturnType<typeof useSavedLibrary>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let folderExists: boolean;
  let membershipExists: boolean;
  const Harness = () => {
    library = useSavedLibrary();
    return null;
  };

  beforeEach(async () => {
    jest.clearAllMocks();
    jest.useFakeTimers();
    mockBoundary = {
      scope: `saved-completion-${mockBoundary.epoch + 1}`,
      epoch: mockBoundary.epoch + 1,
    };
    jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
    jest.mocked(AsyncStorage.getItem).mockImplementation(originalGet);
    jest.mocked(AsyncStorage.removeItem).mockImplementation(originalRemove);
    await AsyncStorage.clear();
    folderExists = true;
    membershipExists = true;
    api.get.mockImplementation(async (url: string) => {
      if (url === 'saved-folders')
        return response(folderExists ? [folder] : []);
      return response({
        ...(url.startsWith('saved-folders/') ? {folder} : {}),
        lessons: membershipExists && folderExists ? [lesson] : [],
        pagination: {
          current_page: 1,
          last_page: 1,
          total: membershipExists && folderExists ? 1 : 0,
        },
      });
    });
    api.delete.mockImplementation(async (url: string) => {
      if (url === 'saved-folders/7') folderExists = false;
      else membershipExists = false;
      return response(null);
    });
    api.post.mockResolvedValue(
      response({is_saved: true, folder_id: 7, lesson_id: 11}),
    );
    await AsyncStorage.setItem(
      `@rokn/course-player/v3:${mockBoundary.scope}`,
      JSON.stringify({savedLessons: ['11'], savedFolderLessons: {'7': ['11']}}),
    );
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    expect(library.saved).toHaveLength(1);
  });

  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
    jest.mocked(AsyncStorage.getItem).mockImplementation(originalGet);
    jest.mocked(AsyncStorage.removeItem).mockImplementation(originalRemove);
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it.each([
    ['membership', 'read'],
    ['membership', 'write'],
    ['folder', 'read'],
    ['folder', 'write'],
  ])(
    'finishes a confirmed %s deletion while the lesson-cache %s remains stalled',
    async (operation, nativeOperation) => {
      if (operation === 'folder') {
        await act(async () => {
          library.selectFolder('7');
          await flush();
        });
      }
      const cacheKey = `@rokn/saved-lessons/v2:${mockBoundary.scope}`;
      const gate = deferred<void>();
      let nativeStarted = false;
      if (nativeOperation === 'write') {
        jest
          .mocked(AsyncStorage.setItem)
          .mockImplementation(async (key, value) => {
            if (key === cacheKey) {
              nativeStarted = true;
              await gate.promise;
            }
            return originalSet(key, value);
          });
      } else {
        jest.mocked(AsyncStorage.getItem).mockImplementation(async key => {
          if (key === cacheKey) {
            nativeStarted = true;
            await gate.promise;
          }
          return originalGet(key);
        });
      }
      let completion: Promise<void> | undefined;
      try {
        await act(async () => {
          if (operation === 'membership') {
            completion = library.removeSaved(library.saved[0]);
          } else {
            library.deleteActiveFolder();
            const buttons = jest.mocked(Alert.alert).mock.calls.at(-1)?.[2];
            buttons?.find(button => button.text === 'حذف')?.onPress?.();
          }
          await flush();
        });
        expect(api.delete).toHaveBeenCalledTimes(1);
        expect(nativeStarted).toBe(true);
        await act(async () => {
          await jest.advanceTimersByTimeAsync(2000);
        });
        expect({
          removing: library.removingSaved.size,
          deletingFolder: library.deletingFolder,
        }).toEqual({removing: 0, deletingFolder: false});
        expect(library.actionError).toBe('');
        expect(library.folderError).toBe('');
        const player = JSON.parse(
          (await AsyncStorage.getItem(
            `@rokn/course-player/v3:${mockBoundary.scope}`,
          )) || '{}',
        );
        expect(player.savedLessons).toEqual([]);
        const index = JSON.parse(
          (await AsyncStorage.getItem(
            `@rokn/saved-folder-options/v1:${mockBoundary.scope}`,
          )) || '[]',
        );
        if (operation === 'folder') {
          expect(index).toEqual([]);
          expect(library.activeFolderId).toBe('all');
          expect(library.folderOptions).toEqual([]);
        } else {
          expect(index[0].lessonsCount).toBeUndefined();
        }
      } finally {
        await act(async () => {
          gate.resolve();
          await completion;
          await flush();
        });
      }
    },
  );

  it.each(['save', 'unsave'])(
    'settles a confirmed %s without letting its late player write overtake the next action',
    async operation => {
      const playerKey = `@rokn/course-player/v3:${mockBoundary.scope}`;
      const gate = deferred<void>();
      let writeCount = 0;
      jest
        .mocked(AsyncStorage.setItem)
        .mockImplementation(async (key, value) => {
          if (key === playerKey) {
            writeCount += 1;
            if (writeCount === 1) await gate.promise;
          }
          return originalSet(key, value);
        });
      const pending = observe(
        operation === 'save'
          ? saveLessonToFolder('11', {id: '7', name: folder.name})
          : toggleWatchLater('11', true),
      );
      try {
        await flush();
        expect(writeCount).toBe(1);
        await jest.advanceTimersByTimeAsync(2000);
        expect(pending.result).toEqual({
          settled: true,
          value: operation === 'save',
        });
        expect(
          operation === 'save' ? api.post : api.delete,
        ).toHaveBeenCalledTimes(1);
        const next = observe(
          operation === 'save'
            ? toggleWatchLater('11', true)
            : saveLessonToFolder('11', {id: '7', name: folder.name}),
        );
        await flush();
        await jest.advanceTimersByTimeAsync(2000);
        expect(next.result).toEqual({
          settled: true,
          value: operation !== 'save',
        });
        expect(writeCount).toBe(1);
        gate.resolve();
        await next.promise;
        await flush();
        expect(writeCount).toBe(2);
        const player = JSON.parse(
          (await AsyncStorage.getItem(playerKey)) || '{}',
        );
        expect(player.savedLessons).toEqual(operation === 'save' ? [] : ['11']);
      } finally {
        gate.resolve();
        await pending.promise;
        await flush();
      }
    },
  );

  it('does not let an old GET or offline cache revive a confirmed deletion while its raw repair is pending', async () => {
    const cacheKey = `@rokn/saved-lessons/v2:${mockBoundary.scope}`;
    const oldRead = deferred<ReturnType<typeof response>>();
    api.get.mockReturnValueOnce(oldRead.promise);
    const reading = getSavedLessonsPage();
    await flush();
    const gate = deferred<void>();
    let writeCount = 0;
    jest.mocked(AsyncStorage.setItem).mockImplementation(async (key, value) => {
      if (key === cacheKey) {
        writeCount += 1;
        await gate.promise;
      }
      return originalSet(key, value);
    });
    let completion!: Promise<void>;
    try {
      await act(async () => {
        completion = library.removeSaved(library.saved[0]);
        await flush();
        await jest.advanceTimersByTimeAsync(2000);
      });
      expect(library.removingSaved.size).toBe(0);
      oldRead.resolve(
        response({
          lessons: [lesson],
          pagination: {current_page: 1, last_page: 1, total: 1},
        }),
      );
      expect((await reading).lessons).toEqual([]);
      expect(writeCount).toBe(1); // The newer empty page cannot overtake the raw repair.
      api.get.mockRejectedValueOnce(new Error('OFFLINE'));
      const offline = observe(getSavedLessonsPage());
      await flush();
      await jest.advanceTimersByTimeAsync(1000);
      expect(offline.result.settled).toBe(true);
      expect(offline.result.error).toBeInstanceOf(Error);
      expect(offline.result.value).toBeUndefined();
      expect(api.delete).toHaveBeenCalledTimes(1);
      await act(async () => {
        gate.resolve();
        await completion;
        await flush();
      });
      api.get.mockRejectedValueOnce(new Error('OFFLINE'));
      expect((await getSavedLessonsPage()).lessons).toEqual([]);
      expect(writeCount).toBe(2);
    } finally {
      oldRead.resolve(
        response({
          lessons: [],
          pagination: {current_page: 1, last_page: 1, total: 0},
        }),
      );
      gate.resolve();
      await reading;
      await completion;
      await flush();
    }
  });

  it('retains the row and count when DELETE fails before acknowledgement', async () => {
    api.delete.mockRejectedValueOnce(new Error('NETWORK_ERROR'));
    const writesBefore = jest.mocked(AsyncStorage.setItem).mock.calls.length;
    await act(async () => {
      await library.removeSaved(library.saved[0]);
    });
    expect(library.saved.map(value => value.id)).toEqual(['11']);
    expect(library.folderCounts.get('7')).toBe(1);
    expect(library.removingSaved.size).toBe(0);
    expect(library.actionError).not.toBe('');
    expect(jest.mocked(AsyncStorage.setItem).mock.calls.length).toBe(
      writesBefore,
    );
  });

  it('rejects the original account after an ACK while the player repair is still pending', async () => {
    const originalOwner = {...mockBoundary};
    const gate = deferred<void>();
    jest.mocked(AsyncStorage.setItem).mockImplementation(async (key, value) => {
      if (key === `@rokn/course-player/v3:${originalOwner.scope}`)
        await gate.promise;
      return originalSet(key, value);
    });
    const pending = observe(
      saveLessonToFolder('11', {id: '7', name: folder.name}),
    );
    try {
      await flush();
      expect(api.post).toHaveBeenCalledTimes(1);
      mockBoundary = {...originalOwner, epoch: originalOwner.epoch + 1};
      await jest.advanceTimersByTimeAsync(2000);
      expect(pending.result.settled).toBe(true);
      expect(pending.result.error).toEqual(
        new Error('ACCOUNT_CHANGED_DURING_REQUEST'),
      );
      expect(pending.result.value).toBeUndefined();
    } finally {
      gate.resolve();
      await pending.promise;
      await flush();
    }
  });

  it.each(['read', 'remove'])(
    'keeps a newly created watch-later hint when the prior folder cleanup %s finishes late',
    async phase => {
      const hintKey = `@rokn/watch-later-folder-id/v2:${mockBoundary.scope}`;
      await AsyncStorage.setItem(hintKey, '7');
      const gate = deferred<void>();
      let blocked = false;
      if (phase === 'read') {
        jest.mocked(AsyncStorage.getItem).mockImplementation(async key => {
          const value = await originalGet(key);
          if (key === hintKey && !blocked) {
            blocked = true;
            await gate.promise;
          }
          return value;
        });
      } else {
        jest.mocked(AsyncStorage.removeItem).mockImplementation(async key => {
          if (key === hintKey && !blocked) {
            blocked = true;
            await gate.promise;
          }
          return originalRemove(key);
        });
      }
      let newFolderCreated = false;
      api.get.mockImplementation(async () =>
        response(newFolderCreated ? [{id: 8, name: 'المشاهدة لاحقًا'}] : []),
      );
      api.post.mockImplementation(async (url: string) => {
        if (url === 'saved-folders/7/lessons') throw {response: {status: 404}};
        if (url === 'saved-folders') {
          newFolderCreated = true;
          return response({id: 8, name: 'المشاهدة لاحقًا'});
        }
        return response({is_saved: true, folder_id: 8, lesson_id: 11});
      });
      const deleting = observe(deleteSavedFolderOption('7'));
      let saving: ReturnType<typeof observe<boolean>> | undefined;
      try {
        await flush();
        expect(blocked).toBe(true);
        await jest.advanceTimersByTimeAsync(1000);
        expect(deleting.result).toEqual({settled: true, value: undefined});
        saving = observe(toggleWatchLater('11', false));
        await flush();
        await jest.advanceTimersByTimeAsync(3000);
        expect(saving.result).toEqual({settled: true, value: true});
        expect(api.post).toHaveBeenCalledWith('saved-folders/8/lessons', {
          lesson_id: '11',
        });
        const requestsBeforeCleanup = api.post.mock.calls.length;
        gate.resolve();
        await deleting.promise;
        await saving.promise;
        await flush();
        expect(await AsyncStorage.getItem(hintKey)).toBe('8');
        expect(api.delete).toHaveBeenCalledTimes(1);
        expect(api.post).toHaveBeenCalledTimes(requestsBeforeCleanup);
      } finally {
        gate.resolve();
        await deleting.promise;
        await saving?.promise;
        await flush();
      }
    },
  );

  it('keeps another account independent from a stalled watch-later hint cleanup', async () => {
    const oldOwner = {...mockBoundary};
    const oldKey = `@rokn/watch-later-folder-id/v2:${oldOwner.scope}`;
    await AsyncStorage.setItem(oldKey, '7');
    const gate = deferred<void>();
    jest.mocked(AsyncStorage.getItem).mockImplementation(async key => {
      const value = await originalGet(key);
      if (key === oldKey) await gate.promise;
      return value;
    });
    const deleting = observe(deleteSavedFolderOption('7'));
    let saving: ReturnType<typeof observe<boolean>> | undefined;
    try {
      await flush();
      await jest.advanceTimersByTimeAsync(1000);
      expect(deleting.result.settled).toBe(true);
      mockBoundary = {
        scope: `${oldOwner.scope}-other`,
        epoch: oldOwner.epoch + 1,
      };
      api.get.mockResolvedValue(response([{id: 8, name: 'المشاهدة لاحقًا'}]));
      api.post.mockResolvedValue(
        response({is_saved: true, folder_id: 8, lesson_id: 11}),
      );
      saving = observe(toggleWatchLater('11', false));
      await flush();
      expect(saving.result).toEqual({settled: true, value: true});
      gate.resolve();
      await deleting.promise;
      await saving.promise;
      await flush();
      expect(
        await AsyncStorage.getItem(
          `@rokn/watch-later-folder-id/v2:${mockBoundary.scope}`,
        ),
      ).toBe('8');
      expect(await AsyncStorage.getItem(oldKey)).toBe('7');
    } finally {
      gate.resolve();
      await deleting.promise;
      await saving?.promise;
      await flush();
    }
  });
});
