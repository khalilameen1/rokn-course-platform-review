import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';

let mockFocus: (() => void | (() => void)) | undefined;
let mockCleanup: (() => void) | undefined;
let mockEpoch = 0;
jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    require('react').useEffect(() => {
      mockFocus = effect;
      mockCleanup = effect() || undefined;
      return () => mockCleanup?.();
    }, [effect]);
  },
}));
jest.mock('react-redux', () => ({
  useSelector: (selector: (value: unknown) => unknown) =>
    selector({auth: {userData: {id: 1}}}),
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    scope: 'user-1',
    epoch: mockEpoch,
  })),
  assertAccountSessionBoundary: (boundary: {epoch: number}) => {
    if (boundary.epoch !== mockEpoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  sessionIdentityKey: () => 'user-1',
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn(), delete: jest.fn()},
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(async () => true),
  getSavedLessonsPage: jest.fn(async () => ({
    lessons: [],
    page: 1,
    hasMore: false,
  })),
  removeSavedFolderFromCache: jest.fn(async () => undefined),
  removeSavedLessonFromCache: jest.fn(async () => undefined),
  removeSavedLessonEverywhereFromCache: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () =>
  jest.requireActual(
    '../src/components/VideoPlayer/courseLearning/savedCollections',
  ),
);
jest.mock('../src/components/VideoPlayer/courseLearning/persistence', () => ({
  updatePlayerStateForScope: jest.fn(async () => undefined),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => 'request-id',
}));
jest.mock('../src/services/networkExperience', () => ({
  friendlyNetworkMessage: () => 'offline',
}));

import {publicRequest} from '../src/constants/api';
import {
  createSavedFolderOption,
  deleteSavedFolderOption,
  getSavedFolderOptions,
  removeLessonFromSavedFolder,
  saveLessonToFolder,
  toggleWatchLater,
} from '../src/components/VideoPlayer/courseLearning/savedCollections';
import {useSavedLibrary} from '../src/screens/Profile/saved/useSavedLibrary';

const api = jest.mocked(publicRequest);
const folder = {id: '7', name: 'قائمتي', lessons_count: 1};
const response = (data: unknown) => ({data: {data}});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const flush = async () => {
  for (let index = 0; index < 30; index += 1) await Promise.resolve();
};

