const mockRetain = jest.fn(async () => undefined);
const mockRemoveFile = jest.fn(async () => undefined);

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (key: string, boundary: {scope: string}) =>
      `${key}:${boundary.scope}`,
  ),
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'user-a',
  })),
}));

jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: (...args: unknown[]) =>
    (mockRemoveFile as (...values: unknown[]) => unknown)(...args),
  retainLearnerDraftFiles: (...args: unknown[]) =>
    (mockRetain as (...values: unknown[]) => unknown)(...args),
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  completePortfolioMediaUpload,
  listPortfolioMediaUploads,
  stagePortfolioMediaUpload,
  setPortfolioMediaUploadsPaused,
  portfolioMediaUploadIsPaused,
  decodePortfolioMediaOutboxEntries,
} from '../src/services/portfolioMediaOutbox';

const owner = {epoch: 1, scope: 'user-a'};
const entry = {
  projectId: '42',
  clientRequestId: '11111111-1111-4111-8111-111111111111',
  file: {uri: 'file:///portfolio/one.jpg'},
  createdAt: Date.now(),
};

describe('portfolio media outbox durability', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
  });

  it('keeps the file protected when durable completion cannot be written', async () => {
    const staged = await stagePortfolioMediaUpload(entry, owner);
    mockRetain.mockClear();
    const remove = AsyncStorage.removeItem as jest.MockedFunction<
      typeof AsyncStorage.removeItem
    >;
    remove.mockRejectedValueOnce(new Error('STORAGE_FULL'));

    await expect(
      completePortfolioMediaUpload(staged, owner),
    ).resolves.toBeUndefined();

    expect(mockRetain).not.toHaveBeenCalled();
    expect(mockRemoveFile).not.toHaveBeenCalled();
    await expect(listPortfolioMediaUploads(undefined, owner)).resolves.toEqual([
      expect.objectContaining({clientRequestId: entry.clientRequestId}),
    ]);
  });

  it('restores previous file references if staging a replacement cannot commit', async () => {
    await stagePortfolioMediaUpload(entry, owner);
    mockRetain.mockClear();
    const write = AsyncStorage.setItem as jest.MockedFunction<
      typeof AsyncStorage.setItem
    >;
    write.mockRejectedValueOnce(new Error('STORAGE_FULL'));
    const replacement = {
      ...entry,
      file: {uri: 'file:///portfolio/two.jpg'},
    };

    await expect(stagePortfolioMediaUpload(replacement, owner)).rejects.toThrow(
      'STORAGE_FULL',
    );
    expect(mockRetain).toHaveBeenLastCalledWith(
      'portfolio-media-outbox',
      [entry.file],
      'user-a',
    );
    await expect(listPortfolioMediaUploads(undefined, owner)).resolves.toEqual([
      expect.objectContaining({file: entry.file}),
    ]);
  });

  it('retains paused files across reads and sibling staging until explicit resume', async () => {
    const staged = await stagePortfolioMediaUpload(entry, owner);
    const sibling = {...entry, clientRequestId: '22222222-2222-4222-8222-222222222222', file: {uri: 'file:///portfolio/two.jpg'}};
    await setPortfolioMediaUploadsPaused('42', true, owner);
    await stagePortfolioMediaUpload(sibling, owner);
    await expect(portfolioMediaUploadIsPaused(staged, owner)).resolves.toBe(true);
    const reloaded = await listPortfolioMediaUploads('42', owner);
    expect(reloaded).toHaveLength(2);
    expect(reloaded.every(item => item.paused === true)).toBe(true);
    expect(mockRemoveFile).not.toHaveBeenCalled();
    await setPortfolioMediaUploadsPaused('42', false, owner);
    await expect(portfolioMediaUploadIsPaused(staged, owner)).resolves.toBe(false);
    expect((await listPortfolioMediaUploads('42', owner)).every(item => item.paused === false)).toBe(true);
  });

  it('keeps old records active, rejects corrupt pause values and never pauses another account', async () => {
    expect(decodePortfolioMediaOutboxEntries([entry])).toEqual([entry]);
    expect(decodePortfolioMediaOutboxEntries([{...entry, paused: 'true'}])).toBeNull();
    const other = {epoch: 2, scope: 'user-b'};
    const first = await stagePortfolioMediaUpload(entry, owner);
    const second = await stagePortfolioMediaUpload(entry, other);
    await setPortfolioMediaUploadsPaused('42', true, owner);
    await expect(portfolioMediaUploadIsPaused(first, owner)).resolves.toBe(true);
    await expect(portfolioMediaUploadIsPaused(second, other)).resolves.toBe(false);
  });
});
