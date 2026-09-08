import AsyncStorage from '@react-native-async-storage/async-storage';

const mockGet = jest.fn();
let mockAccount = {scope: 'user-a', epoch: 1};
const mockDelete = jest.fn(async (..._args: unknown[]) => ({
  data: {success: true},
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    delete: (...args: unknown[]) => mockDelete(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string, owner = mockAccount) =>
    `${key}:${owner.scope}`,
  assertAccountSessionBoundary: (owner: typeof mockAccount) => {
    if (owner.scope !== mockAccount.scope || owner.epoch !== mockAccount.epoch)
      throw new Error('ACCOUNT_CHANGED');
  },
  captureAccountSessionBoundary: async () => ({...mockAccount}),
}));

import {
  deleteSavedLesson,
  getSavedLessonsPage,
  getSavedFolderLessonsPage,
  removeSavedFolderFromCache,
  removeSavedLessonFromCache,
  type SavedLesson,
} from '../src/services/api/savedLessons';

const key = '@rokn/saved-lessons/v2:user-a';
const originalSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const lesson = (id: string, folderId: string): SavedLesson => ({
  id,
  folderId,
  folderName: `قائمة ${folderId}`,
  courseId: '9',
  title: `مقطع ${id}`,
  courseTitle: 'الكورس',
  duration: '02:00',
});
const response = (ids: number[], folderId?: number, page = 1) => ({
  data: {
    data: {
      ...(folderId ? {folder: {id: folderId, name: `قائمة ${folderId}`}} : {}),
      lessons: ids.map(id => ({
        id,
        title: 'المقطع',
        duration_seconds: 120,
        course: {id: 9, title: 'الكورس'},
        ...(folderId ? {} : {folder_memberships: [{id: 1, name: 'قائمة 1'}]}),
      })),
      pagination: {current_page: page, last_page: page, total: ids.length},
    },
  },
});
const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => {
    resolve = yes;
  });
  return {promise, resolve};
};
const drain = async () => {
  for (let i = 0; i < 30; i += 1) await Promise.resolve();
};
const seed = (lessons: SavedLesson[]) =>
  AsyncStorage.setItem(
    key,
    JSON.stringify({
      version: 2,
      savedAt: Date.now(),
      lessons,
    }),
  );
const offlineIds = async () => {
  mockGet.mockRejectedValueOnce(new Error('offline'));
  const result = await getSavedLessonsPage();
  expect(result.fromCache).toBe(true);
  return result.lessons.map(item => item.id);
};