describe('saved folder reads across committed mutations', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    mockEpoch += 1;
    mockFocus = undefined;
    mockCleanup = undefined;
    await AsyncStorage.clear();
    api.get.mockResolvedValue(response([folder]));
    api.delete.mockResolvedValue(response(null));
  });

  it('refreshes the real library after creating while away instead of joining the pre-create read', async () => {
    api.get.mockResolvedValueOnce(response([]));
    const creation = deferred<ReturnType<typeof response>>();
    api.post.mockReturnValueOnce(creation.promise);
    let library!: ReturnType<typeof useSavedLibrary>;
    const Harness = () => {
      library = useSavedLibrary();
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    try {
      await act(async () => library.setNewFolderName('قائمتي'));
      await act(async () => {
        void library.createFolder();
        await flush();
      });
      const oldRead = deferred<ReturnType<typeof response>>();
      api.get.mockReturnValueOnce(oldRead.promise);
      await act(async () => {
        mockCleanup?.();
        mockCleanup = mockFocus?.() || undefined;
        await flush();
      });
      await act(async () => {
        creation.resolve(response(folder));
        await flush();
      });
      await act(async () => {
        oldRead.resolve(response([]));
        await flush();
      });
      expect(library.folderOptions).toEqual([
        expect.objectContaining({id: '7', name: 'قائمتي'}),
      ]);
      expect(library.loading).toBe(false);
      expect(api.post).toHaveBeenCalledTimes(1);
      expect(
        JSON.parse(
          (await AsyncStorage.getItem('@rokn/saved-folder-options/v1:user-1'))!,
        ),
      ).toEqual([expect.objectContaining({id: '7', name: 'قائمتي'})]);
    } finally {
      await act(async () => renderer.unmount());
    }
  });

  it.each(['create', 'delete', 'save', 'remove', 'watch-save', 'watch-remove'])(
    'does not return or cache the old list after %s succeeds',
    async mutation => {
      const oldRead = deferred<ReturnType<typeof response>>();
      api.get.mockReturnValueOnce(oldRead.promise);
      const before = getSavedFolderOptions();
      await flush();
      if (mutation === 'create') {
        api.post.mockResolvedValueOnce(response(folder));
        await createSavedFolderOption(folder.name);
      } else if (mutation === 'delete')
        await deleteSavedFolderOption(folder.id);
      else if (mutation === 'save' || mutation === 'watch-save') {
        await AsyncStorage.setItem(
          '@rokn/watch-later-folder-id/v2:user-1',
          folder.id,
        );
        api.post.mockResolvedValueOnce(
          response({is_saved: true, folder_id: 7, lesson_id: 44}),
        );
        if (mutation === 'save') await saveLessonToFolder('44', folder);
        else await toggleWatchLater('44', false);
      } else if (mutation === 'remove')
        await removeLessonFromSavedFolder('44', folder.id);
      else await toggleWatchLater('44', true);
      const expected =
        mutation === 'delete' ? [] : [{...folder, lessons_count: 2}];
      api.get.mockResolvedValue(response(expected));
      const after = getSavedFolderOptions();
      await flush();
      oldRead.resolve(response([{...folder, name: 'قديم', lessons_count: 0}]));
      const [earlier, fresh] = await Promise.all([before, after]);
      expect(fresh).toEqual(
        expected.map(item =>
          expect.objectContaining({
            id: item.id,
            name: item.name,
            lessonsCount: item.lessons_count,
          }),
        ),
      );
      expect(earlier).toEqual(fresh);
      expect(
        JSON.parse(
          (await AsyncStorage.getItem('@rokn/saved-folder-options/v1:user-1'))!,
        ),
      ).toEqual(fresh);
    },
  );

  it('coalesces unchanged reads and retains the original read when a write fails', async () => {
    const oldRead = deferred<ReturnType<typeof response>>();
    api.get.mockReturnValueOnce(oldRead.promise);
    const before = getSavedFolderOptions();
    await flush();
    api.delete.mockRejectedValueOnce(new Error('offline'));
    await expect(deleteSavedFolderOption('7')).rejects.toThrow('offline');
    const after = getSavedFolderOptions();
    await flush();
    expect(api.get).toHaveBeenCalledTimes(1);
    oldRead.resolve(response([folder]));
    expect(await before).toEqual(await after);
  });

  it('does not recover an invalidated old-owner read using the new account', async () => {
    const oldRead = deferred<ReturnType<typeof response>>();
    api.get.mockReturnValueOnce(oldRead.promise);
    const before = getSavedFolderOptions();
    await flush();
    api.post.mockResolvedValueOnce(response(folder));
    await createSavedFolderOption(folder.name);
    mockEpoch += 1;
    oldRead.resolve(response([]));
    await expect(before).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(api.get).toHaveBeenCalledTimes(1);
  });

  it('orders an already started cache write before the committed create repair', async () => {
    const storageWrite = deferred<void>();
    const setItem = AsyncStorage.setItem;
    const implementation = jest.mocked(setItem).getMockImplementation()!;
    jest.mocked(setItem).mockImplementationOnce(async (key, value) => {
      await storageWrite.promise;
      await implementation(key, value);
    });
    const existing = {id: '8', name: 'القائمة السابقة', lessons_count: 0};
    api.get.mockResolvedValueOnce(response([existing]));
    const before = getSavedFolderOptions();
    await flush();
    expect(setItem).toHaveBeenCalledTimes(1);
    api.post.mockResolvedValueOnce(response(folder));
    const creation = createSavedFolderOption(folder.name);
    await flush();
    api.get.mockRejectedValue(new Error('offline'));
    storageWrite.resolve();
    await creation;
    await before;
    expect(
      JSON.parse(
        (await AsyncStorage.getItem('@rokn/saved-folder-options/v1:user-1'))!,
      ),
    ).toEqual([
      expect.objectContaining({id: '8', name: 'القائمة السابقة'}),
      expect.objectContaining({id: '7', name: 'قائمتي'}),
    ]);
  });

  it('keeps two different folders created concurrently in the same existing cache', async () => {
    api.post
      .mockResolvedValueOnce(response(folder))
      .mockResolvedValueOnce(response({id: '8', name: 'الثانية'}));
    await Promise.all([
      createSavedFolderOption(folder.name),
      createSavedFolderOption('الثانية'),
    ]);
    expect(
      JSON.parse(
        (await AsyncStorage.getItem('@rokn/saved-folder-options/v1:user-1'))!,
      ),
    ).toEqual(
      expect.arrayContaining([
        expect.objectContaining({id: '7', name: 'قائمتي'}),
        expect.objectContaining({id: '8', name: 'الثانية'}),
      ]),
    );
  });
});
