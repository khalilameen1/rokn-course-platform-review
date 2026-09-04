import AsyncStorage from '@react-native-async-storage/async-storage';

let mockPlayerState = {
  savedLessons: [] as string[],
  savedFolderLessons: {} as Record<string, string[]>,
};
let mockAccountBoundary = {epoch: 1, scope: 'user-a'};

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: jest.fn(),
    post: jest.fn(),
    delete: jest.fn(),
  },
}));

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (key: string, boundary?: {scope: string}) =>
      `${key}:${boundary?.scope ?? 'user-a'}`,
  ),
  assertAccountSessionBoundary: jest.fn((boundary: {epoch: number}) => {
    if (boundary.epoch !== mockAccountBoundary.epoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  }),
  captureAccountSessionBoundary: jest.fn(async () => ({
    ...mockAccountBoundary,
  })),
  getCurrentAccountStorageScope: jest.fn(async () => 'user-a'),
}));

jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(),
  removeSavedFolderFromCache: jest.fn(),
  removeSavedLessonEverywhereFromCache: jest.fn(),
  removeSavedLessonFromCache: jest.fn(),
}));

jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));

jest.mock('../src/components/VideoPlayer/courseLearning/persistence', () => ({
  readPlayerState: jest.fn(async () => mockPlayerState),
  readPlayerStateForScope: jest.fn(async () => mockPlayerState),
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

import {
  createSavedFolderOption,
  deleteSavedFolderOption,
  getSavedFolderOptions,
  reconcileServerSavedLessons,
  saveLessonToFolder,
  toggleWatchLater,
} from '../src/components/VideoPlayer/courseLearning/savedCollections';
import {publicRequest} from '../src/constants/api';
import {hasSession} from '../src/services/roknApi';

const mockPublicRequest = publicRequest as jest.Mocked<typeof publicRequest>;
const mockHasSession = hasSession as jest.MockedFunction<typeof hasSession>;

describe('saved collection daily reliability', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    mockHasSession.mockResolvedValue(true);
    mockAccountBoundary = {epoch: 1, scope: 'user-a'};
    mockPlayerState = {savedLessons: [], savedFolderLessons: {}};
    mockPublicRequest.get.mockResolvedValue({data: {data: []}});
    mockPublicRequest.delete.mockResolvedValue({data: {}});
  });

  it('coalesces the same folder creation across concurrent surfaces', async () => {
    mockPublicRequest.post.mockResolvedValueOnce({
      data: {data: {id: 7, name: 'للمراجعة', lessons_count: 0}},
    });

    const first = createSavedFolderOption(' للمراجعة ');
    const duplicate = createSavedFolderOption('للمراجعة');
    await expect(Promise.all([first, duplicate])).resolves.toEqual([
      expect.objectContaining({id: '7', name: 'للمراجعة'}),
      expect.objectContaining({id: '7', name: 'للمراجعة'}),
    ]);
    expect(mockPublicRequest.post).toHaveBeenCalledTimes(1);
  });

  it('sends one membership write when the save button is tapped twice', async () => {
    mockPublicRequest.post.mockResolvedValueOnce({
      data: {
        data: {is_saved: true, folder_id: 7, lesson_id: 44},
      },
    });

    const folder = {id: '7', name: 'للمراجعة'};
    const first = saveLessonToFolder('44', folder);
    const duplicate = saveLessonToFolder('44', folder);
    await expect(Promise.all([first, duplicate])).resolves.toEqual([
      true,
      true,
    ]);
    expect(mockPublicRequest.post).toHaveBeenCalledTimes(1);
    expect(mockPublicRequest.post).toHaveBeenCalledWith(
      'saved-folders/7/lessons',
      {lesson_id: '44'},
    );
    expect(mockPlayerState.savedLessons).toEqual(['44']);
    expect(mockPlayerState.savedFolderLessons).toEqual({'7': ['44']});
  });

  it('deleting one folder keeps the lesson saved in its other folder', async () => {
    mockPlayerState = {
      savedLessons: ['44'],
      savedFolderLessons: {'7': ['44'], '8': ['44']},
    };
    await AsyncStorage.setItem(
      '@rokn/saved-folder-options/v1:user-a',
      JSON.stringify([
        {id: '7', name: 'المشاهدة لاحقًا'},
        {id: '8', name: 'للمراجعة'},
      ]),
    );
    await AsyncStorage.setItem('@rokn/watch-later-folder-id/v2:user-a', '7');

    await deleteSavedFolderOption('7');

    expect(mockPublicRequest.delete).toHaveBeenCalledWith('saved-folders/7');
    expect(mockPlayerState.savedLessons).toEqual(['44']);
    expect(mockPlayerState.savedFolderLessons).toEqual({'8': ['44']});
    await expect(
      AsyncStorage.getItem('@rokn/watch-later-folder-id/v2:user-a'),
    ).resolves.toBeNull();
  });

  it('does not create a second device-only library after the session ends', async () => {
    mockHasSession.mockResolvedValue(false);

    await expect(createSavedFolderOption('للمراجعة')).rejects.toThrow(
      'SAVED_COLLECTIONS_AUTH_REQUIRED',
    );
    expect(mockPublicRequest.post).not.toHaveBeenCalled();
    await expect(
      AsyncStorage.getItem('@rokn/saved-folder-options/v1:user-a'),
    ).resolves.toBeNull();
  });

  it('rejects a legacy nested folder envelope instead of caching it', async () => {
    mockPublicRequest.get.mockResolvedValueOnce({
      data: {data: {data: [{id: 7, name: 'قديمة'}]}},
    });

    await expect(getSavedFolderOptions()).rejects.toThrow(
      'INVALID_SAVED_FOLDERS_RESPONSE',
    );
  });

  it('does not join a request owned by an earlier session epoch', async () => {
    const resolvers: Array<(value: unknown) => void> = [];
    mockPublicRequest.post.mockImplementation(
      () =>
        new Promise(resolve => {
          resolvers.push(resolve);
        }),
    );

    const first = createSavedFolderOption('للمراجعة');
    await new Promise(resolve => setImmediate(resolve));
    mockAccountBoundary = {epoch: 2, scope: 'user-a'};
    const second = createSavedFolderOption('للمراجعة');
    await new Promise(resolve => setImmediate(resolve));

    expect(mockPublicRequest.post).toHaveBeenCalledTimes(2);
    resolvers.forEach((resolve, index) =>
      resolve({data: {data: {id: index + 7, name: 'للمراجعة'}}}),
    );
    await expect(Promise.allSettled([first, second])).resolves.toEqual([
      expect.objectContaining({status: 'rejected'}),
      expect.objectContaining({status: 'fulfilled'}),
    ]);
  });

  it('recreates a deleted watch-later folder once and completes the save', async () => {
    await AsyncStorage.setItem('@rokn/watch-later-folder-id/v2:user-a', '7');
    mockPublicRequest.get.mockResolvedValueOnce({data: {data: []}});
    mockPublicRequest.post
      .mockRejectedValueOnce({response: {status: 404}})
      .mockResolvedValueOnce({
        data: {data: {id: 9, name: 'المشاهدة لاحقًا'}},
      })
      .mockResolvedValueOnce({
        data: {
          data: {is_saved: true, folder_id: 9, lesson_id: 44},
        },
      });

    await expect(toggleWatchLater('44', false)).resolves.toBe(true);
    expect(mockPublicRequest.post).toHaveBeenNthCalledWith(
      3,
      'saved-folders/9/lessons',
      {lesson_id: '44'},
    );
    expect(mockPlayerState.savedFolderLessons).toEqual({'9': ['44']});
  });

  it('does not clear local icons when saved-state reconciliation is malformed', async () => {
    mockPlayerState = {
      savedLessons: ['44'],
      savedFolderLessons: {'7': ['44']},
    };
    mockPublicRequest.get.mockResolvedValueOnce({
      data: {data: {saved_lesson_ids: {data: [44]}}},
    });

    await expect(reconcileServerSavedLessons(['44'])).rejects.toThrow(
      'SAVED_LESSON_STATE_CONTRACT_INVALID',
    );
    expect(mockPlayerState).toEqual({
      savedLessons: ['44'],
      savedFolderLessons: {'7': ['44']},
    });
  });
});
