import React from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import TestRenderer, {act} from 'react-test-renderer';

let mockBoundary = {scope: 'user-1', epoch: 1};
let mockUuidSequence = 0;

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
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));
jest.mock('../src/screens/feedback/pickFeedbackScreenshot', () => ({
  pickFeedbackScreenshot: jest.fn(async () => undefined),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () =>
    `11111111-1111-4111-8111-${String(++mockUuidSequence).padStart(12, '0')}`,
}));

import {publicRequest} from '../src/constants/api';
import {useFeedbackComposer} from '../src/screens/feedback/useFeedbackComposer';
import {useFeedbackCases} from '../src/screens/feedback/useFeedbackCases';
import {pickFeedbackScreenshot} from '../src/screens/feedback/pickFeedbackScreenshot';
import {
  loadProductFeedbackDraft,
  migrateGuestProductFeedback,
  persistProductFeedbackReceipt,
  saveProductFeedbackDraft,
  submitProductFeedback,
} from '../src/services/productFeedback';

const originalSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const originalGet = jest.mocked(AsyncStorage.getItem).getMockImplementation()!;
const originalRemove = jest
  .mocked(AsyncStorage.removeItem)
  .getMockImplementation()!;
const caseId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const receipt = {
  attachments: [],
  case_number: 'RKN12345',
  created_at: '2026-09-08T12:00:00.000Z',
  messages: [],
  public_id: caseId,
  status: 'new',
};
const casePayload = {
  ...receipt,
  category: 'bug',
  message: 'يتوقف الفيديو عند الانتقال إلى المقطع التالي',
  updated_at: receipt.created_at,
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const drain = async () => {
  for (let index = 0; index < 40; index += 1) await Promise.resolve();
};
const mountComposer = async (sourceScreen = 'settings') => {
  let composer!: ReturnType<typeof useFeedbackComposer>;
  let cases!: ReturnType<typeof useFeedbackCases>;
  const Harness = () => {
    composer = useFeedbackComposer({
      identityKey: mockBoundary.scope,
      locale: 'ar',
      sourceScreen,
    });
    cases = useFeedbackCases(mockBoundary.scope, '');
    return null;
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
    await drain();
  });
  return {
    get current() {
      return composer;
    },
    get cases() {
      return cases;
    },
    renderer,
    Harness,
  };
};

