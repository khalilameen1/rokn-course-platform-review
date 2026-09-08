import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {Alert} from 'react-native';

let mockBoundary = {scope: 'user-1', epoch: 1};
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({...mockBoundary})),
  getCurrentAccountStorageScope: async () => mockBoundary.scope,
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
jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
  retainLearnerDraftFiles: jest.fn(async () => undefined),
}));
jest.mock('../src/screens/feedback/pickFeedbackScreenshot', () => ({
  pickFeedbackScreenshot: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  loadCourseAssistantHistory: jest.fn(async () => []),
}));
import {publicRequest} from '../src/constants/api';
import {captureAccountSessionBoundary} from '../src/constants/helpers';
import {learnerDraftFileIsReadable} from '../src/services/learnerDraftFiles';
import {useFeedbackComposer} from '../src/screens/feedback/useFeedbackComposer';
import {useFeedbackCases} from '../src/screens/feedback/useFeedbackCases';
import {useCourseChatConversation} from '../src/components/VideoPlayer/courseChat/useCourseChatConversation';
import {
  saveProductFeedbackDraft,
  saveProductFeedbackReplyDraft,
  loadProductFeedbackDraft,
  loadProductFeedbackReplyDraft,
} from '../src/services/productFeedback';
import {
  saveCourseChatHistory,
  loadCourseChatHistory,
} from '../src/components/VideoPlayer/courseChat/persistence';

const requestId = '11111111-1111-4111-8111-111111111111';
const caseId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const file = {
  uri: 'file:///draft.png',
  name: 'draft.png',
  type: 'image/png',
  uploadId: 'draft-image',
};
const flush = async () => {
  for (let i = 0; i < 50; i++) await Promise.resolve();
};

