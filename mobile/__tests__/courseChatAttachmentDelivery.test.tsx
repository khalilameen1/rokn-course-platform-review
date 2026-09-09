import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';

let mockOwner = {scope: 'user-a', epoch: 1};
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockOwner}),
  getCurrentAccountStorageScope: async () => mockOwner.scope,
  assertAccountSessionBoundary: (owner: typeof mockOwner) => {
    if (owner.scope !== mockOwner.scope || owner.epoch !== mockOwner.epoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  askCourseAssistant: jest.fn(),
  cancelCourseAssistantTurn: jest.fn(),
  pollCourseAssistantTurn: jest.fn(),
  uploadCourseAssistantAttachment: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/assistant', () => ({
  pollCourseAssistantTurn: jest.fn(),
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));

import {useCourseChatTurn} from '../src/components/VideoPlayer/courseChat/useCourseChatTurn';
import {
  askCourseAssistant,
  pollCourseAssistantTurn,
  uploadCourseAssistantAttachment,
} from '../src/components/VideoPlayer/courseLearningApi';
import {pollCourseAssistantTurn as pollAcceptedStatus} from '../src/components/VideoPlayer/courseLearning/assistant';
import {
  loadCourseChatHistory,
  quiesceCourseChatPersistence,
  saveCourseChatHistory,
} from '../src/components/VideoPlayer/courseChat/persistence';
import {
  clearAccountLearnerDraftFiles,
  removeLearnerDraftFile,
} from '../src/services/learnerDraftFiles';
import type {
  ChatMessage,
  CourseLearningData,
} from '../src/components/VideoPlayer/types';

