import React from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';
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
import {
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
} from '../src/services/learnerDraftFiles';
import {useFeedbackComposer} from '../src/screens/feedback/useFeedbackComposer';
import {useFeedbackCases} from '../src/screens/feedback/useFeedbackCases';
import {pickFeedbackScreenshot} from '../src/screens/feedback/pickFeedbackScreenshot';
import {
  loadProductFeedbackDraft,
  loadProductFeedbackReplyDraft,
  migrateGuestProductFeedback,
  persistProductFeedbackReceipt,
  saveProductFeedbackDraft,
  saveProductFeedbackReplyDraft,
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

const mountAttachedDraft = async (kind: string) => {
  const attachment = {uri: 'file:///draft/saved.png', type: 'image/png'};
  const draft = {
    attachment,
    category: 'problem' as const,
    clientRequestId: '11111111-1111-4111-8111-000000000099',
    includeDiagnostics: false,
    message: 'مسودة محفوظة قبل تغيير الصورة',
    updatedAt: Date.now(),
  };
  if (kind === 'reply')
    await saveProductFeedbackReplyDraft(caseId, draft, mockBoundary);
  else await saveProductFeedbackDraft(draft, mockBoundary);
  const view = await mountComposer();
  if (kind === 'reply') {
    await act(async () => {
      await view.cases.reloadCases(caseId, {
        ...receipt,
        caseNumber: receipt.case_number,
        publicId: caseId,
        createdAt: receipt.created_at,
        replayed: false,
      });
      await drain();
    });
  }
  return {
    view,
    attachment,
    choose: () =>
      kind === 'reply'
        ? view.cases.chooseReplyScreenshot()
        : view.current.chooseScreenshot(),
    key:
      kind === 'reply'
        ? `@rokn/product-feedback-reply/v1:${caseId}:${mockBoundary.scope}`
        : `@rokn/product-feedback-draft/v1:${mockBoundary.scope}`,
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
          data: {
            data:
              path === 'feedback'
                ? {
                    items: [],
                    pagination: {
                      current_page: 1,
                      last_page: 1,
                      has_more: false,
                    },
                  }
                : casePayload,
          },
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

  it.each(
    [
      ['same-account', 'new'],
      ['migrated-guest', 'new'],
      ['same-account', 'reply'],
      ['migrated-guest', 'reply'],
    ].flatMap(([origin, kind]) =>
      ['failure', 'successful-retry'].map(outcome => [origin, kind, outcome]),
    ),
  )(
    'keeps the last saved screenshot until %s %s draft removal commits (%s)',
    async (origin, kind, outcome) => {
      const actualFiles = jest.requireActual<
        typeof import('../src/services/learnerDraftFiles')
      >('../src/services/learnerDraftFiles');
      const previousExists = jest.mocked(RNFS.exists).getMockImplementation()!;
      const previousStat = jest.mocked(RNFS.stat).getMockImplementation()!;
      const previousUnlink = jest.mocked(RNFS.unlink).getMockImplementation()!;
      const previousReadable = jest
        .mocked(learnerDraftFileIsReadable)
        .getMockImplementation()!;
      const previousRemove = jest
        .mocked(removeLearnerDraftFile)
        .getMockImplementation()!;
      const originalScope =
        origin === 'migrated-guest' ? 'guest-device' : 'user-1';
      const path = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${originalScope}/feedback/saved.png`;
      const nativeFiles = new Set([path]);
      jest
        .mocked(RNFS.exists)
        .mockImplementation(async candidate => nativeFiles.has(candidate));
      jest.mocked(RNFS.stat).mockImplementation(async candidate => {
        if (!nativeFiles.has(candidate))
          throw Object.assign(new Error('missing'), {code: 'ENOENT'});
        return {size: 20} as Awaited<ReturnType<typeof RNFS.stat>>;
      });
      jest.mocked(RNFS.unlink).mockImplementation(async candidate => {
        nativeFiles.delete(candidate);
      });
      jest
        .mocked(learnerDraftFileIsReadable)
        .mockImplementation(actualFiles.learnerDraftFileIsReadable);
      jest
        .mocked(removeLearnerDraftFile)
        .mockImplementation(actualFiles.removeLearnerDraftFile);
      let view: Awaited<ReturnType<typeof mountComposer>> | undefined;
      try {
        mockBoundary = {scope: originalScope, epoch: mockBoundary.epoch + 1};
        const draft = {
          attachment: {
            uri: `file://${path}`,
            fileName: 'saved.png',
            type: 'image/png',
            size: 20,
          },
          category: 'problem' as const,
          clientRequestId: '11111111-1111-4111-8111-000000000099',
          includeDiagnostics: true,
          message: 'مسودة محفوظة بالصورة قبل تعديلها',
          sourceScreen: 'settings',
          updatedAt: Date.now(),
        };
        const savedDraft =
          kind === 'reply'
            ? {
                attachment: draft.attachment,
                clientRequestId: draft.clientRequestId,
                message: draft.message,
              }
            : draft;
        if (kind === 'reply') {
          await saveProductFeedbackReplyDraft(caseId, draft, mockBoundary);
          await persistProductFeedbackReceipt(
            {
              ...receipt,
              caseNumber: receipt.case_number,
              publicId: caseId,
              createdAt: receipt.created_at,
              replayed: false,
            },
            mockBoundary,
          );
        } else await saveProductFeedbackDraft(draft, mockBoundary);
        if (origin === 'migrated-guest') {
          mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
          expect(
            await migrateGuestProductFeedback(
              originalScope,
              mockBoundary.scope,
              false,
              mockBoundary,
            ),
          ).toBe(true);
        }
        const key =
          kind === 'reply'
            ? `@rokn/product-feedback-reply/v1:${caseId}:user-1`
            : '@rokn/product-feedback-draft/v1:user-1';
        expect(JSON.parse((await originalGet(key))!)).toEqual(savedDraft);
        view = await mountComposer();
        if (kind === 'reply') {
          await act(async () => {
            await view!.cases.reloadCases(caseId, {
              ...receipt,
              caseNumber: receipt.case_number,
              publicId: caseId,
              createdAt: receipt.created_at,
              replayed: false,
            });
            await drain();
          });
          expect(view.cases.replyAttachment).toEqual(draft.attachment);
        } else expect(view.current.attachment).toEqual(draft.attachment);
        jest.mocked(AsyncStorage.setItem).mockImplementation(async (name, value) => {
          if (name === key) throw new Error('draft storage unavailable');
          return originalSet(name, value);
        });
        await act(async () => {
          if (kind === 'reply') view!.cases.removeReplyScreenshot();
          else view!.current.removeScreenshot();
          await drain();
        });
        await act(async () => {
          jest.advanceTimersByTime(300);
          await drain();
        });
        if (kind === 'new') expect(view.current.draftSaveError).toBe(true);
        expect(JSON.parse((await originalGet(key))!)).toEqual(savedDraft);
        expect(nativeFiles.has(path)).toBe(true);
        expect(publicRequest.post).not.toHaveBeenCalled();
        if (outcome === 'successful-retry') {
          jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
          await act(async () => {
            if (kind === 'reply') view!.cases.setReply(`${draft.message} تعديل`);
            else view!.current.setMessage(`${draft.message} تعديل`);
            await drain();
          });
          await act(async () => {
            jest.advanceTimersByTime(300);
            await drain();
          });
          expect(JSON.parse((await originalGet(key))!)).toEqual(
            expect.objectContaining({message: `${draft.message} تعديل`}),
          );
          expect(nativeFiles.has(path)).toBe(false);
        }
        act(() => view!.renderer.unmount());
        view = undefined;
        await drain();
        jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
        const restored =
          kind === 'reply'
            ? await loadProductFeedbackReplyDraft(caseId, mockBoundary)
            : await loadProductFeedbackDraft(mockBoundary);
        if (outcome === 'failure') {
          expect({exists: nativeFiles.has(path), restored}).toEqual({
            exists: true,
            restored: savedDraft,
          });
        } else {
          expect(restored?.attachment).toBeUndefined();
          expect(restored?.message).toBe(`${draft.message} تعديل`);
        }
      } finally {
        if (view) act(() => view!.renderer.unmount());
        jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
        jest.mocked(RNFS.exists).mockImplementation(previousExists);
        jest.mocked(RNFS.stat).mockImplementation(previousStat);
        jest.mocked(RNFS.unlink).mockImplementation(previousUnlink);
        jest.mocked(learnerDraftFileIsReadable).mockImplementation(previousReadable);
        jest.mocked(removeLearnerDraftFile).mockImplementation(previousRemove);
      }
    },
  );

  it.each(['new', 'reply'])(
    'releases superseded picks after the matching %s snapshot commits without releasing a newer pick',
    async kind => {
      const {view, choose, key} = await mountAttachedDraft(kind);
      const firstPick = {uri: 'file:///draft/first.png', type: 'image/png'};
      const pendingPick = {uri: 'file:///draft/pending.png', type: 'image/png'};
      const newestPick = {uri: 'file:///draft/newest.png', type: 'image/png'};
      const pendingWrite = deferred<void>();
      let writeStarted = false;
      jest.mocked(AsyncStorage.setItem).mockImplementation(async (name, value) => {
        if (name === key && JSON.parse(value).attachment?.uri === pendingPick.uri) {
          writeStarted = true;
          await pendingWrite.promise;
        }
        return originalSet(name, value);
      });
      jest
        .mocked(pickFeedbackScreenshot)
        .mockResolvedValueOnce(firstPick)
        .mockResolvedValueOnce(pendingPick)
        .mockResolvedValueOnce(newestPick);
      try {
        await act(async () => {
          await choose();
          await drain();
        });
        await act(async () => {
          await choose();
          await drain();
        });
        await act(async () => {
          jest.advanceTimersByTime(300);
          await drain();
        });
        expect(writeStarted).toBe(true);
        expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(pendingPick);
        await act(async () => {
          await choose();
          await drain();
          pendingWrite.resolve();
          await drain();
        });
        expect(removeLearnerDraftFile).toHaveBeenCalledWith(firstPick);
        expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(pendingPick);
        expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(newestPick);
        await act(async () => {
          jest.advanceTimersByTime(300);
          await drain();
        });
        expect(JSON.parse((await originalGet(key))!).attachment).toEqual(newestPick);
        expect(removeLearnerDraftFile).toHaveBeenCalledWith(pendingPick);
        expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(newestPick);
        expect(publicRequest.post).not.toHaveBeenCalled();
      } finally {
        pendingWrite.resolve();
        await drain();
        jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
        act(() => view.renderer.unmount());
        await drain();
      }
    },
  );

  it.each(
    ['new', 'reply'].flatMap(kind =>
      ['cancel', 'unmount', 'account-change'].map(reason => [kind, reason]),
    ),
  )(
    'keeps the saved %s screenshot and discards only an unaccepted picker result after %s',
    async (kind, reason) => {
      const {view, attachment, choose, key} = await mountAttachedDraft(kind);
      const selected = {uri: 'file:///draft/unaccepted.png', type: 'image/png'};
      const picker = deferred<typeof selected | undefined>();
      jest.mocked(pickFeedbackScreenshot).mockImplementationOnce(() => picker.promise);
      let choosing: Promise<void> | undefined;
      let unmounted = false;
      try {
        await act(async () => {
          choosing = choose();
          await drain();
        });
        await act(async () => {
          if (reason === 'unmount') {
            view.renderer.unmount();
            unmounted = true;
          } else if (reason === 'account-change') {
            mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
            view.renderer.update(<view.Harness />);
          }
          await drain();
        });
        await act(async () => {
          picker.resolve(reason === 'cancel' ? undefined : selected);
          await choosing;
          await drain();
        });
        expect(JSON.parse((await originalGet(key))!).attachment).toEqual(attachment);
        expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(attachment);
        if (reason === 'cancel') expect(removeLearnerDraftFile).not.toHaveBeenCalled();
        else expect(removeLearnerDraftFile).toHaveBeenCalledWith(selected);
        expect(publicRequest.post).not.toHaveBeenCalled();
      } finally {
        picker.resolve(undefined);
        await choosing;
        if (!unmounted) act(() => view.renderer.unmount());
        await drain();
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