describe('local conversation hydration failure is not an empty draft', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  beforeEach(async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    await AsyncStorage.clear();
    mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
    jest
      .mocked(captureAccountSessionBoundary)
      .mockImplementation(async () => ({...mockBoundary}));
    jest.mocked(learnerDraftFileIsReadable).mockResolvedValue(true);
    jest.mocked(publicRequest.get).mockResolvedValue({
      data: {
        data: {
          items: [
            {
              public_id: caseId,
              case_number: 'RKN12345',
              status: 'new',
              category: 'bug',
              message: 'الملاحظة الأصلية',
              messages: [],
              attachments: [],
              created_at: '2026-09-08T12:00:00.000Z',
              updated_at: '2026-09-08T12:00:00.000Z',
            },
          ],
        },
      },
    } as never);
  });
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it.each(['new', 'reply'] as const)(
    'keeps restored %s draft when an older autosave capture finishes later',
    async kind => {
      const baseKey =
        kind === 'new'
          ? '@rokn/product-feedback-draft/v1'
          : `@rokn/product-feedback-reply/v1:${caseId}`;
      const value = {
        category: 'problem' as const,
        message: 'المسودة الحالية',
        clientRequestId: requestId,
        includeDiagnostics: false,
        updatedAt: Date.now(),
      };
      if (kind === 'new') await saveProductFeedbackDraft(value);
      else await saveProductFeedbackReplyDraft(caseId, value);
      await AsyncStorage.setItem(
        `@rokn/product-feedback-draft-conflicts/v1:${mockBoundary.scope}`,
        JSON.stringify([
          {
            id: 'conflict-1',
            baseKey,
            raw: JSON.stringify({
              ...value,
              message: 'المسودة الأخرى المستعادة',
            }),
          },
        ]),
      );
      const alert = jest
        .spyOn(Alert, 'alert')
        .mockImplementation(() => undefined);
      let composer!: ReturnType<typeof useFeedbackComposer>;
      let cases!: ReturnType<typeof useFeedbackCases>;
      const Composer = () => {
        composer = useFeedbackComposer({
          identityKey: mockBoundary.scope,
          locale: 'ar',
          sourceScreen: 'settings',
        });
        return null;
      };
      const Cases = () => {
        cases = useFeedbackCases(mockBoundary.scope, caseId);
        return null;
      };
      await act(async () => {
        renderer = TestRenderer.create(
          kind === 'new' ? <Composer /> : <Cases />,
        );
        await flush();
      });
      let release!: (boundary: typeof mockBoundary) => void;
      const delayed = new Promise<typeof mockBoundary>(resolve => {
        release = resolve;
      });
      jest
        .mocked(captureAccountSessionBoundary)
        .mockImplementationOnce(() => delayed);
      await act(async () =>
        jest.advanceTimersByTimeAsync(kind === 'new' ? 250 : 300),
      );
      const restore = alert.mock.calls[0][2]!.find(button =>
        button.text?.startsWith('استعادة'),
      )!;
      await act(async () => {
        restore.onPress!();
        await flush();
      });
      expect(kind === 'new' ? composer.message : cases.replyMessage).toBe(
        'المسودة الأخرى المستعادة',
      );
      await act(async () => {
        release({...mockBoundary});
        await flush();
      });
      const persisted =
        kind === 'new'
          ? await loadProductFeedbackDraft()
          : await loadProductFeedbackReplyDraft(caseId);
      expect(persisted?.message).toBe('المسودة الأخرى المستعادة');
      expect(publicRequest.post).not.toHaveBeenCalled();
    },
  );

  it.each(['account', 'destination'] as const)(
    'ignores retained restore callbacks after changing %s',
    async change => {
      await saveProductFeedbackDraft({
        category: 'problem',
        message: 'مسودة قديمة محفوظة',
        attachment: file,
        clientRequestId: requestId,
        includeDiagnostics: false,
        updatedAt: Date.now(),
      });
      await saveProductFeedbackReplyDraft(caseId, {
        message: 'رد قديم',
        attachment: file,
        clientRequestId: requestId,
      });
      await saveCourseChatHistory('3', [
        {
          id: 'old',
          role: 'user',
          text: 'سؤال قديم',
          createdAt: Date.now(),
          attachments: [file],
        },
      ]);
      jest
        .mocked(learnerDraftFileIsReadable)
        .mockRejectedValue(new Error('EIO'));
      let composer!: ReturnType<typeof useFeedbackComposer>;
      let cases!: ReturnType<typeof useFeedbackCases>;
      let chat!: ReturnType<typeof useCourseChatConversation>;
      let courseId = '3';
      let sourceScreen = 'settings';
      const inFlightAttachmentIds = {current: new Set<string>()};
      const Harness = () => {
        composer = useFeedbackComposer({
          identityKey: mockBoundary.scope,
          locale: 'ar',
          sourceScreen,
        });
        cases = useFeedbackCases(mockBoundary.scope, caseId);
        chat = useCourseChatConversation({
          courseId,
          conversationScope: `${mockBoundary.scope}:${courseId}`,
          inFlightAttachmentIds,
          remoteEnabled: false,
        });
        return null;
      };
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
        await flush();
      });
      expect(composer.draftRestoreError).toBe(true);
      expect(cases.replyRestoreError).toBe(true);
      expect(chat.hydrationError).not.toBe('');
      const oldRetries = [
        composer.retryDraftRestore,
        cases.retryReplyRestore,
        chat.retryHydration,
      ];
      jest.mocked(learnerDraftFileIsReadable).mockResolvedValue(true);
      if (change === 'account')
        mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
      else {
        courseId = '4';
        sourceScreen = 'help';
      }
      await act(async () => {
        if (change === 'destination')
          cases.selectCase('01ARZ3NDEKTSV4RRFFQ69G5FAW');
        renderer!.update(<Harness />);
        await flush();
      });
      await act(async () => jest.advanceTimersByTimeAsync(1000));
      jest.mocked(AsyncStorage.getItem).mockClear();
      await act(async () => {
        oldRetries.forEach(retry => retry());
        await flush();
      });
      expect(AsyncStorage.getItem).not.toHaveBeenCalled();
      expect(publicRequest.post).not.toHaveBeenCalled();
    },
  );

  it('does not adopt or erase the previous account draft when a delayed local read settles after switching accounts', async () => {
    await saveProductFeedbackDraft({
      category: 'problem',
      message: 'مسودة الحساب الأول',
      attachment: file,
      clientRequestId: requestId,
      includeDiagnostics: false,
      updatedAt: Date.now(),
    });
    await saveProductFeedbackReplyDraft(caseId, {
      message: 'رد الحساب الأول',
      attachment: file,
      clientRequestId: requestId,
    });
    await saveCourseChatHistory('3', [
      {
        id: 'old-user',
        role: 'user',
        text: 'سؤال الحساب الأول',
        createdAt: Date.now(),
        attachments: [file],
      },
    ]);
    let release!: (readable: boolean) => void;
    const pending = new Promise<boolean>(resolve => {
      release = resolve;
    });
    jest.mocked(learnerDraftFileIsReadable).mockReturnValue(pending);
    let composer!: ReturnType<typeof useFeedbackComposer>;
    let cases!: ReturnType<typeof useFeedbackCases>;
    let chat!: ReturnType<typeof useCourseChatConversation>;
    const inFlightAttachmentIds = {current: new Set<string>()};
    const Harness = () => {
      composer = useFeedbackComposer({
        identityKey: mockBoundary.scope,
        locale: 'ar',
        sourceScreen: 'settings',
      });
      cases = useFeedbackCases(mockBoundary.scope, caseId);
      chat = useCourseChatConversation({
        courseId: '3',
        conversationScope: `${mockBoundary.scope}:3`,
        inFlightAttachmentIds,
        remoteEnabled: false,
      });
      return null;
    };
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await flush();
    });
    expect(learnerDraftFileIsReadable).toHaveBeenCalled();
    mockBoundary = {scope: 'user-2', epoch: mockBoundary.epoch + 1};
    await act(async () => {
      renderer!.update(<Harness />);
      await flush();
      release(true);
      await flush();
    });
    await act(async () => jest.advanceTimersByTimeAsync(1000));
    expect(composer.message).toBe('');
    expect(composer.ready).toBe(true);
    expect(cases.replyMessage).toBe('');
    expect(chat.hydrated).toBe(true);
    expect(
      chat.messages.some(message => message.text === 'سؤال الحساب الأول'),
    ).toBe(false);
    await act(async () => {
      renderer!.unmount();
      renderer = undefined;
      await flush();
    });
    mockBoundary = {scope: 'user-1', epoch: mockBoundary.epoch + 1};
    jest.mocked(learnerDraftFileIsReadable).mockResolvedValue(true);
    expect(await loadProductFeedbackDraft()).toMatchObject({
      message: 'مسودة الحساب الأول',
      attachment: file,
    });
    expect(await loadProductFeedbackReplyDraft(caseId)).toMatchObject({
      message: 'رد الحساب الأول',
      attachment: file,
    });
    expect(await loadCourseChatHistory('3')).toContainEqual(
      expect.objectContaining({
        text: 'سؤال الحساب الأول',
        attachments: [expect.objectContaining({uri: file.uri})],
      }),
    );
    expect(publicRequest.post).not.toHaveBeenCalled();
  });

  it.each([false, true])(
    'preserves a support draft and restores its request (new same-account epoch: %s)',
    async newEpoch => {
      await saveProductFeedbackDraft({
        category: 'problem',
        message: 'مسودة الملاحظة الأصلية',
        attachment: file,
        clientRequestId: requestId,
        includeDiagnostics: false,
        updatedAt: Date.now(),
      });
      jest
        .mocked(learnerDraftFileIsReadable)
        .mockRejectedValue(new Error('EIO'));
      let hook!: ReturnType<typeof useFeedbackComposer>;
      const Harness = () => {
        hook = useFeedbackComposer({
          identityKey: mockBoundary.scope,
          locale: 'ar',
          sourceScreen: 'settings',
        });
        return null;
      };
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
        await flush();
      });
      await act(async () => jest.advanceTimersByTimeAsync(1000));
      expect(hook.ready).toBe(false);
      jest.mocked(learnerDraftFileIsReadable).mockResolvedValue(true);
      expect(await loadProductFeedbackDraft()).toMatchObject({
        clientRequestId: requestId,
        attachment: file,
      });
      if (newEpoch)
        mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
      await act(async () => {
        hook.retryDraftRestore();
        await flush();
      });
      expect(hook.ready).toBe(true);
      expect(hook.message).toBe('مسودة الملاحظة الأصلية');
      expect(publicRequest.post).not.toHaveBeenCalled();
    },
  );

  it.each([false, true])(
    'preserves a support reply and retries only its read (new same-account epoch: %s)',
    async newEpoch => {
      await saveProductFeedbackReplyDraft(caseId, {
        message: 'الرد الأصلي',
        clientRequestId: requestId,
        attachment: file,
      });
      jest
        .mocked(learnerDraftFileIsReadable)
        .mockRejectedValue(new Error('EIO'));
      let hook!: ReturnType<typeof useFeedbackCases>;
      const Harness = () => {
        hook = useFeedbackCases(mockBoundary.scope, caseId);
        return null;
      };
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
        await flush();
      });
      await act(async () => jest.advanceTimersByTimeAsync(1000));
      jest.mocked(learnerDraftFileIsReadable).mockResolvedValue(true);
      expect(await loadProductFeedbackReplyDraft(caseId)).toMatchObject({
        clientRequestId: requestId,
        attachment: file,
      });
      if (newEpoch)
        mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
      await act(async () => {
        hook.retryReplyRestore();
        await flush();
      });
      expect(hook.replyReady).toBe(true);
      expect(hook.replyMessage).toBe('الرد الأصلي');
      expect(publicRequest.post).not.toHaveBeenCalled();
    },
  );

  it.each([false, true])(
    'recovers the pending chat identity (new same-account epoch: %s)',
    async newEpoch => {
      await saveCourseChatHistory('3', [
        {
          id: 'local-user',
          role: 'user',
          text: 'سؤال محفوظ',
          createdAt: Date.now(),
          clientRequestId: requestId,
          attachments: [file],
        },
        {
          id: 'local-answer',
          role: 'assistant',
          text: '',
          createdAt: Date.now(),
          clientRequestId: requestId,
          deliveryStatus: 'queued',
        },
      ]);
      jest
        .mocked(learnerDraftFileIsReadable)
        .mockRejectedValue(new Error('EIO'));
      let hook!: ReturnType<typeof useCourseChatConversation>;
      const inFlightAttachmentIds = {current: new Set<string>()};
      const Harness = () => {
        hook = useCourseChatConversation({
          courseId: '3',
          conversationScope: `${mockBoundary.scope}:3`,
          inFlightAttachmentIds,
          remoteEnabled: true,
        });
        return null;
      };
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
        await flush();
      });
      expect(hook.hydrated).toBe(false);
      expect(hook.hydrationError).not.toBe('');
      jest.mocked(learnerDraftFileIsReadable).mockResolvedValue(true);
      expect(await loadCourseChatHistory('3')).toContainEqual(
        expect.objectContaining({
          clientRequestId: requestId,
          deliveryStatus: 'queued',
        }),
      );
      if (newEpoch)
        mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
      await act(async () => {
        hook.retryHydration();
        await flush();
      });
      expect(hook.hydrated).toBe(true);
      expect(hook.messages).toContainEqual(
        expect.objectContaining({
          clientRequestId: requestId,
          deliveryStatus: 'queued',
        }),
      );
      expect(hook.recoveryRevision).toBeGreaterThan(0);
    },
  );
});