describe('feedback server delivery and local receipt completion', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
    mockUuidSequence = 0;
    jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
    jest.mocked(AsyncStorage.getItem).mockImplementation(originalGet);
    jest.mocked(AsyncStorage.removeItem).mockImplementation(originalRemove);
    await AsyncStorage.clear();
    jest.mocked(publicRequest.get).mockImplementation(
      async path =>
        ({
          data: {data: path === 'feedback' ? {items: []} : casePayload},
        } as never),
    );
    jest
      .mocked(publicRequest.post)
      .mockResolvedValue({status: 201, data: {data: receipt}} as never);
    jest.useFakeTimers();
  });
  afterEach(() => {
    jest.useRealTimers();
  });

  it.each([
    'receipt-write-failure',
    'receipt-read-failure',
    'receipt-write-hang',
    'draft-clear-hang',
  ])(
    'does not present an accepted report as undelivered after %s',
    async failure => {
      const view = await mountComposer();
      const releaseStorage = deferred<void>();
      let submission: Promise<void> | undefined;
      try {
        await act(async () => {
          view.current.setMessage(
            'يتوقف الفيديو عند الانتقال إلى المقطع التالي',
          );
          await drain();
        });
        expect(view.current.canSubmit).toBe(true);
        jest
          .mocked(AsyncStorage.setItem)
          .mockImplementation(async (key, value) => {
            if (key.includes('product-feedback-receipts')) {
              if (failure === 'receipt-write-failure')
                throw new Error('disk-full');
              if (failure === 'receipt-write-hang')
                await releaseStorage.promise;
            }
            return originalSet(key, value);
          });
        jest.mocked(AsyncStorage.getItem).mockImplementation(async key => {
          if (
            failure === 'receipt-read-failure' &&
            key.includes('product-feedback-receipts')
          ) {
            throw new Error('disk-unavailable');
          }
          return originalGet(key);
        });
        jest.mocked(AsyncStorage.removeItem).mockImplementation(async key => {
          if (
            failure === 'draft-clear-hang' &&
            key.includes('product-feedback-draft/v1')
          ) {
            await releaseStorage.promise;
          }
          return originalRemove(key);
        });
        await act(async () => {
          submission = view.current.submit();
          void view.current.submit();
          await drain();
          jest.advanceTimersByTime(1000);
          await drain();
        });
        expect(publicRequest.post).toHaveBeenCalledTimes(1);
        expect(view.current.sent).toBe(true);
        expect(view.current.receiptId).toBe('RKN12345');
        expect(view.current.error).not.toContain('لم تصل الرسالة');
        expect(view.current.busy).toBe(false);
      } finally {
        jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
        jest.mocked(AsyncStorage.getItem).mockImplementation(originalGet);
        jest.mocked(AsyncStorage.removeItem).mockImplementation(originalRemove);
        await act(async () => {
          releaseStorage.resolve();
          await submission;
          await drain();
          view.renderer.unmount();
        });
      }
    },
  );

  it('does not block the next account draft behind a timed-out old receipt write', async () => {
    const view = await mountComposer();
    const releaseStorage = deferred<void>();
    try {
      await act(async () => {
        view.current.setMessage('رسالة الحساب الأول وصلت إلى فريق الدعم');
        await drain();
      });
      jest
        .mocked(AsyncStorage.setItem)
        .mockImplementation(async (key, value) => {
          if (
            key.includes('product-feedback-receipts') &&
            key.endsWith(':user-1')
          ) {
            await releaseStorage.promise;
          }
          return originalSet(key, value);
        });
      await act(async () => {
        void view.current.submit();
        await drain();
        jest.advanceTimersByTime(1000);
        await drain();
      });
      expect(view.current.sent).toBe(true);
      await act(async () => {
        mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
        view.renderer.update(<view.Harness />);
        await drain();
      });
      expect(view.current.ready).toBe(true);
      expect(view.current.sent).toBe(false);
      expect(view.current.receipt).toBeUndefined();
      expect(view.current.message).toBe('');
    } finally {
      await act(async () => {
        releaseStorage.resolve();
        await drain();
        view.renderer.unmount();
      });
      jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
    }
  });

  it('opens the accepted guest case and sends a reply before tracking can be saved, then retries locally only', async () => {
    mockBoundary = {scope: 'guest-device', epoch: mockBoundary.epoch + 1};
    const token = 'A'.repeat(43);
    jest.mocked(publicRequest.post).mockImplementation(
      async path =>
        ({
          status: 201,
          data: {
            data:
              path === 'feedback'
                ? {...receipt, access_token: token}
                : {
                    ...casePayload,
                    messages: [
                      {
                        public_id: 'reply-1',
                        author: 'learner',
                        text: 'هذه تفاصيل إضافية للمشكلة',
                        created_at: receipt.created_at,
                      },
                    ],
                  },
          },
        } as never),
    );
    const view = await mountComposer();
    try {
      await act(async () => {
        view.current.setMessage(casePayload.message);
        await drain();
      });
      jest
        .mocked(AsyncStorage.setItem)
        .mockImplementation(async (key, value) => {
          if (key.includes('product-feedback-receipts'))
            throw new Error('disk-full');
          return originalSet(key, value);
        });
      await act(async () => {
        await view.current.submit();
        await drain();
      });
      expect(view.current.sent).toBe(true);
      expect(view.current.trackingRecoveryNeeded).toBe(true);
      const draftKey = '@rokn/product-feedback-draft/v1:guest-device';
      const recoveryDraft = await originalGet(draftKey);
      await act(async () => {
        view.current.setMessage(
          'لا ينبغي أن يستبدل هذا النص محاولة الزائر المقبولة',
        );
        view.current.dismissReceipt();
        await view.cases.reloadCases(
          view.current.receiptPublicId,
          view.current.receipt,
        );
        await drain();
      });
      expect(view.current.message).toBe(casePayload.message);
      expect(view.current.canSubmit).toBe(false);
      expect(view.cases.selectedCase?.accessToken).toBe(token);
      expect(publicRequest.get).toHaveBeenCalledWith(`feedback/${caseId}`, {
        headers: {'X-Support-Access': token},
      });
      await act(async () => {
        view.cases.setReply('هذه تفاصيل إضافية للمشكلة');
        await drain();
      });
      await act(async () => {
        await view.cases.sendReply();
        await drain();
      });
      expect(view.cases.replyMessage).toBe('');
      expect(view.cases.replyBusy).toBe(false);
      expect(view.cases.selectedCase?.messages).toEqual([
        expect.objectContaining({publicId: 'reply-1'}),
      ]);
      expect(publicRequest.post).toHaveBeenLastCalledWith(
        `feedback/${caseId}/messages`,
        expect.anything(),
        expect.objectContaining({
          headers: expect.objectContaining({'X-Support-Access': token}),
        }),
      );
      await act(async () => {
        await view.current.retryTracking();
        await drain();
      });
      expect(view.current.trackingRecoveryNeeded).toBe(true);
      expect(await originalGet(draftKey)).toBe(recoveryDraft);
      jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
      await act(async () => {
        const retry = view.current.retryTracking();
        void view.current.retryTracking();
        await retry;
        await drain();
      });
      expect(view.current.trackingRecoveryNeeded).toBe(false);
      expect(await originalGet(draftKey)).toBeNull();
      expect(publicRequest.post).toHaveBeenCalledTimes(2);
      expect(
        JSON.parse(
          (await originalGet(
            '@rokn/product-feedback-receipts/v1:guest-device',
          ))!,
        ),
      ).toEqual([
        expect.objectContaining({publicId: caseId, accessToken: token}),
      ]);
      await act(async () => {
        await view.cases.reloadCases(caseId);
        await drain();
      });
      expect(view.cases.selectedCase?.accessToken).toBe(token);
    } finally {
      jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
      await act(async () => {
        view.renderer.unmount();
        await drain();
      });
    }
  });

  it('keeps the same guest request and multipart identity for retry and reopening from another screen', async () => {
    const append = jest.spyOn(FormData.prototype, 'append');
    mockBoundary = {scope: 'guest-device', epoch: mockBoundary.epoch + 1};
    const screenshot = {
      uri: 'file:///private/support.jpg',
      type: 'image/jpeg',
      fileName: 'support.jpg',
    };
    jest.mocked(pickFeedbackScreenshot).mockResolvedValueOnce(screenshot);
    jest
      .mocked(publicRequest.post)
      .mockRejectedValueOnce(new Error('response-lost'))
      .mockResolvedValue({
        status: 200,
        data: {
          data: {...receipt, replayed: true, access_token: 'A'.repeat(43)},
        },
      } as never);
    const view = await mountComposer('settings');
    let reopened: Awaited<ReturnType<typeof mountComposer>> | undefined;
    try {
      await act(async () => {
        view.current.setMessage(casePayload.message);
        view.current.setIncludeDiagnostics(true);
        await view.current.chooseScreenshot();
        await drain();
      });
      await act(async () => {
        await view.current.submit();
        await drain();
      });
      expect(view.current.sent).toBe(false);
      expect(view.current.error).toContain('تعذّر تأكيد وصول الرسالة');
      jest
        .mocked(AsyncStorage.setItem)
        .mockImplementation(async (key, value) => {
          if (key.includes('product-feedback-receipts'))
            throw new Error('disk-full');
          return originalSet(key, value);
        });
      await act(async () => {
        await view.current.submit();
        await drain();
      });
      expect(view.current.sent).toBe(true);
      expect(view.current.trackingRecoveryNeeded).toBe(true);
      await act(async () => {
        view.renderer.unmount();
        await drain();
      });
      jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
      reopened = await mountComposer('privacy');
      expect(reopened.current.message).toBe(casePayload.message);
      expect(reopened.current.attachment).toEqual(screenshot);
      await act(async () => {
        await reopened!.current.submit();
        await drain();
      });
      expect(reopened.current.sent).toBe(true);
      expect(reopened.current.receipt?.replayed).toBe(true);
      expect(reopened.current.trackingRecoveryNeeded).toBe(false);
      const bodies = jest
        .mocked(publicRequest.post)
        .mock.calls.map(call =>
          Object.fromEntries(
            append.mock.calls.filter(
              (_field, index) => append.mock.contexts[index] === call[1],
            ),
          ),
        );
      expect(bodies).toHaveLength(3);
      bodies.forEach(body =>
        expect(body).toEqual(
          expect.objectContaining({
            client_request_id: bodies[0].client_request_id,
            screen_key: 'settings',
            message: casePayload.message,
            screenshot: {
              uri: screenshot.uri,
              type: screenshot.type,
              name: screenshot.fileName,
            },
          }),
        ),
      );
    } finally {
      append.mockRestore();
      jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
      await act(async () => {
        view.renderer.unmount();
        reopened?.renderer.unmount();
        await drain();
      });
    }
  });

  it('finishes an accepted reply when native draft cleanup never settles', async () => {
    const view = await mountComposer();
    const cleanup = deferred<void>();
    try {
      await act(async () => {
        await view.cases.reloadCases(caseId, {
          publicId: caseId,
          caseNumber: 'RKN12345',
          createdAt: receipt.created_at,
          attachments: [],
          messages: [],
          replayed: false,
          status: 'new',
        });
        await drain();
      });
      await act(async () => {
        view.cases.setReply('هذه تفاصيل إضافية للمشكلة');
        await drain();
      });
      jest.mocked(publicRequest.post).mockResolvedValue({
        data: {
          data: {
            ...casePayload,
            messages: [
              {
                public_id: 'reply-1',
                author: 'learner',
                text: 'هذه تفاصيل إضافية للمشكلة',
                created_at: receipt.created_at,
              },
            ],
          },
        },
      } as never);
      jest.mocked(AsyncStorage.removeItem).mockImplementation(async key => {
        if (key.includes('product-feedback-reply/v1')) await cleanup.promise;
        return originalRemove(key);
      });
      await act(async () => {
        void view.cases.sendReply();
        void view.cases.sendReply();
        await drain();
        jest.advanceTimersByTime(1000);
        await drain();
      });
      expect(publicRequest.post).toHaveBeenCalledTimes(1);
      expect(AsyncStorage.removeItem).toHaveBeenCalledWith(
        `@rokn/product-feedback-reply/v1:${caseId}:user-1`,
      );
      expect(view.cases.replyBusy).toBe(false);
      expect(view.cases.replyMessage).toBe('');
      expect(view.cases.replyError).toBe('');
      expect(view.cases.selectedCase?.messages).toEqual([
        expect.objectContaining({publicId: 'reply-1'}),
      ]);
    } finally {
      cleanup.resolve();
      jest.mocked(AsyncStorage.removeItem).mockImplementation(originalRemove);
      await act(async () => {
        await drain();
        view.renderer.unmount();
        await drain();
      });
    }
  });

  it.each([{}, 's'.repeat(65)])(
    'rejects an invalid persisted source screen %p',
    async sourceScreen => {
      const draft = {
        category: 'problem' as const,
        message: casePayload.message,
        includeDiagnostics: true,
        sourceScreen: sourceScreen as string,
        clientRequestId: '11111111-1111-4111-8111-000000000099',
        updatedAt: Date.now(),
      };
      await originalSet(
        '@rokn/product-feedback-draft/v1:user-1',
        JSON.stringify(draft),
      );
      expect(await loadProductFeedbackDraft(mockBoundary)).toBeNull();
      await expect(
        saveProductFeedbackDraft(draft, mockBoundary),
      ).rejects.toThrow('INVALID_FEEDBACK_DRAFT');
    },
  );

  it.each(['completion', 'failure'])(
    'keeps late receipt write %s ordered with retries and newer accepted cases',
    async result => {
      const releaseStorage = deferred<void>();
      let firstWrite = true;
      jest
        .mocked(AsyncStorage.setItem)
        .mockImplementation(async (key, value) => {
          if (key.includes('product-feedback-receipts') && firstWrite) {
            firstWrite = false;
            await releaseStorage.promise;
            if (result === 'failure') throw new Error('late-disk-error');
          }
          return originalSet(key, value);
        });
      const first = {
        publicId: caseId,
        accessToken: 'A'.repeat(43),
        attachments: [],
        messages: [],
        caseNumber: 'RKN12345',
        createdAt: receipt.created_at,
        replayed: false,
        status: 'new',
      };
      const second = {
        ...first,
        publicId: '01ARZ3NDEKTSV4RRFFQ69G5FAW',
        caseNumber: 'RKN12346',
      };
      try {
        const initial = persistProductFeedbackReceipt(first, mockBoundary);
        await drain();
        jest.advanceTimersByTime(1000);
        await drain();
        expect(await initial).toBe(false);
        const retry = persistProductFeedbackReceipt(first, mockBoundary);
        const newer = persistProductFeedbackReceipt(second, mockBoundary);
        await drain();
        jest.advanceTimersByTime(1000);
        await drain();
        expect(await retry).toBe(false);
        expect(await newer).toBe(false);
        releaseStorage.resolve();
        await drain();
        const stored = JSON.parse(
          (await originalGet('@rokn/product-feedback-receipts/v1:user-1'))!,
        );
        expect(stored.map((item: {publicId: string}) => item.publicId)).toEqual(
          [second.publicId, first.publicId],
        );
      } finally {
        releaseStorage.resolve();
        await drain();
        jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
      }
    },
  );

  it('keeps migration pending until the guest receipt write can be copied with its recovery draft', async () => {
    mockBoundary = {scope: 'guest-device', epoch: mockBoundary.epoch + 1};
    const guestBoundary = {...mockBoundary};
    const draft = {
      category: 'problem' as const,
      message: 'رسالة الزائر التي وصلت إلى الدعم',
      includeDiagnostics: true,
      sourceScreen: 'settings',
      clientRequestId: '11111111-1111-4111-8111-000000000099',
      updatedAt: Date.now(),
    };
    await saveProductFeedbackDraft(draft, guestBoundary);
    const releaseStorage = deferred<void>();
    const token = 'A'.repeat(43);
    jest.mocked(publicRequest.post).mockResolvedValue({
      status: 201,
      data: {data: {...receipt, access_token: token}},
    } as never);
    jest.mocked(AsyncStorage.setItem).mockImplementation(async (key, value) => {
      if (
        key.includes('product-feedback-receipts') &&
        key.endsWith(':guest-device')
      )
        await releaseStorage.promise;
      return originalSet(key, value);
    });
    try {
      const submitted = submitProductFeedback(draft, guestBoundary);
      await drain();
      jest.advanceTimersByTime(1000);
      await drain();
      expect((await submitted).trackingSaved).toBe(false);
      mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
      const migrating = migrateGuestProductFeedback(
        'guest-device',
        'user-2',
        true,
        mockBoundary,
      );
      await drain();
      jest.advanceTimersByTime(1000);
      await drain();
      expect(await migrating).toBe(false);
      expect(
        await originalGet('@rokn/product-feedback-draft/v1:guest-device'),
      ).not.toBeNull();
      releaseStorage.resolve();
      await drain();
      expect(
        await migrateGuestProductFeedback(
          'guest-device',
          'user-2',
          true,
          mockBoundary,
        ),
      ).toBe(true);
      expect(
        JSON.parse(
          (await originalGet('@rokn/product-feedback-draft/v1:user-2'))!,
        ),
      ).toEqual(draft);
      const migrated = JSON.parse(
        (await originalGet('@rokn/product-feedback-receipts/v1:user-2'))!,
      );
      expect(migrated).toEqual([expect.objectContaining({publicId: caseId})]);
      expect(
        jest.mocked(publicRequest.post).mock.calls.map(call => call[0]),
      ).toEqual(['feedback', `feedback/${caseId}/claim`]);
    } finally {
      releaseStorage.resolve();
      await drain();
      jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
    }
  });

  it('merges claimed guest tracking with a newer account receipt instead of replacing it', async () => {
    const guestKey = '@rokn/product-feedback-receipts/v1:guest-device';
    const accountKey = '@rokn/product-feedback-receipts/v1:user-1';
    await originalSet(
      guestKey,
      JSON.stringify([
        {publicId: caseId, accessToken: 'A'.repeat(43), updatedAt: Date.now()},
      ]),
    );
    const claimed = deferred<{data: {data: typeof receipt}}>();
    jest
      .mocked(publicRequest.post)
      .mockImplementation(() => claimed.promise as never);
    const migrating = migrateGuestProductFeedback(
      'guest-device',
      'user-1',
      true,
      mockBoundary,
    );
    try {
      await drain();
      expect(publicRequest.post).toHaveBeenCalledWith(
        `feedback/${caseId}/claim`,
        {},
        expect.anything(),
      );
      const newer = {
        publicId: '01ARZ3NDEKTSV4RRFFQ69G5FAW',
        attachments: [],
        messages: [],
        caseNumber: 'RKN12346',
        createdAt: receipt.created_at,
        replayed: false,
        status: 'new',
      };
      expect(await persistProductFeedbackReceipt(newer, mockBoundary)).toBe(
        true,
      );
      claimed.resolve({data: {data: receipt}});
      expect(await migrating).toBe(true);
      const stored = JSON.parse((await originalGet(accountKey))!);
      expect(stored).toHaveLength(2);
      expect(stored).toEqual(
        expect.arrayContaining([
          expect.objectContaining({publicId: newer.publicId}),
          expect.objectContaining({publicId: caseId}),
        ]),
      );
      expect(
        stored.find((item: {publicId: string}) => item.publicId === caseId)
          .accessToken,
      ).toBeUndefined();
    } finally {
      claimed.resolve({data: {data: receipt}});
      await migrating;
      await drain();
    }
  });
});
