import AsyncStorage from '@react-native-async-storage/async-storage';

let mockBoundary = {scope: 'user-1', epoch: 1};
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string, boundary = mockBoundary) =>
    `${key}:${boundary.scope}`,
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => '11111111-1111-4111-8111-111111111111',
}));

import {publicRequest} from '../src/constants/api';
import {
  loadProductFeedbackDraftConflicts,
  migrateGuestProductFeedback,
  restoreProductFeedbackDraftConflict,
  saveProductFeedbackDraft,
  saveProductFeedbackReplyDraft,
} from '../src/services/productFeedback';

const originalSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const originalGet = jest.mocked(AsyncStorage.getItem).getMockImplementation()!;
const originalMultiGet = jest
  .mocked(AsyncStorage.multiGet)
  .getMockImplementation()!;
const caseId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const guestReceiptsKey = '@rokn/product-feedback-receipts/v1:guest-device';
const deferred = () => {
  let resolve!: () => void;
  const promise = new Promise<void>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const drain = async () => {
  for (let index = 0; index < 80; index += 1) await Promise.resolve();
};
const draft = (message: string) => ({
  category: 'problem' as const,
  clientRequestId: '11111111-1111-4111-8111-111111111111',
  includeDiagnostics: false,
  message,
  updatedAt: Date.now(),
});

describe('guest feedback draft adoption shares normal draft ownership', () => {
  beforeEach(async () => {
    mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
    jest.clearAllMocks();
    jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
    jest.mocked(AsyncStorage.multiGet).mockImplementation(originalMultiGet);
    await AsyncStorage.clear();
    await originalSet(
      guestReceiptsKey,
      JSON.stringify([
        {publicId: caseId, accessToken: 'A'.repeat(43), updatedAt: Date.now()},
      ]),
    );
  });

  it.each(['new', 'reply'])(
    'preserves both %s drafts when a local save is still committing at login',
    async kind => {
      const baseKey =
        kind === 'new'
          ? '@rokn/product-feedback-draft/v1'
          : `@rokn/product-feedback-reply/v1:${caseId}`;
      const guestKey = `${baseKey}:guest-device`;
      const accountKey = `${baseKey}:user-1`;
      const guestDraft = draft('مسودة الزائر قبل تسجيل الدخول');
      const accountDraft = draft('مسودة الحساب التي لم يكتمل حفظها بعد');
      await originalSet(guestKey, JSON.stringify(guestDraft));
      const pending = deferred();
      let saveStarted = false;
      jest
        .mocked(AsyncStorage.setItem)
        .mockImplementation(async (key, value) => {
          if (
            key === accountKey &&
            JSON.parse(value).message === accountDraft.message
          ) {
            saveStarted = true;
            await pending.promise;
          }
          return originalSet(key, value);
        });
      const saving =
        kind === 'new'
          ? saveProductFeedbackDraft(accountDraft, mockBoundary)
          : saveProductFeedbackReplyDraft(caseId, accountDraft, mockBoundary);
      await drain();
      expect(saveStarted).toBe(true);
      const migrating = migrateGuestProductFeedback(
        'guest-device',
        'user-1',
        false,
        mockBoundary,
      );
      try {
        await drain();
        expect(await originalGet(guestKey)).not.toBeNull();
        pending.resolve();
        await saving;
        expect(await migrating).toBe(true);
        expect(JSON.parse((await originalGet(accountKey))!).message).toBe(
          accountDraft.message,
        );
        expect(await originalGet(guestKey)).toBeNull();
        const conflicts = await loadProductFeedbackDraftConflicts(mockBoundary);
        expect(conflicts).toHaveLength(1);
        expect(
          await restoreProductFeedbackDraftConflict(
            conflicts[0].id,
            mockBoundary,
          ),
        ).toBe(true);
        expect(JSON.parse((await originalGet(accountKey))!).message).toBe(
          guestDraft.message,
        );
        expect(
          await restoreProductFeedbackDraftConflict(
            conflicts[0].id,
            mockBoundary,
          ),
        ).toBe(true);
        expect(JSON.parse((await originalGet(accountKey))!).message).toBe(
          accountDraft.message,
        );
        expect(publicRequest.post).not.toHaveBeenCalled();
      } finally {
        pending.resolve();
        await Promise.allSettled([saving, migrating]);
      }
    },
  );

  it('does not move or delete a draft after the owning account changes during its read', async () => {
    const guestKey = '@rokn/product-feedback-draft/v1:guest-device';
    const accountKey = '@rokn/product-feedback-draft/v1:user-1';
    const saved = JSON.stringify(draft('مسودة يجب أن تبقى للزائر'));
    await originalSet(guestKey, saved);
    const pending = deferred();
    let readStarted = false;
    jest.mocked(AsyncStorage.multiGet).mockImplementation(async keys => {
      const result = await originalMultiGet(keys);
      if (keys.includes(guestKey)) {
        readStarted = true;
        await pending.promise;
      }
      return result;
    });
    const migrating = migrateGuestProductFeedback(
      'guest-device',
      'user-1',
      true,
      {...mockBoundary},
    );
    const observed = migrating.catch(error => error);
    try {
      await drain();
      expect(readStarted).toBe(true);
      mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
      pending.resolve();
      expect(await observed).toEqual(
        new Error('ACCOUNT_CHANGED_DURING_REQUEST'),
      );
      expect(await originalGet(guestKey)).toBe(saved);
      expect(await originalGet(accountKey)).toBeNull();
      expect(await originalGet(guestReceiptsKey)).not.toBeNull();
      expect(publicRequest.post).not.toHaveBeenCalled();
    } finally {
      pending.resolve();
      await observed;
    }
  });
});
