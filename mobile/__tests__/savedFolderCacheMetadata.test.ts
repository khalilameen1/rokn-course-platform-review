import AsyncStorage from '@react-native-async-storage/async-storage';

let mockBoundary = {scope: 'user-a', epoch: 0};

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn(), delete: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({...mockBoundary})),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(async () => true),
  removeSavedFolderFromCache: jest.fn(async () => undefined),
  removeSavedLessonEverywhereFromCache: jest.fn(async () => undefined),
  removeSavedLessonFromCache: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/persistence', () => ({
  updatePlayerStateForScope: jest.fn(async () => undefined),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => '11111111-1111-4111-8111-111111111111',
}));

import {publicRequest} from '../src/constants/api';
import {
  removeSavedFolderFromCache,
  removeSavedLessonEverywhereFromCache,
  removeSavedLessonFromCache,
} from '../src/services/roknApi';
import {updatePlayerStateForScope} from '../src/components/VideoPlayer/courseLearning/persistence';
import {
  createSavedFolderOption,
  deleteSavedFolderOption,
  getSavedFolderOptions,
  removeLessonFromSavedFolder,
  saveLessonToFolder,
  toggleWatchLater,
} from '../src/components/VideoPlayer/courseLearning/savedCollections';

const api = jest.mocked(publicRequest);
const cacheKey = '@rokn/saved-folder-options/v1:user-a';
const response = (data: unknown) => ({data: {data}});
const folder = {
  id: 7,
  name: 'للمراجعة',
  image: 'https://cdn.example.test/folder-cover.jpg',
  lessons_count: 6,
};
const normalizedFolder = {
  id: '7',
  name: folder.name,
  imageUrl: folder.image,
  lessonsCount: folder.lessons_count,
};
const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const drain = async () => {
  for (let index = 0; index < 30; index += 1) await Promise.resolve();
};
const observe = <T>(operation: Promise<T>) => {
  const result: {settled: boolean; value?: T; error?: unknown} = {
    settled: false,
  };
  const promise = operation.then(
    value => {
      result.value = value;
      result.settled = true;
    },
    error => {
      result.error = error;
      result.settled = true;
    },
  );
  return {result, promise};
};