describe('uploaded course chat attachment delivery', () => {
  it.each([
    'registry_read',
    'registry_write',
    'local_remove',
    'registry_failure',
    'initial_history_failure',
    'uploaded_history_failure',
    'initial_reference_failure',
    'account_change',
    'normal',
    'lost_ack',
  ])('honors attachment durability and delivery across %s', async stage => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockOwner = {scope: stage, epoch: 1};
    const disk = new Map<string, string>();
    const files = new Map<string, string>();
    const accountDirectory = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${stage}`;
    const localPath = `${accountDirectory}/course_chat/image.png`;
    files.set(localPath, 'image-bytes');
    let release!: () => void;
    const blocked = new Promise<void>(resolve => {
      release = resolve;
    });
    let serverIdDurable = false;
    let cleanupStarted = false;
    const stall = async (operation: string) => {
      if (serverIdDurable && stage === operation) {
        cleanupStarted = true;
        await blocked;
      }
    };
    jest.mocked(RNFS.mkdir).mockResolvedValue(undefined);
    jest
      .mocked(RNFS.exists)
      .mockImplementation(
        async path => path === accountDirectory || files.has(path),
      );
    jest.mocked(RNFS.readFile).mockImplementation(async path => {
      await stall('registry_read');
      return files.get(path) || '{}';
    });
    jest.mocked(RNFS.writeFile).mockImplementation(async (path, value) => {
      if (
        (stage === 'registry_failure' && serverIdDurable) ||
        (stage === 'initial_reference_failure' && !serverIdDurable)
      ) {
        throw new Error('native registry unavailable');
      }
      await stall('registry_write');
      files.set(path, String(value));
    });
    jest.mocked(RNFS.moveFile).mockImplementation(async (source, target) => {
      files.set(target, files.get(source) || '{}');
      files.delete(source);
    });
    jest.mocked(RNFS.unlink).mockImplementation(async path => {
      if (path === localPath) await stall('local_remove');
      if (path === accountDirectory) {
        for (const key of files.keys())
          if (key.startsWith(`${path}/`)) files.delete(key);
      }
      files.delete(path);
    });
    (AsyncStorage.getItem as jest.Mock).mockImplementation(
      async key => disk.get(key) ?? null,
    );
    (AsyncStorage.setItem as jest.Mock).mockImplementation(
      async (key: string, value: string) => {
        if (
          stage === 'initial_history_failure' ||
          (stage === 'uploaded_history_failure' &&
            value.includes('uploaded-image-id'))
        ) {
          throw new Error('native history unavailable');
        }
        disk.set(key, value);
        if (value.includes('uploaded-image-id')) serverIdDurable = true;
        if (stage === 'account_change' && serverIdDurable)
          mockOwner = {scope: 'replacement', epoch: 2};
      },
    );
    jest
      .mocked(uploadCourseAssistantAttachment)
      .mockResolvedValue('uploaded-image-id');
    jest.mocked(askCourseAssistant).mockResolvedValue({
      text: 'الإجابة على الصورة',
      offline: false,
      turnStatus: 'completed',
    });
    if (stage === 'lost_ack') {
      // This is the service's timeout/5xx contract, not a definitive rejected
      // turn: it must be looked up with the same identity before any retry.
      jest
        .mocked(askCourseAssistant)
        .mockImplementationOnce(async ({clientRequestId}) => ({
          text: '',
          offline: true,
          turnStatus: 'queued',
          code: 'chat_answer_in_progress',
          retryAfterSeconds: 2,
          clientRequestId,
        }));
      jest.mocked(pollAcceptedStatus).mockResolvedValue({
        text: '',
        offline: true,
        turnStatus: 'queued',
        code: 'chat_answer_in_progress',
        retryAfterSeconds: 2,
      });
    }
    const scope = `${stage}:3:course`;
    const messagesRef: {current: ChatMessage[]} = {current: []};
    let turn!: ReturnType<typeof useCourseChatTurn>;
    const params = {
      activeAccountScope: {current: stage},
      activeConversation: {current: scope},
      assistantIncluded: true,
      attachmentsRef: {
        current: [
          {
            uploadId: 'draft-image',
            uri: `file://${localPath}`,
            name: 'image.png',
            type: 'image/png',
          },
        ],
      },
      commitAttachments: jest.fn(),
      commitMessages: (
        update: ChatMessage[] | ((current: ChatMessage[]) => ChatMessage[]),
      ) => {
        messagesRef.current =
          typeof update === 'function' ? update(messagesRef.current) : update;
      },
      conversationGeneration: {current: 1},
      conversationScope: scope,
      course: {
        id: '3',
        accessType: 'paid',
        chatAvailable: true,
      } as CourseLearningData,
      hydratedConversation: {current: scope},
      hydrationRecoveryRevision: 0,
      inFlightAttachmentIds: {current: new Set<string>()},
      input: 'هل الرسم صحيح؟',
      interactive: true,
      messagesRef,
      recordServerBlock: jest.fn(),
      scheduleScrollToEnd: jest.fn(),
      setInput: jest.fn(),
      upgraded: false,
    };
    const Harness = () => {
      turn = useCourseChatTurn(params);
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    let laterSave: Promise<void> | undefined;
    let laterFilePath: string | undefined;
    let accountCleanup: Promise<void> | undefined;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      await act(async () => {
        turn.send();
        await jest.advanceTimersByTimeAsync(
          stage === 'lost_ack' ? 34000 : 1000,
        );
      });
      const failedDurability = [
        'initial_history_failure',
        'uploaded_history_failure',
        'initial_reference_failure',
      ].includes(stage);
      if (failedDurability || stage === 'account_change') {
        expect(askCourseAssistant).not.toHaveBeenCalled();
        expect(files.has(localPath)).toBe(true);
        expect(uploadCourseAssistantAttachment).toHaveBeenCalledTimes(
          stage === 'uploaded_history_failure' || stage === 'account_change'
            ? 1
            : 0,
        );
      } else if (stage === 'lost_ack') {
        const requestId =
          jest.mocked(askCourseAssistant).mock.calls[0][0].clientRequestId!;
        expect(requestId).toBeTruthy();
        const user = messagesRef.current.find(
          message => message.role === 'user',
        );
        expect(user).toMatchObject({
          clientRequestId: requestId,
          attachments: [{serverId: 'uploaded-image-id', uri: ''}],
        });
        expect(messagesRef.current).toContainEqual(
          expect.objectContaining({
            role: 'assistant',
            clientRequestId: requestId,
            deliveryStatus: 'interrupted',
            canRetry: true,
          }),
        );
        const durable = await loadCourseChatHistory('3', undefined, {
          ...mockOwner,
        });
        expect(durable).toContainEqual(
          expect.objectContaining({
            role: 'user',
            clientRequestId: requestId,
            attachments: [
              expect.objectContaining({serverId: 'uploaded-image-id', uri: ''}),
            ],
          }),
        );
        expect(files.has(localPath)).toBe(false);
        expect(askCourseAssistant).toHaveBeenCalledTimes(1);
        expect(uploadCourseAssistantAttachment).toHaveBeenCalledTimes(1);
        jest.mocked(pollCourseAssistantTurn).mockResolvedValueOnce({
          text: 'الجواب المحفوظ',
          offline: false,
          turnStatus: 'completed',
          clientRequestId: requestId,
        });
        await act(async () => {
          turn.retry(requestId);
          await jest.advanceTimersByTimeAsync(0);
        });
        expect(pollCourseAssistantTurn).toHaveBeenCalledWith(requestId);
        expect(messagesRef.current).toContainEqual(
          expect.objectContaining({
            role: 'assistant',
            clientRequestId: requestId,
            text: 'الجواب المحفوظ',
            deliveryStatus: 'completed',
          }),
        );
        expect(askCourseAssistant).toHaveBeenCalledTimes(1);
        expect(uploadCourseAssistantAttachment).toHaveBeenCalledTimes(1);
      } else {
        expect(serverIdDurable).toBe(true);
        if (!['normal', 'registry_failure'].includes(stage))
          expect(cleanupStarted).toBe(true);
        expect(askCourseAssistant).toHaveBeenCalledTimes(1);
        expect(jest.mocked(askCourseAssistant).mock.calls[0][0]).toMatchObject({
          attachmentIds: ['uploaded-image-id'],
          message: 'هل الرسم صحيح؟',
        });
        expect(messagesRef.current).toContainEqual(
          expect.objectContaining({
            role: 'assistant',
            text: 'الإجابة على الصورة',
            deliveryStatus: 'completed',
          }),
        );
        expect(turn.sending).toBe(false);
        expect(uploadCourseAssistantAttachment).toHaveBeenCalledTimes(1);
      }
      if (stage === 'registry_read') {
        // The old raw registry operation still owns its place in the file
        // queue even after sending succeeds. A new draft's required retain
        // must run after it, not be erased when that cleanup finally lands.
        laterFilePath = localPath.replace('image.png', 'next.png');
        files.set(laterFilePath, 'next-image');
        const nextMessage: ChatMessage = {
          id: 'new-user',
          role: 'user',
          text: 'سؤال جديد',
          createdAt: Date.now(),
          attachments: [
            {
              uploadId: 'next-image',
              uri: `file://${laterFilePath}`,
              name: 'next.png',
              type: 'image/png',
            },
          ],
        };
        let saved = false;
        laterSave = saveCourseChatHistory('3', [nextMessage], undefined, {
          ...mockOwner,
        }).then(() => {
          saved = true;
        });
        await jest.advanceTimersByTimeAsync(1000);
        expect(saved).toBe(false);
        expect(askCourseAssistant).toHaveBeenCalledTimes(1);
      }
      if (stage === 'registry_write') {
        let accountCleared = false;
        accountCleanup = clearAccountLearnerDraftFiles(stage).then(() => {
          accountCleared = true;
        });
        await jest.advanceTimersByTimeAsync(1000);
        expect(accountCleared).toBe(false);
      }
    } finally {
      release();
      await act(async () => {
        await jest.advanceTimersByTimeAsync(0);
        renderer?.unmount();
      });
      await quiesceCourseChatPersistence();
      await laterSave;
      await accountCleanup;
      if (
        ![
          'initial_history_failure',
          'uploaded_history_failure',
          'initial_reference_failure',
          'account_change',
        ].includes(stage)
      ) {
        await removeLearnerDraftFile({uri: `file://${localPath}`});
      }
      jest.useRealTimers();
    }
    if (laterFilePath) {
      const registry = JSON.parse(
        files.get(
          `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${stage}/.references.json`,
        ) || '{}',
      );
      expect(registry['course-chat:3:course'].paths).toEqual([laterFilePath]);
      expect(files.has(laterFilePath)).toBe(true);
      expect(files.has(localPath)).toBe(false);
    }
    if (accountCleanup) expect(files.size).toBe(0);
  });
});
