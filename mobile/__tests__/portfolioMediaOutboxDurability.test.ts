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

const mockAppend = jest.fn();
jest.mock('../src/services/api/profile', () => ({
  appendPortfolioMedia: (...args: unknown[]) =>
    (mockAppend as (...values: unknown[]) => unknown)(...args),
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
  listPortfolioMediaUploads,
  stagePortfolioMediaUpload,
  setPortfolioMediaUploadsPaused,
  decodePortfolioMediaOutboxEntries,
} from '../src/services/portfolioMediaOutbox';
import {
  deliverPortfolioMedia,
  replayPendingPortfolioMediaUploads,
  resetPortfolioMediaDeliveryForTests,
} from '../src/services/portfolioMediaDelivery';
import {resetDurableOutboxForTests} from '../src/services/durableOutbox';

const isPaused = async (boundary: {epoch: number; scope: string}) =>
  (await listPortfolioMediaUploads('42', boundary)).some(
    item => item.paused === true,
  );

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
    resetDurableOutboxForTests();
    resetPortfolioMediaDeliveryForTests();
  });

  it('keeps the file protected when durable completion cannot be written', async () => {
    const staged = await stagePortfolioMediaUpload(entry, owner);
    mockRetain.mockClear();
    const remove = AsyncStorage.removeItem as jest.MockedFunction<
      typeof AsyncStorage.removeItem
    >;
    remove.mockRejectedValueOnce(new Error('STORAGE_FULL'));
    mockAppend.mockResolvedValueOnce({id: '9', type: 'image', status: 'ready'});

    // The server accepted the file; a failed local retirement cannot turn it
    // into a failed upload, and the same id stays for an idempotent replay.
    await expect(deliverPortfolioMedia(staged, owner)).resolves.toMatchObject(
      {state: 'uploaded'},
    );

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
    await expect(isPaused(owner)).resolves.toBe(true);
    const reloaded = await listPortfolioMediaUploads('42', owner);
    expect(reloaded).toHaveLength(2);
    expect(reloaded.every(item => item.paused === true)).toBe(true);
    expect(mockRemoveFile).not.toHaveBeenCalled();
    await setPortfolioMediaUploadsPaused('42', false, owner);
    await expect(isPaused(owner)).resolves.toBe(false);
    expect(staged.clientRequestId).toBe(entry.clientRequestId);
    expect((await listPortfolioMediaUploads('42', owner)).every(item => item.paused === false)).toBe(true);
  });

  it('keeps old records active, rejects corrupt pause values and never pauses another account', async () => {
    expect(decodePortfolioMediaOutboxEntries([entry])).toEqual([entry]);
    expect(decodePortfolioMediaOutboxEntries([{...entry, paused: 'true'}])).toBeNull();
    const other = {epoch: 2, scope: 'user-b'};
    await stagePortfolioMediaUpload(entry, owner);
    await stagePortfolioMediaUpload(entry, other);
    await setPortfolioMediaUploadsPaused('42', true, owner);
    await expect(isPaused(owner)).resolves.toBe(true);
    await expect(isPaused(other)).resolves.toBe(false);
  });

  it('migrates a v1 queue on first read without losing a paused learner file', async () => {
    const sibling = {
      ...entry,
      clientRequestId: '22222222-2222-4222-8222-222222222222',
      file: {uri: 'file:///portfolio/two.jpg'},
      createdAt: entry.createdAt + 1,
    };
    await AsyncStorage.setItem(
      '@rokn/portfolio-media-outbox/v1:user-a',
      JSON.stringify([{...sibling, paused: true}, {...entry, paused: true}]),
    );

    const migrated = await listPortfolioMediaUploads('42', owner);

    expect(migrated.map(item => [item.clientRequestId, item.paused])).toEqual([
      [entry.clientRequestId, true],
      [sibling.clientRequestId, true],
    ]);
    expect(
      await AsyncStorage.getItem('@rokn/portfolio-media-outbox/v1:user-a'),
    ).toBeNull();
    expect(
      JSON.parse(
        (await AsyncStorage.getItem('@rokn/portfolio-media-outbox/v2:user-a'))!,
      ),
    ).toHaveLength(2);
    expect(mockRetain).toHaveBeenCalledWith(
      'portfolio-media-outbox',
      expect.arrayContaining([entry.file, sibling.file]),
      'user-a',
    );
    expect(mockRemoveFile).not.toHaveBeenCalled();

    // The pause survives the migration; resuming delivers both in order.
    await setPortfolioMediaUploadsPaused('42', false, owner);
    mockAppend.mockResolvedValue({id: '9', type: 'image', status: 'ready'});
    await expect(replayPendingPortfolioMediaUploads()).resolves.toMatchObject({
      attempted: 2,
      completed: 2,
      completedProjectIds: ['42'],
    });
    expect(mockAppend.mock.calls.map(call => call[2])).toEqual([
      entry.clientRequestId,
      sibling.clientRequestId,
    ]);
    expect(await listPortfolioMediaUploads(undefined, owner)).toEqual([]);
  });
});