describe('saved folder cache metadata round-trip', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-a', epoch: mockBoundary.epoch + 1};
    await AsyncStorage.clear();
    api.get.mockResolvedValue(response([folder]));
    api.delete.mockResolvedValue(response(null));
  });

  it('keeps the fetched cover and count when the next folder read is offline', async () => {
    await expect(getSavedFolderOptions()).resolves.toEqual([normalizedFolder]);
    expect(JSON.parse((await AsyncStorage.getItem(cacheKey)) || '[]')).toEqual([
      normalizedFolder,
    ]);

    api.get.mockRejectedValue(new Error('offline'));
    await expect(getSavedFolderOptions()).resolves.toEqual([normalizedFolder]);
    expect(api.get).toHaveBeenCalledTimes(2);
  });

  it('rejects an offline authoritative read even while a default read can use the cache', async () => {
    await getSavedFolderOptions();
    api.get.mockRejectedValue(new Error('offline'));

    const defaultRead = getSavedFolderOptions();
    const freshRead = getSavedFolderOptions({requireFresh: true});
    await expect(freshRead).rejects.toThrow('offline');
    await expect(defaultRead).resolves.toEqual([normalizedFolder]);
    expect(api.get).toHaveBeenCalledTimes(3);
  });

  it('keeps the fresh-only policy when a committed mutation invalidates its pending read', async () => {
    await getSavedFolderOptions();
    let resolveOld!: (value: ReturnType<typeof response>) => void;
    api.get.mockReturnValueOnce(
      new Promise(resolve => {
        resolveOld = resolve;
      }),
    );
    const oldRead = getSavedFolderOptions({requireFresh: true});
    for (let index = 0; index < 10; index += 1) await Promise.resolve();
    expect(api.get).toHaveBeenCalledTimes(2);
    await removeLessonFromSavedFolder('11', '7');
    api.get.mockRejectedValue(new Error('offline'));
    resolveOld(response([folder]));

    await expect(oldRead).rejects.toThrow('offline');
    expect(api.get).toHaveBeenCalledTimes(3);
    expect(api.delete).toHaveBeenCalledTimes(1);
  });

  it.each([
    'save-to-folder',
    'remove-from-folder',
    'watch-later',
    'unsave-everywhere',
  ])(
    'does not resurrect a known-stale cached count after confirmed %s',
    async mutation => {
      const sibling = {...folder, id: 8, name: 'قائمة أخرى', lessons_count: 2};
      api.get.mockResolvedValueOnce(response([folder, sibling]));
      await getSavedFolderOptions();
      await AsyncStorage.setItem('@rokn/watch-later-folder-id/v2:user-a', '7');
      if (mutation === 'save-to-folder' || mutation === 'watch-later') {
        api.post.mockResolvedValueOnce(
          response({is_saved: true, folder_id: 7, lesson_id: 11}),
        );
      }

      if (mutation === 'save-to-folder')
        await saveLessonToFolder('11', normalizedFolder);
      else if (mutation === 'remove-from-folder')
        await removeLessonFromSavedFolder('11', '7');
      else await toggleWatchLater('11', mutation === 'unsave-everywhere');
      api.get.mockRejectedValue(new Error('offline'));

      await expect(getSavedFolderOptions()).resolves.toEqual([
        {...normalizedFolder, lessonsCount: undefined},
        {
          ...normalizedFolder,
          id: '8',
          name: sibling.name,
          lessonsCount: mutation === 'unsave-everywhere' ? undefined : 2,
        },
      ]);
      api.get.mockResolvedValueOnce(response([{...folder, lessons_count: 5}]));
      await expect(
        getSavedFolderOptions({requireFresh: true}),
      ).resolves.toEqual([{...normalizedFolder, lessonsCount: 5}]);
    },
  );

  it('waits for a confirmed membership cache repair before serving an offline index', async () => {
    await getSavedFolderOptions();
    const storageWrite = deferred<void>();
    const writeStarted = deferred<void>();
    const implementation = jest
      .mocked(AsyncStorage.setItem)
      .getMockImplementation()!;
    jest
      .mocked(AsyncStorage.setItem)
      .mockImplementationOnce(async (key, value) => {
        writeStarted.resolve();
        await storageWrite.promise;
        await implementation(key, value);
      });
    const deletion = removeLessonFromSavedFolder('11', '7');
    await writeStarted.promise;
    api.get.mockRejectedValue(new Error('offline'));
    let delivered = false;
    const offline = getSavedFolderOptions().then(value => {
      delivered = true;
      return value;
    });
    try {
      await drain();
      expect(delivered).toBe(false);
    } finally {
      storageWrite.resolve();
      await deletion;
    }
    await expect(offline).resolves.toEqual([
      {...normalizedFolder, lessonsCount: undefined},
    ]);
  });

  it('preserves a known count when the server membership removal fails', async () => {
    await getSavedFolderOptions();
    api.delete.mockRejectedValueOnce(new Error('offline'));
    await expect(removeLessonFromSavedFolder('11', '7')).rejects.toThrow(
      'offline',
    );
    api.get.mockRejectedValue(new Error('offline'));
    await expect(getSavedFolderOptions()).resolves.toEqual([normalizedFolder]);
    expect(updatePlayerStateForScope).not.toHaveBeenCalled();
  });

  it.each(['save', 'remove', 'unsave'])(
    'keeps an acknowledged %s successful and repairs other caches if the count write fails',
    async operation => {
      await getSavedFolderOptions();
      jest
        .mocked(AsyncStorage.setItem)
        .mockRejectedValueOnce(new Error('disk-full'));
      if (operation === 'save') {
        api.post.mockResolvedValueOnce(
          response({is_saved: true, folder_id: 7, lesson_id: 11}),
        );
        await expect(saveLessonToFolder('11', normalizedFolder)).resolves.toBe(
          true,
        );
      } else if (operation === 'remove') {
        await expect(
          removeLessonFromSavedFolder('11', '7'),
        ).resolves.toBeUndefined();
        expect(removeSavedLessonFromCache).toHaveBeenCalledTimes(1);
      } else {
        await expect(toggleWatchLater('11', true)).resolves.toBe(false);
        expect(removeSavedLessonEverywhereFromCache).toHaveBeenCalledTimes(1);
      }
      expect(updatePlayerStateForScope).toHaveBeenCalledTimes(1);
      expect(
        operation === 'save' ? api.post : api.delete,
      ).toHaveBeenCalledTimes(1);
    },
  );

  it('retains both count invalidations when different folder removals overlap', async () => {
    api.get.mockResolvedValueOnce(
      response([
        folder,
        {...folder, id: 8, lessons_count: 2},
        {...folder, id: 9, lessons_count: 4},
      ]),
    );
    await getSavedFolderOptions();
    await Promise.all([
      removeLessonFromSavedFolder('11', '7'),
      removeLessonFromSavedFolder('22', '8'),
    ]);
    api.get.mockRejectedValue(new Error('offline'));
    await expect(getSavedFolderOptions()).resolves.toEqual([
      {...normalizedFolder, lessonsCount: undefined},
      {...normalizedFolder, id: '8', lessonsCount: undefined},
      {...normalizedFolder, id: '9', lessonsCount: 4},
    ]);
  });

  it.each([
    ['remove', 'setItem'],
    ['remove', 'getItem'],
    ['save', 'setItem'],
    ['fresh-read', 'setItem'],
    ['create', 'getItem'],
    ['delete', 'getItem'],
  ] as const)(
    'settles %s and rejects an offline snapshot while native index %s never settles',
    async (operation, method) => {
      await getSavedFolderOptions();
      jest.useFakeTimers();
      const releaseStorage = deferred<void>();
      const storageStarted = deferred<void>();
      if (method === 'setItem') {
        const implementation = jest
          .mocked(AsyncStorage.setItem)
          .getMockImplementation()!;
        jest
          .mocked(AsyncStorage.setItem)
          .mockImplementationOnce(async (key, value) => {
            storageStarted.resolve();
            await releaseStorage.promise;
            return implementation(key, value);
          });
      } else {
        const implementation = jest
          .mocked(AsyncStorage.getItem)
          .getMockImplementation()!;
        jest.mocked(AsyncStorage.getItem).mockImplementationOnce(async key => {
          storageStarted.resolve();
          await releaseStorage.promise;
          return implementation(key);
        });
      }
      if (operation === 'save') {
        api.post.mockResolvedValueOnce(
          response({is_saved: true, folder_id: 7, lesson_id: 11}),
        );
      } else if (operation === 'create') {
        api.post.mockResolvedValueOnce(
          response({id: 9, name: 'جديدة', lessons_count: 0}),
        );
      }
      const pending = observe<unknown>(
        operation === 'remove'
          ? removeLessonFromSavedFolder('11', '7')
          : operation === 'save'
          ? saveLessonToFolder('11', normalizedFolder)
          : operation === 'create'
          ? createSavedFolderOption('جديدة')
          : operation === 'delete'
          ? deleteSavedFolderOption('7')
          : getSavedFolderOptions({requireFresh: true}),
      );
      await storageStarted.promise;
      api.get.mockRejectedValue(new Error('offline'));
      const offline = observe(getSavedFolderOptions());
      try {
        await drain();
        jest.advanceTimersByTime(1000);
        await drain();
        expect(pending.result.settled).toBe(true);
        expect(pending.result.error).toBeUndefined();
        expect(offline.result.settled).toBe(true);
        expect(offline.result.error).toEqual(new Error('offline'));
        expect(offline.result.value).toBeUndefined();
        if (operation === 'remove') {
          expect(removeSavedLessonFromCache).toHaveBeenCalledTimes(1);
          expect(updatePlayerStateForScope).toHaveBeenCalledTimes(1);
        } else if (operation === 'delete') {
          expect(removeSavedFolderFromCache).toHaveBeenCalledTimes(1);
          expect(updatePlayerStateForScope).toHaveBeenCalledTimes(1);
        } else if (operation === 'save') {
          expect(updatePlayerStateForScope).toHaveBeenCalledTimes(1);
        } else if (operation === 'fresh-read') {
          expect(pending.result.value).toEqual([normalizedFolder]);
        }
      } finally {
        // The native call remains unresolved throughout the assertions. Release
        // it only to leave the real module's raw queue clean for the next case.
        releaseStorage.resolve();
        await Promise.all([pending.promise, offline.promise]);
        await drain();
        jest.useRealTimers();
      }
    },
  );

  it('bounds the raw offline index read even when no write queue is pending', async () => {
    await getSavedFolderOptions();
    jest.useFakeTimers();
    const nativeRead = deferred<string | null>();
    jest.mocked(AsyncStorage.getItem).mockReturnValueOnce(nativeRead.promise);
    api.get.mockRejectedValue(new Error('offline'));
    const offline = observe(getSavedFolderOptions());
    try {
      await drain();
      jest.advanceTimersByTime(1000);
      await drain();
      expect(offline.result.settled).toBe(true);
      expect(offline.result.error).toEqual(new Error('offline'));
    } finally {
      nativeRead.resolve(JSON.stringify([normalizedFolder]));
      await offline.promise;
      jest.useRealTimers();
    }
  });

  it('keeps timed-out native writes ordered before the mutation repair and later fresh snapshot', async () => {
    await getSavedFolderOptions();
    jest.useFakeTimers();
    const releaseStorage = deferred<void>();
    const storageStarted = deferred<void>();
    const implementation = jest
      .mocked(AsyncStorage.setItem)
      .getMockImplementation()!;
    jest
      .mocked(AsyncStorage.setItem)
      .mockImplementationOnce(async (key, value) => {
        storageStarted.resolve();
        await releaseStorage.promise;
        return implementation(key, value);
      });
    const oldRead = observe(getSavedFolderOptions({requireFresh: true}));
    try {
      await storageStarted.promise;
      jest.advanceTimersByTime(1000);
      await drain();
      expect(oldRead.result.settled).toBe(true);

      const removal = observe(removeLessonFromSavedFolder('11', '7'));
      await drain();
      jest.advanceTimersByTime(1000);
      await drain();
      expect(removal.result.settled).toBe(true);
      expect(removal.result.error).toBeUndefined();

      api.get.mockResolvedValueOnce(response([{...folder, lessons_count: 5}]));
      const freshRead = observe(getSavedFolderOptions({requireFresh: true}));
      await drain();
      jest.advanceTimersByTime(1000);
      await drain();
      expect(freshRead.result.value).toEqual([
        {...normalizedFolder, lessonsCount: 5},
      ]);
      // None of those completed caller waits released the actual queue tail.
      releaseStorage.resolve();
      await drain();
      api.get.mockRejectedValue(new Error('offline'));
      await expect(getSavedFolderOptions()).resolves.toEqual([
        {...normalizedFolder, lessonsCount: 5},
      ]);
      expect(api.delete).toHaveBeenCalledTimes(1);
    } finally {
      releaseStorage.resolve();
      await drain();
      jest.useRealTimers();
    }
  });

  it('keeps sibling metadata through queued create and delete cache repairs', async () => {
    api.get.mockResolvedValueOnce(
      response([folder, {id: 8, name: 'مؤقت', lessons_count: 0}]),
    );
    await getSavedFolderOptions();
    api.post.mockResolvedValueOnce(
      response({id: 9, name: 'جديد', lessons_count: 0}),
    );

    await Promise.all([
      createSavedFolderOption('جديد'),
      deleteSavedFolderOption('8'),
    ]);
    const expected = [
      normalizedFolder,
      {id: '9', name: 'جديد', imageUrl: undefined, lessonsCount: 0},
    ];
    expect(JSON.parse((await AsyncStorage.getItem(cacheKey)) || '[]')).toEqual(
      expected,
    );
    api.get.mockRejectedValue(new Error('offline'));
    await expect(getSavedFolderOptions()).resolves.toEqual(expected);
  });

  it.each([
    ['normalized', {...normalizedFolder, lessonsCount: 0}],
    ['legacy server-shaped', {...folder, lessons_count: 0}],
  ])(
    'retains metadata and zero counts in an existing %s cache',
    async (_, cached) => {
      await AsyncStorage.setItem(cacheKey, JSON.stringify([cached]));
      api.get.mockRejectedValue(new Error('offline'));

      await expect(getSavedFolderOptions()).resolves.toEqual([
        {...normalizedFolder, lessonsCount: 0},
      ]);
    },
  );

  it('keeps folders without optional metadata readable without inventing a count', async () => {
    await AsyncStorage.setItem(
      cacheKey,
      JSON.stringify([{id: '7', name: folder.name}]),
    );
    api.get.mockRejectedValue(new Error('offline'));

    await expect(getSavedFolderOptions()).resolves.toEqual([
      {
        id: '7',
        name: folder.name,
        imageUrl: undefined,
        lessonsCount: undefined,
      },
    ]);
  });

  it('does not return the former account cache after an in-flight account change', async () => {
    await getSavedFolderOptions();
    api.get.mockImplementationOnce(async () => {
      mockBoundary = {scope: 'user-b', epoch: mockBoundary.epoch + 1};
      throw new Error('offline');
    });

    await expect(getSavedFolderOptions()).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    api.get.mockRejectedValue(new Error('offline'));
    await expect(getSavedFolderOptions()).rejects.toThrow('offline');
  });
});
