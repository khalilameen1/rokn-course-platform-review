import type {ProductFeedbackReceipt} from '../src/services/productFeedback/contracts';

const mockDisk = new Map<string, string>();
const mockRead = jest.fn();
const mockWrite = jest.fn();
const mockRemove = jest.fn();
let mockBoundary = {scope: 'user-a', epoch: 1};

jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: (...args: unknown[]) => mockRead(...args),
  setItem: (...args: unknown[]) => mockWrite(...args),
  removeItem: (...args: unknown[]) => mockRemove(...args),
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string, boundary = mockBoundary) =>
    `${key}:${boundary.scope}`,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));

import {
  loadStoredReceipts,
  persistProductFeedbackReceipt,
} from '../src/services/productFeedback/receipts';

const key = '@rokn/product-feedback-receipts/v1:user-a';
const publicId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const receipt: ProductFeedbackReceipt = {
  publicId,
  accessToken: 'A'.repeat(43),
  attachments: [],
  caseNumber: 'RKN-123',
  createdAt: '2026-09-25T12:00:00Z',
  messages: [],
  replayed: false,
  status: 'open',
};
const deferred = () => {
  let resolve!: () => void;
  const promise = new Promise<void>(done => (resolve = done));
  return {promise, resolve};
};

describe('feedback receipt read and mutation ownership', () => {
  beforeEach(() => {
    mockBoundary = {scope: 'user-a', epoch: 1};
    mockDisk.clear();
    mockRead
      .mockReset()
      .mockImplementation(
        async (storageKey: string) => mockDisk.get(storageKey) ?? null,
      );
    mockWrite
      .mockReset()
      .mockImplementation(async (storageKey: string, value: string) => {
        mockDisk.set(storageKey, value);
      });
    mockRemove.mockReset().mockImplementation(async (storageKey: string) => {
      mockDisk.delete(storageKey);
    });
  });

  it('does not let a stale corrupt read erase a newly saved support receipt', async () => {
    mockDisk.set(key, '{bad json');
    const started = deferred();
    const release = deferred();
    mockRead.mockImplementationOnce(async () => {
      started.resolve();
      await release.promise;
      return '{bad json';
    });
    const reading = loadStoredReceipts({...mockBoundary});
    try {
      await started.promise;
      await expect(
        persistProductFeedbackReceipt(receipt, mockBoundary),
      ).resolves.toBe(true);
      release.resolve();
      await expect(reading).resolves.toEqual([]);
      expect(JSON.parse(mockDisk.get(key)!)).toEqual([
        expect.objectContaining({publicId, accessToken: receipt.accessToken}),
      ]);
    } finally {
      release.resolve();
      await reading;
    }
  });

  it.each([
    null,
    '{bad json',
    'null',
    '{}',
    '[]',
    '[null, 12, {"publicId":"invalid"}]',
  ])('reading %s performs no storage mutations', async raw => {
    if (raw !== null) mockDisk.set(key, raw);
    await expect(loadStoredReceipts(mockBoundary)).resolves.toEqual([]);
    expect(mockWrite).not.toHaveBeenCalled();
    expect(mockRemove).not.toHaveBeenCalled();
    expect(mockDisk.get(key)).toBe(raw ?? undefined);
  });

  it('replaces corrupt storage in its normal write without requiring a separate deletion', async () => {
    mockDisk.set(key, '{bad json');
    mockRemove.mockRejectedValue(new Error('delete unavailable'));
    await expect(
      persistProductFeedbackReceipt(receipt, mockBoundary),
    ).resolves.toBe(true);
    expect(mockRemove).not.toHaveBeenCalled();
    await expect(loadStoredReceipts(mockBoundary)).resolves.toEqual([
      expect.objectContaining({publicId, accessToken: receipt.accessToken}),
    ]);
  });

  it('filters malformed entries and credentials without rewriting valid siblings', async () => {
    const raw = JSON.stringify([
      null,
      {publicId: 'invalid'},
      {publicId, accessToken: 'unsafe token', updatedAt: 5},
      {
        publicId: '01ARZ3NDEKTSV4RRFFQ69G5FAW',
        accessToken: 'B'.repeat(43),
        updatedAt: 6,
      },
    ]);
    mockDisk.set(key, raw);
    await expect(loadStoredReceipts(mockBoundary)).resolves.toEqual([
      {publicId, accessToken: undefined, updatedAt: 5},
      {
        publicId: '01ARZ3NDEKTSV4RRFFQ69G5FAW',
        accessToken: 'B'.repeat(43),
        updatedAt: 6,
      },
    ]);
    expect(mockDisk.get(key)).toBe(raw);
    expect(mockWrite).not.toHaveBeenCalled();
    expect(mockRemove).not.toHaveBeenCalled();
  });

  it('propagates storage failures without deleting the receipt list', async () => {
    const failure = new Error('storage unavailable');
    mockRead.mockRejectedValueOnce(failure);
    await expect(loadStoredReceipts(mockBoundary)).rejects.toBe(failure);
    expect(mockRemove).not.toHaveBeenCalled();
  });

  it('rejects a read after the account changes without clearing its data', async () => {
    const owner = {...mockBoundary};
    mockDisk.set(key, '{bad json');
    mockRead.mockImplementationOnce(async () => {
      mockBoundary = {scope: 'user-b', epoch: 2};
      return '{bad json';
    });
    await expect(loadStoredReceipts(owner)).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(mockRemove).not.toHaveBeenCalled();
    expect(mockDisk.get(key)).toBe('{bad json');
  });
});
