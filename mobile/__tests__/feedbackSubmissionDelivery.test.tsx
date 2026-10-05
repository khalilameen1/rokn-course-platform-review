import React from 'react';
import {Alert} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';
import {launchImageLibrary} from 'react-native-image-picker';
import TestRenderer, {act} from 'react-test-renderer';

let mockBoundary = {scope: 'user-1', epoch: 1};
let mockUuidSequence = 0;
let mockForeground = true;
let mockFocused = true;

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string, boundary = mockBoundary) =>
    `${key}:${boundary.scope}`,
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
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => mockForeground,
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  cacheLearnerDraftFile: jest.fn(),
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
  retainLearnerDraftFiles: jest.fn(async () => undefined),
}));
jest.mock('react-native-image-picker', () => ({launchImageLibrary: jest.fn()}));
jest.mock('../src/screens/feedback/pickFeedbackScreenshot', () => ({
  pickFeedbackScreenshot: jest.fn(async () => undefined),
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () =>
    `11111111-1111-4111-8111-${String(++mockUuidSequence).padStart(12, '0')}`,
}));

import {publicRequest} from '../src/constants/api';
import {captureAccountSessionBoundary} from '../src/constants/helpers';
import {
  cacheLearnerDraftFile,
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
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
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const drain = async () => {
  for (let index = 0; index < 100; index += 1) await Promise.resolve();
};
const mountComposer = async (sourceScreen = 'settings') => {
  let composer!: ReturnType<typeof useFeedbackComposer>;
  let cases!: ReturnType<typeof useFeedbackCases>;
  const Harness = () => {
    composer = useFeedbackComposer({
      focused: mockFocused,
      identityKey: mockBoundary.scope,
      locale: 'ar',
      sourceScreen,
    });
    cases = useFeedbackCases(mockBoundary.scope, '', mockFocused);
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

const openReplyCase = async (
  view: Awaited<ReturnType<typeof mountComposer>>,
  publicId = caseId,
) => {
  await act(async () => {
    await view.cases.reloadCases(publicId, {
      ...receipt,
      publicId,
      caseNumber: receipt.case_number,
      createdAt: receipt.created_at,
      replayed: false,
    });
    await drain();
  });
};

describe('feedback server delivery and local receipt completion', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
    mockUuidSequence = 0;
    mockForeground = true;
    mockFocused = true;
    jest
      .mocked(captureAccountSessionBoundary)
      .mockImplementation(async () => ({...mockBoundary}));
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

  it('saves the latest same-batch reply on back rather than the previous rendered closure', async () => {
    const {view, attachment} = await mountAttachedDraft('reply');
    act(() => {
      view.cases.setReply('آخر كتابة قبل الرجوع من المحادثة');
      view.renderer.unmount();
    });
    await drain();
    expect(await loadProductFeedbackReplyDraft(caseId, mockBoundary)).toEqual(
      expect.objectContaining({
        message: 'آخر كتابة قبل الرجوع من المحادثة',
        attachment,
      }),
    );
    expect(publicRequest.post).not.toHaveBeenCalled();
  });

  it.each(['replace', 'remove'])(
    'saves a reply screenshot %s before render/debounce and restores it on immediate reopening',
    async operation => {
      const {view, attachment} = await mountAttachedDraft('reply');
      const selected = {
        uri: 'file:///draft/reply-on-back.jpg',
        type: 'image/jpeg',
      };
      let reopened: Awaited<ReturnType<typeof mountComposer>> | undefined;
      try {
        if (operation === 'replace') {
          jest.mocked(pickFeedbackScreenshot).mockResolvedValueOnce(selected);
          await act(async () => {
            await view.cases.chooseReplyScreenshot();
            view.renderer.unmount();
          });
        } else {
          act(() => {
            view.cases.removeReplyScreenshot();
            view.renderer.unmount();
          });
        }
        reopened = await mountComposer();
        await openReplyCase(reopened);
        expect(reopened.cases.replyReady).toBe(true);
        expect(reopened.cases.replyMessage).toBe(
          'مسودة محفوظة قبل تغيير الصورة',
        );
        expect(reopened.cases.replyAttachment).toEqual(
          operation === 'replace' ? selected : undefined,
        );
        expect(removeLearnerDraftFile).toHaveBeenCalledWith(attachment);
        expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(selected);
        expect(publicRequest.post).not.toHaveBeenCalled();
      } finally {
        act(() => {
          view.renderer.unmount();
          reopened?.renderer.unmount();
        });
        await drain();
      }
    },
  );

  it.each(['background', 'blur'])(
    'saves a reply on %s and does not rewrite it during an unrelated clean render',
    async departure => {
      const {view, key} = await mountAttachedDraft('reply');
      try {
        await act(async () => {
          view.cases.setReply('مسودة الرد قبل مغادرة المحادثة');
          await drain();
        });
        await act(async () => {
          if (departure === 'background') mockForeground = false;
          else mockFocused = false;
          view.renderer.update(<view.Harness />);
          await drain();
        });
        expect(
          (await loadProductFeedbackReplyDraft(caseId, mockBoundary))?.message,
        ).toBe('مسودة الرد قبل مغادرة المحادثة');
        const writes = jest
          .mocked(AsyncStorage.setItem)
          .mock.calls.filter(call => call[0] === key).length;
        await act(async () => {
          view.renderer.update(<view.Harness />);
          jest.advanceTimersByTime(300);
          await drain();
        });
        act(() => view.renderer.unmount());
        await drain();
        expect(
          jest
            .mocked(AsyncStorage.setItem)
            .mock.calls.filter(call => call[0] === key),
        ).toHaveLength(writes);
        expect(publicRequest.post).not.toHaveBeenCalled();
      } finally {
        act(() => view.renderer.unmount());
        await drain();
      }
    },
  );

  it('reserves the existing draft queue before a departure boundary resolves so reopening cannot read the older draft', async () => {
    const {view} = await mountAttachedDraft('reply');
    const capture = deferred<typeof mockBoundary>();
    let reopened: Awaited<ReturnType<typeof mountComposer>> | undefined;
    try {
      jest
        .mocked(captureAccountSessionBoundary)
        .mockImplementationOnce(() => capture.promise);
      act(() => {
        view.cases.setReply('النص النهائي قبل فتح المحادثة من جديد');
        view.renderer.unmount();
      });
      reopened = await mountComposer();
      await openReplyCase(reopened);
      expect(reopened.cases.replyReady).toBe(false);
      await act(async () => {
        capture.resolve({...mockBoundary});
        await drain();
      });
      expect(reopened.cases.replyReady).toBe(true);
      expect(reopened.cases.replyMessage).toBe(
        'النص النهائي قبل فتح المحادثة من جديد',
      );
      expect(publicRequest.post).not.toHaveBeenCalled();
    } finally {
      capture.resolve({...mockBoundary});
      await drain();
      act(() => {
        view.renderer.unmount();
        reopened?.renderer.unmount();
      });
      await drain();
    }
  });

  it('does not enqueue rendered predecessor drafts and keeps each case reply independent when switching', async () => {
    const secondId = '01ARZ3NDEKTSV4RRFFQ69G5FB0';
    const {view, attachment} = await mountAttachedDraft('reply');
    jest.mocked(publicRequest.get).mockImplementation(
      async path =>
        ({
          data: {
            data: {
              ...casePayload,
              public_id: String(path).endsWith(secondId) ? secondId : caseId,
            },
          },
        } as never),
    );
    try {
      await openReplyCase(view, secondId);
      await act(async () => {
        view.cases.selectCase(caseId);
        await drain();
      });
      const staleSend = view.cases.sendReply;
      act(() => {
        view.cases.setReply('نسخة أولى لا تحتاج حفظًا عند كل render');
      });
      act(() => {
        view.cases.setReply('آخر رد يخص الطلب الأول');
        view.cases.selectCase(secondId);
      });
      await act(async () => {
        await drain();
      });
      expect(
        (await loadProductFeedbackReplyDraft(caseId, mockBoundary))?.message,
      ).toBe('آخر رد يخص الطلب الأول');
      expect(view.cases.replyMessage).toBe('');
      act(() => {
        view.cases.setReply('آخر رد يخص الطلب الثاني');
      });
      await act(async () => {
        await staleSend();
        await drain();
      });
      expect(publicRequest.post).not.toHaveBeenCalled();
      act(() => {
        view.cases.selectCase(caseId);
      });
      await act(async () => {
        await drain();
      });
      expect(view.cases.replyMessage).toBe('آخر رد يخص الطلب الأول');
      expect(view.cases.replyAttachment).toEqual(attachment);
      expect(
        (await loadProductFeedbackReplyDraft(secondId, mockBoundary))?.message,
      ).toBe('آخر رد يخص الطلب الثاني');
      const persistedMessages = jest
        .mocked(AsyncStorage.setItem)
        .mock.calls.filter(call =>
          call[0].includes('product-feedback-reply/v1'),
        )
        .map(call => JSON.parse(call[1]).message);
      expect(persistedMessages).not.toContain(
        'نسخة أولى لا تحتاج حفظًا عند كل render',
      );
    } finally {
      act(() => view.renderer.unmount());
      await drain();
    }
  });

  it('invalidates a captured earlier reply autosave when a newer edit departs', async () => {
    const {view} = await mountAttachedDraft('reply');
    const capture = deferred<typeof mockBoundary>();
    try {
      await act(async () => {
        view.cases.setReply('النص الأقدم قبل آخر تعديل');
        await drain();
      });
      jest
        .mocked(captureAccountSessionBoundary)
        .mockImplementationOnce(() => capture.promise);
      await act(async () => {
        jest.advanceTimersByTime(300);
        await drain();
      });
      act(() => {
        view.cases.setReply('النص الأحدث عند الرجوع');
        view.renderer.unmount();
      });
      capture.resolve({...mockBoundary});
      await drain();
      expect(
        (await loadProductFeedbackReplyDraft(caseId, mockBoundary))?.message,
      ).toBe('النص الأحدث عند الرجوع');
      expect(
        jest
          .mocked(AsyncStorage.setItem)
          .mock.calls.filter(call =>
            call[0].includes('product-feedback-reply/v1'),
          )
          .map(call => JSON.parse(call[1]).message),
      ).not.toContain('النص الأقدم قبل آخر تعديل');
    } finally {
      capture.resolve({...mockBoundary});
      await drain();
      act(() => view.renderer.unmount());
      await drain();
    }
  });

  it('keeps a newly saved same-case reply and image when an accepted prior send returns after back and reopening', async () => {
    const {view} = await mountAttachedDraft('reply');
    const ack = deferred<unknown>();
    let sending: Promise<void> | undefined;
    let reopened: Awaited<ReturnType<typeof mountComposer>> | undefined;
    const newerImage = {
      uri: 'file:///draft/newer-reply.jpg',
      type: 'image/jpeg',
    };
    try {
      jest
        .mocked(publicRequest.post)
        .mockImplementationOnce(async () => (await ack.promise) as never);
      await act(async () => {
        view.cases.setReply('الرد السابق الجاري إرساله');
        await drain();
      });
      await act(async () => {
        sending = view.cases.sendReply();
        await drain();
      });
      expect(publicRequest.post).toHaveBeenCalledTimes(1);
      act(() => view.renderer.unmount());
      reopened = await mountComposer();
      await openReplyCase(reopened);
      expect(reopened.cases.replyMessage).toBe('الرد السابق الجاري إرساله');
      jest.mocked(pickFeedbackScreenshot).mockResolvedValueOnce(newerImage);
      await act(async () => {
        await reopened!.cases.chooseReplyScreenshot();
        reopened!.cases.setReply('رد أحدث سأرسله لاحقًا');
        await drain();
      });
      await act(async () => {
        jest.advanceTimersByTime(300);
        await drain();
      });
      const newerDraft = await loadProductFeedbackReplyDraft(
        caseId,
        mockBoundary,
      );
      expect(newerDraft).toEqual(
        expect.objectContaining({
          message: 'رد أحدث سأرسله لاحقًا',
          attachment: newerImage,
        }),
      );
      await act(async () => {
        ack.resolve({
          data: {
            data: {
              ...casePayload,
              messages: [
                {
                  public_id: 'reply-prior',
                  author: 'learner',
                  text: 'الرد السابق الجاري إرساله',
                  created_at: receipt.created_at,
                },
              ],
            },
          },
        });
        await sending;
        await drain();
      });
      expect(await loadProductFeedbackReplyDraft(caseId, mockBoundary)).toEqual(
        newerDraft,
      );
      expect(reopened.cases.replyMessage).toBe('رد أحدث سأرسله لاحقًا');
      expect(reopened.cases.replyAttachment).toEqual(newerImage);
      expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(newerImage);
      expect(publicRequest.post).toHaveBeenCalledTimes(1);
    } finally {
      ack.resolve({data: {data: casePayload}});
      await sending;
      act(() => {
        view.renderer.unmount();
        reopened?.renderer.unmount();
      });
      await drain();
    }
  });

  it('keeps the borrowed image through a late prior ACK before the reopened reply debounce saves its new text', async () => {
    const actualFiles = jest.requireActual<
      typeof import('../src/services/learnerDraftFiles')
    >('../src/services/learnerDraftFiles');
    const previousExists = jest.mocked(RNFS.exists).getMockImplementation()!;
    const previousStat = jest.mocked(RNFS.stat).getMockImplementation()!;
    const previousUnlink = jest.mocked(RNFS.unlink).getMockImplementation()!;
    const previousRead = jest.mocked(RNFS.readFile).getMockImplementation()!;
    const previousWrite = jest.mocked(RNFS.writeFile).getMockImplementation()!;
    const previousMove = jest.mocked(RNFS.moveFile).getMockImplementation()!;
    const previousReadable = jest
      .mocked(learnerDraftFileIsReadable)
      .getMockImplementation()!;
    const previousRemove = jest
      .mocked(removeLearnerDraftFile)
      .getMockImplementation()!;
    const previousRetain = jest
      .mocked(retainLearnerDraftFiles)
      .getMockImplementation()!;
    const path = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/user-1/feedback/reused.png`;
    const nativeFiles = new Map([[path, 'image-bytes']]);
    jest
      .mocked(RNFS.exists)
      .mockImplementation(async name => nativeFiles.has(name));
    jest.mocked(RNFS.stat).mockImplementation(async name => {
      if (!nativeFiles.has(name)) throw new Error('ENOENT');
      return {size: 20} as Awaited<ReturnType<typeof RNFS.stat>>;
    });
    jest.mocked(RNFS.readFile).mockImplementation(async name => {
      if (!nativeFiles.has(name)) throw new Error('ENOENT');
      return nativeFiles.get(name)!;
    });
    jest.mocked(RNFS.writeFile).mockImplementation(async (name, content) => {
      nativeFiles.set(name, content);
    });
    jest.mocked(RNFS.moveFile).mockImplementation(async (from, to) => {
      if (!nativeFiles.has(from)) throw new Error('ENOENT');
      nativeFiles.set(to, nativeFiles.get(from)!);
      nativeFiles.delete(from);
    });
    jest.mocked(RNFS.unlink).mockImplementation(async name => {
      nativeFiles.delete(name);
    });
    jest
      .mocked(learnerDraftFileIsReadable)
      .mockImplementation(actualFiles.learnerDraftFileIsReadable);
    jest
      .mocked(removeLearnerDraftFile)
      .mockImplementation(actualFiles.removeLearnerDraftFile);
    jest
      .mocked(retainLearnerDraftFiles)
      .mockImplementation(actualFiles.retainLearnerDraftFiles);
    const screenshot = {
      uri: `file://${path}`,
      type: 'image/png',
      fileName: 'reused.png',
      size: 20,
    };
    const ack = deferred<unknown>();
    let view: Awaited<ReturnType<typeof mountComposer>> | undefined;
    let reopened: Awaited<ReturnType<typeof mountComposer>> | undefined;
    let sending: Promise<void> | undefined;
    try {
      await saveProductFeedbackReplyDraft(
        caseId,
        {
          message: 'الرد السابق بصورته',
          attachment: screenshot,
          clientRequestId: '11111111-1111-4111-8111-000000000099',
        },
        mockBoundary,
      );
      view = await mountComposer();
      await openReplyCase(view);
      expect(view.cases.replyReady).toBe(true);
      jest
        .mocked(publicRequest.post)
        .mockImplementationOnce(async () => (await ack.promise) as never);
      await act(async () => {
        sending = view!.cases.sendReply();
        await drain();
      });
      expect(publicRequest.post).toHaveBeenCalledTimes(1);
      act(() => view!.renderer.unmount());
      reopened = await mountComposer();
      await openReplyCase(reopened);
      expect(reopened.cases.replyAttachment).toEqual(screenshot);
      // No image replacement and no advance of the 300 ms draft timer.
      act(() => {
        reopened!.cases.setReply('نص أحدث يحتفظ بالصورة نفسها');
      });
      await act(async () => {
        ack.resolve({data: {data: casePayload}});
        await sending;
        await drain();
      });
      expect(reopened.cases.replyMessage).toBe('نص أحدث يحتفظ بالصورة نفسها');
      expect(reopened.cases.replyAttachment).toEqual(screenshot);
      expect(nativeFiles.has(path)).toBe(true);
      expect(await actualFiles.learnerDraftFileIsReadable(screenshot)).toBe(
        true,
      );
      await act(async () => {
        jest.advanceTimersByTime(300);
        await drain();
      });
      expect(await loadProductFeedbackReplyDraft(caseId, mockBoundary)).toEqual(
        expect.objectContaining({
          message: 'نص أحدث يحتفظ بالصورة نفسها',
          attachment: screenshot,
        }),
      );
      expect(nativeFiles.has(path)).toBe(true);
    } finally {
      ack.resolve({data: {data: casePayload}});
      await sending;
      act(() => {
        view?.renderer.unmount();
        reopened?.renderer.unmount();
      });
      await drain();
      jest.mocked(RNFS.exists).mockImplementation(previousExists);
      jest.mocked(RNFS.stat).mockImplementation(previousStat);
      jest.mocked(RNFS.unlink).mockImplementation(previousUnlink);
      jest.mocked(RNFS.readFile).mockImplementation(previousRead);
      jest.mocked(RNFS.writeFile).mockImplementation(previousWrite);
      jest.mocked(RNFS.moveFile).mockImplementation(previousMove);
      jest
        .mocked(learnerDraftFileIsReadable)
        .mockImplementation(previousReadable);
      jest.mocked(removeLearnerDraftFile).mockImplementation(previousRemove);
      jest.mocked(retainLearnerDraftFiles).mockImplementation(previousRetain);
    }
  });

  it('shows reply save failure without deleting the previous saved screenshot or sending the unsaved reply', async () => {
    const {view, attachment, key} = await mountAttachedDraft('reply');
    const previous = await originalGet(key);
    jest
      .mocked(AsyncStorage.setItem)
      .mockImplementation(async (name, value) => {
        if (name === key) throw new Error('disk-full');
        return originalSet(name, value);
      });
    try {
      await act(async () => {
        view.cases.removeReplyScreenshot();
        view.cases.setReply('تعديل لم يستطع الجهاز حفظه');
        await drain();
        jest.advanceTimersByTime(300);
        await drain();
      });
      expect(view.cases.replyError).toContain('تعذّر حفظ الرد على الجهاز');
      expect(await originalGet(key)).toBe(previous);
      expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(attachment);
      await act(async () => {
        await view.cases.sendReply();
      });
      expect(publicRequest.post).not.toHaveBeenCalled();
    } finally {
      act(() => view.renderer.unmount());
      await drain();
      jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
    }
  });

  it('flushes the latest text and metadata on back before a render or debounce can commit', async () => {
    const view = await mountComposer();
    act(() => {
      view.current.setMessage('رسالة جديدة سأكملها لاحقًا');
      view.current.selectCategory('playback');
      view.current.setIncludeDiagnostics(true);
      view.renderer.unmount();
    });
    await drain();
    const draft = await loadProductFeedbackDraft(mockBoundary);
    expect(draft).toEqual(
      expect.objectContaining({
        message: 'رسالة جديدة سأكملها لاحقًا',
        category: 'playback',
        includeDiagnostics: true,
        sourceScreen: 'settings',
      }),
    );
    expect(publicRequest.post).not.toHaveBeenCalled();
  });

  it.each(['replace', 'remove'])(
    'commits an adopted image %s on back without waiting for the debounce, and reopens the same draft',
    async operation => {
      const {view, attachment} = await mountAttachedDraft('new');
      const selected = {
        uri: 'file:///draft/ready-on-back.jpg',
        type: 'image/jpeg',
        fileName: 'ready.jpg',
      };
      let reopened: Awaited<ReturnType<typeof mountComposer>> | undefined;
      try {
        if (operation === 'replace') {
          jest.mocked(pickFeedbackScreenshot).mockResolvedValueOnce(selected);
          await act(async () => {
            await view.current.chooseScreenshot();
            view.renderer.unmount();
          });
        } else {
          act(() => {
            view.current.removeScreenshot();
            view.renderer.unmount();
          });
        }
        // Reopen directly; the real draft queue must order restoration behind save.
        reopened = await mountComposer('feedback');
        expect(reopened.current.message).toBe('مسودة محفوظة قبل تغيير الصورة');
        expect(reopened.current.attachment).toEqual(
          operation === 'replace' ? selected : undefined,
        );
        expect(removeLearnerDraftFile).toHaveBeenCalledWith(attachment);
        expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(selected);
        expect(publicRequest.post).not.toHaveBeenCalled();
      } finally {
        act(() => {
          view.renderer.unmount();
          reopened?.renderer.unmount();
        });
        await drain();
      }
    },
  );

  it.each(['background', 'blur'])(
    'flushes the current snapshot on %s and does not rewrite a clean draft at later unmount',
    async departure => {
      const view = await mountComposer();
      try {
        await act(async () => {
          view.current.setMessage('مسودة قبل مغادرة المحرر');
          await drain();
        });
        await act(async () => {
          if (departure === 'background') mockForeground = false;
          else mockFocused = false;
          view.renderer.update(<view.Harness />);
          await drain();
        });
        expect((await loadProductFeedbackDraft(mockBoundary))?.message).toBe(
          'مسودة قبل مغادرة المحرر',
        );
        const writes = jest
          .mocked(AsyncStorage.setItem)
          .mock.calls.filter(call =>
            call[0].includes('product-feedback-draft/v1'),
          ).length;
        act(() => view.renderer.unmount());
        await drain();
        expect(
          jest
            .mocked(AsyncStorage.setItem)
            .mock.calls.filter(call =>
              call[0].includes('product-feedback-draft/v1'),
            ),
        ).toHaveLength(writes);
        expect(publicRequest.post).not.toHaveBeenCalled();
      } finally {
        act(() => view.renderer.unmount());
        await drain();
      }
    },
  );

  it('does not let a late earlier autosave overwrite the latest departure snapshot', async () => {
    const view = await mountComposer();
    const capture = deferred<typeof mockBoundary>();
    try {
      await act(async () => {
        view.current.setMessage('نسخة قديمة قبل آخر تعديل');
        await drain();
      });
      jest
        .mocked(captureAccountSessionBoundary)
        .mockImplementationOnce(() => capture.promise);
      await act(async () => {
        jest.advanceTimersByTime(250);
        await drain();
      });
      act(() => {
        view.current.setMessage('آخر نسخة عند الرجوع من الصفحة');
        view.renderer.unmount();
      });
      await drain();
      expect((await loadProductFeedbackDraft(mockBoundary))?.message).toBe(
        'آخر نسخة عند الرجوع من الصفحة',
      );
      capture.resolve({...mockBoundary});
      await drain();
      expect((await loadProductFeedbackDraft(mockBoundary))?.message).toBe(
        'آخر نسخة عند الرجوع من الصفحة',
      );
    } finally {
      capture.resolve({...mockBoundary});
      await drain();
      act(() => view.renderer.unmount());
    }
  });

  it('does not let an earlier account save overwrite or flag the next account draft', async () => {
    const view = await mountComposer();
    const priorBoundary = {...mockBoundary};
    const capture = deferred<typeof mockBoundary>();
    const next = {
      category: 'content' as const,
      message: 'مسودة صاحب الحساب الثاني',
      includeDiagnostics: false,
      clientRequestId: '11111111-1111-4111-8111-000000000099',
      updatedAt: Date.now(),
    };
    try {
      await act(async () => {
        view.current.setMessage('نص الحساب الأول قبل تغييره');
        await drain();
      });
      jest
        .mocked(captureAccountSessionBoundary)
        .mockImplementationOnce(() => capture.promise);
      await act(async () => {
        jest.advanceTimersByTime(250);
        await drain();
      });
      mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
      await saveProductFeedbackDraft(next, mockBoundary);
      await act(async () => {
        view.renderer.update(<view.Harness />);
        await drain();
      });
      expect(view.current.message).toBe(next.message);
      await act(async () => {
        capture.resolve(priorBoundary);
        await drain();
      });
      expect(view.current.message).toBe(next.message);
      expect(view.current.draftSaveError).toBe(false);
      expect(await loadProductFeedbackDraft(mockBoundary)).toEqual(next);
      expect(publicRequest.post).not.toHaveBeenCalled();
    } finally {
      capture.resolve(priorBoundary);
      await drain();
      act(() => view.renderer.unmount());
      await drain();
    }
  });

  it('does not clear a stored draft when leaving before restoration completes', async () => {
    const saved = {
      category: 'problem' as const,
      message: 'رسالة محفوظة قبل الاستعادة',
      attachment: {uri: 'file:///draft/existing.jpg', type: 'image/jpeg'},
      includeDiagnostics: false,
      clientRequestId: '11111111-1111-4111-8111-000000000099',
      updatedAt: Date.now(),
    };
    await saveProductFeedbackDraft(saved, mockBoundary);
    const reading = deferred<void>();
    jest.mocked(AsyncStorage.getItem).mockImplementation(async key => {
      if (key.includes('product-feedback-draft/v1')) await reading.promise;
      return originalGet(key);
    });
    const view = await mountComposer();
    try {
      expect(view.current.ready).toBe(false);
      act(() => view.renderer.unmount());
      reading.resolve();
      await drain();
      jest.mocked(AsyncStorage.getItem).mockImplementation(originalGet);
      expect(await loadProductFeedbackDraft(mockBoundary)).toEqual(saved);
      expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(saved.attachment);
      expect(publicRequest.post).not.toHaveBeenCalled();
    } finally {
      reading.resolve();
      await drain();
      jest.mocked(AsyncStorage.getItem).mockImplementation(originalGet);
      act(() => view.renderer.unmount());
    }
  });

  it('retains the prior durable screenshot if the departure save fails', async () => {
    const {view, attachment, key} = await mountAttachedDraft('new');
    const savedRaw = await originalGet(key);
    jest
      .mocked(AsyncStorage.setItem)
      .mockImplementation(async (name, value) => {
        if (name === key) throw new Error('disk-full');
        return originalSet(name, value);
      });
    try {
      act(() => {
        view.current.removeScreenshot();
        view.current.setMessage('آخر تعديل مع تعذر حفظ الجهاز');
        view.renderer.unmount();
      });
      await drain();
      expect(await originalGet(key)).toBe(savedRaw);
      expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(attachment);
      expect(publicRequest.post).not.toHaveBeenCalled();
    } finally {
      jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
      act(() => view.renderer.unmount());
      await drain();
    }
  });

  it('does not recreate an accepted report draft on blur, background or unmount', async () => {
    const view = await mountComposer();
    try {
      await act(async () => {
        view.current.setMessage('رسالة تم إرسالها بالفعل');
        await drain();
      });
      await act(async () => {
        await view.current.submit();
        await drain();
      });
      expect(view.current.sent).toBe(true);
      await act(async () => {
        mockForeground = false;
        mockFocused = false;
        view.renderer.update(<view.Harness />);
        await drain();
        view.renderer.unmount();
        await drain();
      });
      expect(await loadProductFeedbackDraft(mockBoundary)).toBeNull();
      expect(publicRequest.post).toHaveBeenCalledTimes(1);
    } finally {
      act(() => view.renderer.unmount());
      await drain();
    }
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
        jest
          .mocked(AsyncStorage.setItem)
          .mockImplementation(async (name, value) => {
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
            if (kind === 'reply')
              view!.cases.setReply(`${draft.message} تعديل`);
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
        jest
          .mocked(learnerDraftFileIsReadable)
          .mockImplementation(previousReadable);
        jest.mocked(removeLearnerDraftFile).mockImplementation(previousRemove);
      }
    },
  );

  it.each(['new', 'reply'])(
    'blocks %s send through the real picker adapter and deferred private-copy boundary, then replays the prepared multipart snapshot',
    async kind => {
      const {view, choose} = await mountAttachedDraft(kind);
      const copy = deferred<{uri: string; type: string; fileName: string}>();
      const selected = {
        uri: 'file:///draft/prepared.jpg',
        type: 'image/jpeg',
        fileName: 'prepared.jpg',
      };
      jest.mocked(launchImageLibrary).mockResolvedValueOnce({
        assets: [
          {
            uri: 'content://photos/selected',
            type: selected.type,
            fileName: selected.fileName,
            fileSize: 1024,
          },
        ],
      });
      jest
        .mocked(cacheLearnerDraftFile)
        .mockImplementationOnce(() => copy.promise);
      const actualPicker = jest.requireActual<
        typeof import('../src/screens/feedback/pickFeedbackScreenshot')
      >(
        '../src/screens/feedback/pickFeedbackScreenshot',
      ).pickFeedbackScreenshot;
      jest.mocked(pickFeedbackScreenshot).mockImplementationOnce(actualPicker);
      jest
        .mocked(publicRequest.post)
        .mockRejectedValueOnce(new Error('response-lost'))
        .mockResolvedValue({status: 200, data: {data: casePayload}} as never);
      const append = jest.spyOn(FormData.prototype, 'append');
      const staleSend =
        kind === 'reply' ? view.cases.sendReply : view.current.submit;
      let choosing: Promise<void> | undefined;
      try {
        // Start and send in the same render, before the disabled UI can update.
        await act(async () => {
          choosing = choose();
          await staleSend();
          await drain();
        });
        expect(launchImageLibrary).toHaveBeenCalledTimes(1);
        expect(cacheLearnerDraftFile).toHaveBeenCalledWith(
          'feedback',
          expect.objectContaining({uri: 'content://photos/selected'}),
          4 * 1024 * 1024,
          mockBoundary,
        );
        expect(
          kind === 'reply'
            ? view.cases.replyAttachmentBusy
            : view.current.preparingAttachment,
        ).toBe(true);
        expect(view.current.busy).toBe(false);
        expect(view.cases.replyBusy).toBe(false);
        if (kind === 'new') expect(view.current.canSubmit).toBe(false);
        await act(async () => {
          if (kind === 'reply')
            view.cases.setReply('نص أحدث أثناء تجهيز الصورة');
          else view.current.setMessage('نص أحدث أثناء تجهيز الصورة');
          await staleSend();
          await drain();
        });
        expect(publicRequest.post).not.toHaveBeenCalled();
        await act(async () => {
          copy.resolve(selected);
          await choosing;
          // Reuse the old button callback: its attachment closure is obsolete.
          await staleSend();
          await drain();
        });
        expect(publicRequest.post).toHaveBeenCalledTimes(1);
        expect(
          kind === 'reply'
            ? view.cases.replyAttachment
            : view.current.attachment,
        ).toEqual(selected);
        expect(
          kind === 'reply'
            ? view.cases.replyAttachmentBusy
            : view.current.preparingAttachment,
        ).toBe(false);
        await act(async () => {
          await (kind === 'reply'
            ? view.cases.sendReply()
            : view.current.submit());
          await drain();
        });
        const bodies = jest
          .mocked(publicRequest.post)
          .mock.calls.map(call =>
            Object.fromEntries(
              append.mock.calls.filter(
                (_field, index) => append.mock.contexts[index] === call[1],
              ),
            ),
          );
        expect(bodies).toHaveLength(2);
        for (const body of bodies) {
          expect(body).toEqual(
            expect.objectContaining({
              client_request_id: bodies[0].client_request_id,
              message: 'نص أحدث أثناء تجهيز الصورة',
              screenshot: {
                uri: selected.uri,
                type: selected.type,
                name: selected.fileName,
              },
            }),
          );
        }
        expect(jest.mocked(publicRequest.post).mock.calls[0][0]).toBe(
          kind === 'reply' ? `feedback/${caseId}/messages` : 'feedback',
        );
      } finally {
        copy.resolve(selected);
        await choosing;
        append.mockRestore();
        act(() => view.renderer.unmount());
        await drain();
      }
    },
  );

  it.each(
    ['new', 'reply'].flatMap(kind =>
      ['active', 'unmounted'].map(owner => [kind, owner]),
    ),
  )(
    'keeps the %s saved image on copy failure and shows recovery only to the %s owner',
    async (kind, owner) => {
      const {view, choose, attachment} = await mountAttachedDraft(kind);
      const copy = deferred<typeof attachment>();
      jest.mocked(launchImageLibrary).mockResolvedValueOnce({
        assets: [
          {
            uri: 'content://photos/selected',
            type: 'image/jpeg',
            fileSize: 1024,
          },
        ],
      });
      jest
        .mocked(cacheLearnerDraftFile)
        .mockImplementationOnce(() => copy.promise);
      jest
        .mocked(pickFeedbackScreenshot)
        .mockImplementationOnce(
          jest.requireActual<
            typeof import('../src/screens/feedback/pickFeedbackScreenshot')
          >('../src/screens/feedback/pickFeedbackScreenshot')
            .pickFeedbackScreenshot,
        );
      const alert = jest
        .spyOn(Alert, 'alert')
        .mockImplementation(() => undefined);
      let choosing: Promise<void> | undefined;
      let unmounted = false;
      try {
        await act(async () => {
          choosing = choose();
          await drain();
        });
        if (owner === 'unmounted') {
          act(() => view.renderer.unmount());
          unmounted = true;
        }
        await act(async () => {
          copy.reject(new Error('storage-unavailable'));
          await choosing;
          await drain();
        });
        if (owner === 'active') {
          expect(alert).toHaveBeenCalledTimes(1);
          expect(
            kind === 'reply'
              ? view.cases.replyAttachmentBusy
              : view.current.preparingAttachment,
          ).toBe(false);
          expect(
            kind === 'reply'
              ? view.cases.replyAttachment
              : view.current.attachment,
          ).toEqual(attachment);
        } else expect(alert).not.toHaveBeenCalled();
        expect(publicRequest.post).not.toHaveBeenCalled();
        expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(attachment);
      } finally {
        copy.resolve(attachment);
        await choosing;
        alert.mockRestore();
        if (!unmounted) act(() => view.renderer.unmount());
        await drain();
      }
    },
  );

  it.each(['new', 'reply'])(
    'unlocks the %s draft after cancelling selection without dropping its saved image or edited text',
    async kind => {
      const {view, choose, attachment} = await mountAttachedDraft(kind);
      const picker = deferred<undefined>();
      jest
        .mocked(pickFeedbackScreenshot)
        .mockImplementationOnce(() => picker.promise);
      let choosing: Promise<void> | undefined;
      try {
        await act(async () => {
          choosing = choose();
          await drain();
        });
        await act(async () => {
          if (kind === 'reply') {
            view.cases.removeReplyScreenshot();
            view.cases.setReply('رد محفوظ أثناء اختيار الصورة');
          } else {
            view.current.removeScreenshot();
            view.current.setMessage('رسالة محفوظة أثناء اختيار الصورة');
          }
          picker.resolve(undefined);
          await choosing;
          await drain();
        });
        expect(
          kind === 'reply'
            ? view.cases.replyAttachment
            : view.current.attachment,
        ).toEqual(attachment);
        expect(
          kind === 'reply' ? view.cases.replyMessage : view.current.message,
        ).toContain('محفوظ');
        expect(
          kind === 'reply'
            ? view.cases.replyAttachmentBusy
            : view.current.preparingAttachment,
        ).toBe(false);
        expect(publicRequest.post).not.toHaveBeenCalled();
        expect(removeLearnerDraftFile).not.toHaveBeenCalled();
      } finally {
        picker.resolve(undefined);
        await choosing;
        act(() => view.renderer.unmount());
        await drain();
      }
    },
  );

  it.each(['new', 'reply'])(
    'does not let an old account picker clear the next account %s preparation lock',
    async kind => {
      const {view, choose} = await mountAttachedDraft(kind);
      const oldFile = {uri: 'file:///draft/old-owner.jpg', type: 'image/jpeg'};
      const newFile = {uri: 'file:///draft/new-owner.jpg', type: 'image/jpeg'};
      const oldPicker = deferred<typeof oldFile>();
      const newPicker = deferred<typeof newFile>();
      jest
        .mocked(pickFeedbackScreenshot)
        .mockImplementationOnce(() => oldPicker.promise)
        .mockImplementationOnce(() => newPicker.promise);
      let oldChoosing: Promise<void> | undefined;
      let newChoosing: Promise<void> | undefined;
      try {
        await act(async () => {
          oldChoosing = choose();
          await drain();
        });
        await act(async () => {
          mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
          view.renderer.update(<view.Harness />);
          await drain();
        });
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
        await act(async () => {
          if (kind === 'reply') view.cases.setReply('رد الحساب الجديد');
          else view.current.setMessage('رسالة الحساب الجديد');
          await drain();
        });
        await act(async () => {
          newChoosing =
            kind === 'reply'
              ? view.cases.chooseReplyScreenshot()
              : view.current.chooseScreenshot();
          await drain();
        });
        await act(async () => {
          oldPicker.resolve(oldFile);
          await oldChoosing;
          await drain();
        });
        expect(removeLearnerDraftFile).toHaveBeenCalledWith(oldFile);
        expect(
          kind === 'reply'
            ? view.cases.replyAttachmentBusy
            : view.current.preparingAttachment,
        ).toBe(true);
        await act(async () => {
          await (kind === 'reply'
            ? view.cases.sendReply()
            : view.current.submit());
          await drain();
        });
        expect(publicRequest.post).not.toHaveBeenCalled();
        await act(async () => {
          newPicker.resolve(newFile);
          await newChoosing;
          await drain();
        });
        expect(
          kind === 'reply'
            ? view.cases.replyAttachment
            : view.current.attachment,
        ).toEqual(newFile);
        expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(newFile);
        expect(
          kind === 'reply'
            ? view.cases.replyAttachmentBusy
            : view.current.preparingAttachment,
        ).toBe(false);
      } finally {
        oldPicker.resolve(oldFile);
        newPicker.resolve(newFile);
        await oldChoosing;
        await newChoosing;
        act(() => view.renderer.unmount());
        await drain();
      }
    },
  );

  it('keeps preparation on the selected support case when a previous case picker finishes late', async () => {
    const {view, choose} = await mountAttachedDraft('reply');
    const secondCaseId = '01ARZ3NDEKTSV4RRFFQ69G5FB0';
    const secondPayload = {
      ...casePayload,
      public_id: secondCaseId,
      case_number: 'RKN12346',
    };
    jest.mocked(publicRequest.get).mockImplementation(
      async () =>
        ({
          data: {
            data: {
              items: [casePayload, secondPayload],
              pagination: {current_page: 1, last_page: 1, has_more: false},
            },
          },
        } as never),
    );
    const oldFile = {uri: 'file:///draft/first-case.jpg', type: 'image/jpeg'};
    const newFile = {uri: 'file:///draft/second-case.jpg', type: 'image/jpeg'};
    const oldPicker = deferred<typeof oldFile>();
    const newPicker = deferred<typeof newFile>();
    jest
      .mocked(pickFeedbackScreenshot)
      .mockImplementationOnce(() => oldPicker.promise)
      .mockImplementationOnce(() => newPicker.promise);
    let oldChoosing: Promise<void> | undefined;
    let newChoosing: Promise<void> | undefined;
    try {
      await act(async () => {
        await view.cases.reloadCases();
        await drain();
      });
      await act(async () => {
        oldChoosing = choose();
        await drain();
      });
      await act(async () => {
        view.cases.selectCase(secondCaseId);
        await drain();
      });
      expect(view.cases.selectedCase?.publicId).toBe(secondCaseId);
      expect(view.cases.replyReady).toBe(true);
      await act(async () => {
        view.cases.setReply('رد للطلب الثاني');
        await drain();
        newChoosing = view.cases.chooseReplyScreenshot();
        await drain();
      });
      await act(async () => {
        oldPicker.resolve(oldFile);
        await oldChoosing;
        await drain();
      });
      expect(removeLearnerDraftFile).toHaveBeenCalledWith(oldFile);
      expect(view.cases.replyAttachmentBusy).toBe(true);
      expect(view.cases.replyAttachment).toBeUndefined();
      await act(async () => {
        await view.cases.sendReply();
        await drain();
      });
      expect(publicRequest.post).not.toHaveBeenCalled();
      await act(async () => {
        newPicker.resolve(newFile);
        await newChoosing;
        await drain();
      });
      expect(view.cases.replyAttachment).toEqual(newFile);
      expect(view.cases.replyMessage).toBe('رد للطلب الثاني');
      expect(view.cases.replyAttachmentBusy).toBe(false);
      expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(newFile);
    } finally {
      oldPicker.resolve(oldFile);
      newPicker.resolve(newFile);
      await oldChoosing;
      await newChoosing;
      act(() => view.renderer.unmount());
      await drain();
    }
  });

  it.each(['new', 'reply'])(
    'releases superseded picks after the matching %s snapshot commits without releasing a newer pick',
    async kind => {
      const {view, choose, key} = await mountAttachedDraft(kind);
      const firstPick = {uri: 'file:///draft/first.png', type: 'image/png'};
      const pendingPick = {uri: 'file:///draft/pending.png', type: 'image/png'};
      const newestPick = {uri: 'file:///draft/newest.png', type: 'image/png'};
      const pendingWrite = deferred<void>();
      let writeStarted = false;
      jest
        .mocked(AsyncStorage.setItem)
        .mockImplementation(async (name, value) => {
          if (
            name === key &&
            JSON.parse(value).attachment?.uri === pendingPick.uri
          ) {
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
        expect(JSON.parse((await originalGet(key))!).attachment).toEqual(
          newestPick,
        );
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
      jest
        .mocked(pickFeedbackScreenshot)
        .mockImplementationOnce(() => picker.promise);
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
        expect(JSON.parse((await originalGet(key))!).attachment).toEqual(
          attachment,
        );
        expect(removeLearnerDraftFile).not.toHaveBeenCalledWith(attachment);
        if (reason === 'cancel')
          expect(removeLearnerDraftFile).not.toHaveBeenCalled();
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
