import AsyncStorage from '@react-native-async-storage/async-storage';

let mockBoundary = {scope: 'index-owner', epoch: 0};
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({...mockBoundary})),
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
}));
jest.mock(
  '../src/components/VideoPlayer/courseLearning/savedCollections',
  () => {
    throw new Error('Folder index must not load mutation orchestration');
  },
);
jest.mock(
  '../src/components/VideoPlayer/courseLearning/watchLaterFolder',
  () => {
    throw new Error('Folder index must not load default-folder creation');
  },
);
jest.mock('../src/components/VideoPlayer/courseLearning/persistence', () => {
  throw new Error('Folder index must not load the player cache');
});

import {publicRequest} from '../src/constants/api';
import {
  cacheCreatedSavedFolder,
  cacheDeletedSavedFolder,
  getSavedFolderOptions,
  invalidateFolderList,
  invalidateMembershipCounts,
  savedFolderRevision,
} from '../src/components/VideoPlayer/courseLearning/savedFolderIndex';

const get = jest.mocked(publicRequest.get);
const response = (data: unknown) => ({data: {data}});
const folder = {id: '7', name: 'قائمة', lessons_count: 2};

beforeEach(async () => {
  jest.clearAllMocks();
  mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
  await AsyncStorage.clear();
  get.mockResolvedValue(response([folder]));
});

it('reads and caches folders without depending on mutation or player owners', async () => {
  const expected = [
    {id: '7', name: 'قائمة', lessonsCount: 2, imageUrl: undefined},
  ];
  await expect(getSavedFolderOptions()).resolves.toEqual(expected);
  get.mockRejectedValueOnce(new Error('offline'));
  await expect(getSavedFolderOptions()).resolves.toEqual(expected);
});

it('owns read invalidation and retries an overtaken snapshot against the new revision', async () => {
  let resolveOld!: (value: ReturnType<typeof response>) => void;
  let markStarted!: () => void;
  const started = new Promise<void>(resolve => {
    markStarted = resolve;
  });
  get.mockImplementationOnce(() => {
    markStarted();
    return new Promise(resolve => {
      resolveOld = resolve;
    });
  });
  const pending = getSavedFolderOptions({requireFresh: true});
  await started;
  const before = savedFolderRevision(mockBoundary);
  invalidateFolderList(mockBoundary);
  expect(savedFolderRevision(mockBoundary)).toBe(before + 1);
  get.mockResolvedValueOnce(response([{...folder, lessons_count: 1}]));
  resolveOld(response([folder]));
  await expect(pending).resolves.toEqual([
    expect.objectContaining({id: '7', lessonsCount: 1}),
  ]);
  expect(get).toHaveBeenCalledTimes(2);
});

it('repairs only the acknowledged folder and does not fetch or guess the new count', async () => {
  get.mockResolvedValueOnce(
    response([folder, {...folder, id: '8', lessons_count: 5}]),
  );
  await getSavedFolderOptions();
  await invalidateMembershipCounts(mockBoundary, '7');
  await cacheCreatedSavedFolder(mockBoundary, {
    id: '9',
    name: 'جديدة',
    lessonsCount: 0,
  });
  await cacheDeletedSavedFolder(mockBoundary, '9');
  expect(get).toHaveBeenCalledTimes(1);
  get.mockRejectedValueOnce(new Error('offline'));
  await expect(getSavedFolderOptions()).resolves.toEqual([
    expect.objectContaining({id: '7', lessonsCount: undefined}),
    expect.objectContaining({id: '8', lessonsCount: 5}),
  ]);
});

it('keeps strict response validation and fresh-only reads from falling back to a cache', async () => {
  await getSavedFolderOptions();
  get.mockResolvedValueOnce(response([{id: 'bad', name: 'قائمة'}]));
  await expect(getSavedFolderOptions()).rejects.toThrow(
    'INVALID_SAVED_FOLDERS_RESPONSE',
  );
  get.mockRejectedValueOnce(new Error('offline'));
  await expect(getSavedFolderOptions({requireFresh: true})).rejects.toThrow(
    'offline',
  );
});
