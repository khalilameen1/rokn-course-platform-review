import type {Notification} from '../src/services/notificationMapper';

const mockRead = jest.fn();
const mockSave = jest.fn();
let mockBoundary = {scope: 'account-a', epoch: 1};
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${key}:${boundary.scope}`,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  getItem: (...args: unknown[]) => mockRead(...args),
  saveItem: (...args: unknown[]) => mockSave(...args),
}));

import {
  notificationCacheKey,
  readCachedNotifications,
  saveCachedNotifications,
} from '../src/screens/notifications/cache';

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const flush = () => new Promise<void>(done => setImmediate(done));
const notification = (id: string): Notification => ({
  id,
  type: 'admin_message',
  kind: 'account_update',
  title: 'رسالة من ركن',
  description: 'تفاصيل الرسالة',
  actionLabel: '',
  createdAt: '2026-09-09T08:00:00.000Z',
  tone: 'learning',
  read: false,
});
type Boundary = typeof mockBoundary;
const owners = [
  {
    name: 'notifications',
    save: async (boundary: Boundary, id: string) =>
      saveCachedNotifications(
        await notificationCacheKey(boundary),
        [notification(id)],
        boundary,
      ),
    read: async (boundary: Boundary) =>
      (
        await readCachedNotifications(
          await notificationCacheKey(boundary),
          boundary,
        )
      ).map(item => item.id),
  },
];
const disk = new Map<string, unknown>();

beforeEach(() => {
  jest.clearAllMocks();
  mockBoundary = {scope: 'account-a', epoch: mockBoundary.epoch + 1};
  disk.clear();
  mockRead.mockImplementation(async (key: string) => disk.get(key) ?? null);
  mockSave.mockImplementation(async (key: string, value: unknown) => {
    disk.set(key, value);
    return true;
  });
});

describe.each(owners)('$name cache ownership', owner => {
  it('persists and recovers a new account while the previous native write is suspended', async () => {
    const gate = deferred<void>();
    mockSave.mockImplementationOnce(async (key: string, value: unknown) => {
      await gate.promise;
      disk.set(key, value);
      return true;
    });
    const first = owner.save({...mockBoundary}, '1').catch(error => error);
    await flush();
    expect(mockSave).toHaveBeenCalledTimes(1);
    mockBoundary = {scope: 'account-b', epoch: mockBoundary.epoch + 1};
    const second = owner.save({...mockBoundary}, '2');
    try {
      await flush();
      expect(mockSave).toHaveBeenCalledTimes(2);
      await second;
      await expect(owner.read({...mockBoundary})).resolves.toEqual(['2']);
    } finally {
      gate.resolve();
      await Promise.all([first, second]);
    }
    await expect(first).resolves.toMatchObject({
      message: 'ACCOUNT_CHANGED_DURING_REQUEST',
    });
  });

  it('preserves same-account write ordering until the native write really finishes', async () => {
    const gate = deferred<void>();
    mockSave.mockImplementationOnce(async (key: string, value: unknown) => {
      await gate.promise;
      disk.set(key, value);
      return true;
    });
    const first = owner.save({...mockBoundary}, '1');
    const second = owner.save({...mockBoundary}, '2');
    try {
      await flush();
      expect(mockSave).toHaveBeenCalledTimes(1);
    } finally {
      gate.resolve();
      await Promise.all([first, second]);
    }
    await expect(owner.read({...mockBoundary})).resolves.toEqual(['2']);
  });

  it('rejects queued old-session writes before touching storage', async () => {
    const gate = deferred<void>();
    mockSave.mockReturnValueOnce(gate.promise);
    const boundary = {...mockBoundary};
    const first = owner.save(boundary, '1').catch(error => error);
    const queued = owner.save(boundary, '2').catch(error => error);
    await flush();
    mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
    gate.resolve();
    for (const pending of [first, queued]) {
      await expect(pending).resolves.toMatchObject({
        message: 'ACCOUNT_CHANGED_DURING_REQUEST',
      });
    }
    expect(mockSave).toHaveBeenCalledTimes(1);
    await owner.save({...mockBoundary}, '3');
    await expect(owner.read({...mockBoundary})).resolves.toEqual(['3']);
  });

  it.each(['reject', 'false'])(
    'releases later writes after persistence returns %s',
    async failure => {
      if (failure === 'reject')
        mockSave.mockRejectedValueOnce(new Error('storage failed'));
      else mockSave.mockResolvedValueOnce(false);
      const first = owner.save({...mockBoundary}, '1').catch(error => error);
      const second = owner.save({...mockBoundary}, '2');
      await Promise.all([first, second]);
      expect(mockSave).toHaveBeenCalledTimes(2);
      await expect(owner.read({...mockBoundary})).resolves.toEqual(['2']);
    },
  );

  it('rejects cache reads completed after an account switch', async () => {
    await owner.save({...mockBoundary}, '1');
    const gate = deferred<unknown>();
    const snapshot = [...disk.values()][0];
    mockRead.mockReturnValueOnce(gate.promise);
    const reading = owner.read({...mockBoundary}).catch(error => error);
    await flush();
    mockBoundary = {scope: 'account-b', epoch: mockBoundary.epoch + 1};
    gate.resolve(snapshot);
    await expect(reading).resolves.toMatchObject({
      message: 'ACCOUNT_CHANGED_DURING_REQUEST',
    });
  });
});

// The wallet snapshot moved into the account query snapshot; its per-account
// key, old-session write refusal and failed-write recovery are proven in
// queryPersistence.test.tsx.