describe('saved lesson cache mutation ordering', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    mockGet.mockReset();
    mockDelete.mockReset().mockResolvedValue({data: {success: true}});
    mockAccount = {scope: 'user-a', epoch: mockAccount.epoch + 1};
    jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
    await AsyncStorage.clear();
  });
  afterEach(() => jest.restoreAllMocks());

  it('keeps both acknowledged removals when their cache repairs overlap', async () => {
    await seed([lesson('11', '1'), lesson('22', '2'), lesson('33', '3')]);
    const firstWrite = deferred<void>();
    const writeStarted = deferred<void>();
    let delayed = false;
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementation(async (storageKey, value) => {
        if (storageKey === key && !delayed) {
          delayed = true;
          writeStarted.resolve();
          await firstWrite.promise;
        }
        return originalSet(storageKey, value);
      });
    const removeFirst = removeSavedLessonFromCache('1', '11');
    await writeStarted.promise;
    const removeSecondFolder = removeSavedFolderFromCache('2');
    await drain();
    firstWrite.resolve();
    await Promise.all([removeFirst, removeSecondFolder]);

    expect(await offlineIds()).toEqual(['33']);
  });

  it('does not resurrect an acknowledged deletion when a pre-deletion page read arrives late', async () => {
    await seed([lesson('11', '1')]);
    const oldRead = deferred<unknown>();
    const readStarted = deferred<void>();
    mockGet.mockImplementationOnce(() => {
      readStarted.resolve();
      return oldRead.promise;
    });
    const pageRead = getSavedLessonsPage();
    await readStarted.promise;
    await deleteSavedLesson('1', '11');
    expect(mockDelete).toHaveBeenCalledWith('saved-folders/1/lessons/11');
    expect(JSON.parse((await AsyncStorage.getItem(key))!).lessons).toEqual([]);
    mockGet.mockResolvedValueOnce(response([]));
    oldRead.resolve(response([11]));
    expect((await pageRead).lessons).toEqual([]);
    expect(mockGet).toHaveBeenCalledTimes(2);
    await drain();

    expect(await offlineIds()).toEqual([]);
  });

  it('refreshes the same selected-folder page when an acknowledged removal overtakes it', async () => {
    const oldRead = deferred<unknown>();
    mockGet.mockReturnValueOnce(oldRead.promise);
    const pageRead = getSavedFolderLessonsPage('2', 3, 10);
    await drain();
    await deleteSavedLesson('2', '22');
    mockGet.mockResolvedValueOnce(response([], 2, 3));
    oldRead.resolve(response([22], 2, 3));

    expect(await pageRead).toMatchObject({
      lessons: [],
      page: 3,
      fromCache: false,
    });
    expect(mockGet).toHaveBeenCalledTimes(2);
    for (const call of mockGet.mock.calls)
      expect(call).toEqual([
        'saved-folders/2/lessons',
        {params: {page: 3, per_page: 10}},
      ]);
  });

  it('repairs after a page cache write already in progress without resurrecting its removed row', async () => {
    await seed([lesson('11', '1')]);
    const oldWrite = deferred<void>();
    const writeStarted = deferred<void>();
    let delayed = false;
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementation(async (storageKey, value) => {
        if (storageKey === key && !delayed) {
          delayed = true;
          writeStarted.resolve();
          await oldWrite.promise;
        }
        return originalSet(storageKey, value);
      });
    mockGet.mockResolvedValueOnce(response([11]));
    await getSavedLessonsPage();
    await writeStarted.promise;
    const removal = deleteSavedLesson('1', '11');
    await drain();
    oldWrite.resolve();
    await removal;

    expect(await offlineIds()).toEqual([]);
  });

  it('uses repaired offline data if the post-mutation refresh fails instead of the stale response', async () => {
    await seed([lesson('11', '1')]);
    const oldRead = deferred<unknown>();
    mockGet.mockReturnValueOnce(oldRead.promise);
    const pageRead = getSavedLessonsPage();
    await drain();
    await deleteSavedLesson('1', '11');
    mockGet.mockRejectedValueOnce(new Error('offline'));
    oldRead.resolve(response([11]));

    expect(await pageRead).toMatchObject({lessons: [], fromCache: true});
    expect(mockGet).toHaveBeenCalledTimes(2);
    expect(mockDelete).toHaveBeenCalledTimes(1);
  });

  it('does not retry or filter a page when the server rejected the deletion', async () => {
    const oldRead = deferred<unknown>();
    mockGet.mockReturnValueOnce(oldRead.promise);
    const pageRead = getSavedLessonsPage();
    await drain();
    mockDelete.mockRejectedValueOnce(new Error('DELETE_REJECTED'));
    await expect(deleteSavedLesson('1', '11')).rejects.toThrow(
      'DELETE_REJECTED',
    );
    oldRead.resolve(response([11]));

    expect((await pageRead).lessons.map(item => item.id)).toEqual(['11']);
    expect(mockGet).toHaveBeenCalledTimes(1);
    await drain();
  });

  it('does not restore or retry the old account response after a session switch', async () => {
    const oldRead = deferred<unknown>();
    mockGet.mockReturnValueOnce(oldRead.promise);
    const pageRead = getSavedFolderLessonsPage('2');
    await drain();
    await deleteSavedLesson('2', '22');
    mockAccount = {scope: 'user-b', epoch: mockAccount.epoch + 1};
    oldRead.resolve(response([22], 2));

    await expect(pageRead).rejects.toThrow('ACCOUNT_CHANGED');
    expect(mockGet).toHaveBeenCalledTimes(1);
  });
});
