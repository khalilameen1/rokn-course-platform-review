import AsyncStorage from '@react-native-async-storage/async-storage';
import fs from 'node:fs';
import path from 'node:path';

const mockCaptureBoundary = jest.fn(async () => ({epoch: 2, scope: 'user-b'}));
const mockAssertBoundary = jest.fn();

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(
    async (key: string, boundary?: {scope: string}) =>
      `${key}:${boundary?.scope || 'user-b'}`,
  ),
  assertAccountSessionBoundary: (...args: unknown[]) =>
    mockAssertBoundary(...args),
  captureAccountSessionBoundary: () => mockCaptureBoundary(),
}));

jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  retainLearnerDraftFiles: jest.fn(async () => undefined),
  removeLearnerDraftFile: jest.fn(async () => undefined),
}));

import {
  loadCourseChatHistory,
  mergeCourseChatHistories,
  saveCourseChatHistory,
} from '../src/components/VideoPlayer/courseChat/persistence';
import {retainLearnerDraftFiles} from '../src/services/learnerDraftFiles';
import {saveProjectFeedbackDraft} from '../src/services/projectFeedbackDraft';

describe('chat draft account ownership', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    jest.mocked(retainLearnerDraftFiles).mockResolvedValue();
    await AsyncStorage.clear();
  });

  it('merges paged server history with older local rows without duplication or reordering', () => {
    const remote = [
      {
        id: 'server-user-new',
        role: 'user' as const,
        text: 'الجديد',
        createdAt: 300,
        clientRequestId: 'request-new',
        deliveryStatus: 'completed' as const,
      },
      {
        id: 'server-answer-new',
        role: 'assistant' as const,
        text: 'رد جديد',
        createdAt: 400,
        clientRequestId: 'request-new',
        deliveryStatus: 'completed' as const,
      },
    ];
    const local = [
      {
        id: 'local-user-old',
        role: 'user' as const,
        text: 'القديم',
        createdAt: 100,
        clientRequestId: 'request-old',
        deliveryStatus: 'completed' as const,
      },
      {
        id: 'local-answer-old',
        role: 'assistant' as const,
        text: 'رد قديم',
        createdAt: 200,
        clientRequestId: 'request-old',
        deliveryStatus: 'completed' as const,
      },
      {...remote[1], id: 'stale-local-answer', text: 'نسخة محلية قديمة'},
    ];

    expect(
      mergeCourseChatHistories(remote, local).map(item => item.id),
    ).toEqual([
      'local-user-old',
      'local-answer-old',
      'server-user-new',
      'server-answer-new',
    ]);
  });

  it('keeps the live bubble id until the server reports a terminal turn', () => {
    const local = {
      id: 'assistant-local',
      role: 'assistant' as const,
      text: 'جزء أحدث من الرد',
      createdAt: 200,
      clientRequestId: 'request-live',
      deliveryStatus: 'streaming' as const,
    };
    const queuedRemote = {
      ...local,
      id: 'assistant-server',
      text: '',
      createdAt: 100,
      deliveryStatus: 'queued' as const,
    };

    expect(mergeCourseChatHistories([queuedRemote], [local])).toEqual([local]);

    const completedRemote = {
      ...queuedRemote,
      text: 'الرد النهائي',
      deliveryStatus: 'completed' as const,
    };
    expect(mergeCourseChatHistories([completedRemote], [local])).toEqual([
      completedRemote,
    ]);
  });

  it('keeps post-upload chat persistence under the account that started it', async () => {
    const owner = {epoch: 1, scope: 'user-a'} as const;
    await saveCourseChatHistory(
      '52',
      [
        {
          id: 'user-request-1',
          role: 'user',
          text: 'راجع الملف',
          createdAt: Date.now(),
          deliveryStatus: 'sent',
          contextEligible: true,
        },
      ],
      '7',
      owner,
    );

    expect(mockCaptureBoundary).not.toHaveBeenCalled();
    expect(AsyncStorage.setItem).toHaveBeenCalledWith(
      expect.stringContaining(':user-a:'),
      expect.any(String),
    );
    expect(mockAssertBoundary).toHaveBeenCalledWith(owner);
  });

  it('commits server attachment ids before releasing their local files', async () => {
    await saveCourseChatHistory('52', [
      {
        id: 'user-request-durable',
        role: 'user',
        text: 'راجع الملف',
        createdAt: Date.now(),
        deliveryStatus: 'sent',
        attachments: [
          {
            uploadId: 'upload-1',
            serverId: 'server-1',
            uri: '',
            name: 'ملف.pdf',
            type: 'application/pdf',
          },
        ],
      },
    ]);

    const storageCall = jest.mocked(AsyncStorage.setItem).mock
      .invocationCallOrder[0];
    const releaseCall = jest.mocked(retainLearnerDraftFiles).mock
      .invocationCallOrder[0];
    expect(storageCall).toBeLessThan(releaseCall);
    expect(retainLearnerDraftFiles).toHaveBeenCalledWith(
      expect.stringContaining('course-chat:'),
      [],
      'user-b',
    );
  });

  it('does not release another account history when ownership changes during load', async () => {
    await AsyncStorage.setItem(
      '@rokn/course-chat-history/v2:user-a:52:7',
      '[]',
    );
    mockAssertBoundary.mockImplementationOnce(() => {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    });

    await expect(
      loadCourseChatHistory('52', '7', {epoch: 1, scope: 'user-a'}),
    ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(retainLearnerDraftFiles).not.toHaveBeenCalled();
  });

  it('keeps readable history visible when cache-reference repair fails', async () => {
    await saveCourseChatHistory('52', [
      {
        id: 'assistant-readable',
        role: 'assistant',
        text: 'إجابة محفوظة',
        createdAt: 1,
        deliveryStatus: 'completed',
      },
    ]);
    jest
      .mocked(retainLearnerDraftFiles)
      .mockRejectedValueOnce(new Error('REFERENCE_REGISTRY_UNAVAILABLE'));

    await expect(loadCourseChatHistory('52')).resolves.toEqual([
      expect.objectContaining({id: 'assistant-readable', text: 'إجابة محفوظة'}),
    ]);
  });

  it('keeps an accepted turn pending so reopening reconciles the same request', async () => {
    await saveCourseChatHistory(
      '52',
      [
        {
          id: 'assistant-request-1',
          role: 'assistant',
          text: '',
          createdAt: Date.now(),
          clientRequestId: 'request-1',
          deliveryStatus: 'queued',
          contextEligible: false,
        },
      ],
      '7',
    );

    const [restored] = await loadCourseChatHistory('52', '7');
    expect(restored).toMatchObject({
      clientRequestId: 'request-1',
      deliveryStatus: 'queued',
    });
    expect(restored).not.toHaveProperty('pending');
  });

  it('restores a paused accepted turn as recoverable without a second flag', async () => {
    await saveCourseChatHistory(
      '52',
      [
        {
          id: 'assistant-request-2',
          role: 'assistant',
          text: 'الرد يستغرق وقتًا أطول',
          createdAt: Date.now(),
          clientRequestId: 'request-2',
          deliveryStatus: 'interrupted',
          errorCode: 'interrupted_turn',
          contextEligible: false,
        },
      ],
      '7',
    );

    await expect(loadCourseChatHistory('52', '7')).resolves.toEqual([
      expect.objectContaining({
        clientRequestId: 'request-2',
        deliveryStatus: 'interrupted',
        errorCode: 'interrupted_turn',
        contextEligible: false,
      }),
    ]);
  });

  it('keeps a project feedback draft under its sending account', async () => {
    const owner = {epoch: 1, scope: 'user-a'} as const;
    await saveProjectFeedbackDraft(
      '11111111-1111-4111-8111-111111111111',
      {
        text: 'راجع المشروع',
        attachments: [],
        updatedAt: Date.now(),
      },
      owner,
    );

    expect(mockCaptureBoundary).not.toHaveBeenCalled();
    expect(AsyncStorage.setItem).toHaveBeenCalledWith(
      expect.stringContaining(':user-a:'),
      expect.any(String),
    );
    expect(mockAssertBoundary).toHaveBeenCalledWith(owner);
  });

  it('threads the captured owner through both post-upload flows', () => {
    const chat = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/courseChat/useCourseChatTurn.ts',
      ),
      'utf8',
    );
    const conversation = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/courseChat/useCourseChatConversation.ts',
      ),
      'utf8',
    );
    const project = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/projectTransition/useProjectFeedback.ts',
      ),
      'utf8',
    );

    expect(chat).toContain(
      'const turnBoundary = await captureAccountSessionBoundary()',
    );
    expect(chat).toMatch(
      /saveCourseChatHistory\([\s\S]*?turnBoundary[\s\S]*?uploadCourseAssistantAttachment[\s\S]*?saveCourseChatHistory\([\s\S]*?turnBoundary/,
    );
    expect(project).toContain(
      'const boundary = await captureAccountSessionBoundary()',
    );
    expect(project).toMatch(
      /uploadProjectFeedbackAttachment[\s\S]*?saveProjectFeedbackDraft\([\s\S]*?boundary/,
    );
    expect(project).toContain('activeProjectIdRef.current === projectId');
    expect(project).toContain('activeThreadIdRef.current === threadId');
    expect(project).toMatch(
      /uploadProjectFeedbackAttachment[\s\S]*?if \(!ownsContext\(\)\) return;[\s\S]*?sendProjectFeedbackMessage/,
    );
    expect(chat).toMatch(
      /catch \(error: unknown\)[\s\S]*?ACCOUNT_CHANGED_DURING_REQUEST/,
    );
    expect(conversation).toContain('loadCourseAssistantHistory(');
    expect(conversation).toContain('mergeCourseChatHistories(');
    expect(conversation).toContain(
      'activeConversationRef.current === conversationScope',
    );
    expect(conversation).toContain(
      '(await getCurrentAccountStorageScope()) === accountScope',
    );
    expect(conversation).toContain('if (hasRecoverableTurn(reconciled))');
  });
});
