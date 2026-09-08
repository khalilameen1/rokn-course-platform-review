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
  createSavedFolderOption,
  deleteSavedFolderOption,
  getSavedFolderOptions,
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
  ])('retains metadata and zero counts in an existing %s cache', async (_, cached) => {
    await AsyncStorage.setItem(cacheKey, JSON.stringify([cached]));
    api.get.mockRejectedValue(new Error('offline'));

    await expect(getSavedFolderOptions()).resolves.toEqual([
      {...normalizedFolder, lessonsCount: 0},
    ]);
  });

  it('keeps folders without optional metadata readable without inventing a count', async () => {
    await AsyncStorage.setItem(
      cacheKey,
      JSON.stringify([{id: '7', name: folder.name}]),
    );
    api.get.mockRejectedValue(new Error('offline'));

    await expect(getSavedFolderOptions()).resolves.toEqual([
      {id: '7', name: folder.name, imageUrl: undefined, lessonsCount: undefined},
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
