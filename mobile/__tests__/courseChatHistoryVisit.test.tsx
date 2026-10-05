import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

let mockBoundary = {scope: 'user-a', epoch: 1};
let mockForeground = true;
const mockCapture = jest.fn();
jest.mock('react-redux', () => ({useSelector: () => ({id: mockBoundary.scope})}));
jest.mock('expo-document-picker', () => ({getDocumentAsync: jest.fn()}));
jest.mock('../src/constants/helpers', () => ({
  sessionIdentityKey: () => mockBoundary.scope,
  captureAccountSessionBoundary: () => mockCapture(),
  getCurrentAccountStorageScope: async () => mockBoundary.scope,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (boundary.scope !== mockBoundary.scope || boundary.epoch !== mockBoundary.epoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/hooks/useAppActiveState', () => ({useAppForegroundState: () => mockForeground}));
jest.mock('../src/services/aiConsent', () => ({requestAiConsent: jest.fn(async () => true)}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  courseIncludesAssistant: (course: {chatAvailable?: boolean}) => course.chatAvailable === true,
  loadCourseAssistantHistory: jest.fn(),
  askCourseAssistant: jest.fn(),
  pollCourseAssistantTurn: jest.fn(),
  cancelCourseAssistantTurn: jest.fn(),
  uploadCourseAssistantAttachment: jest.fn(async () => 'server-file'),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/assistant', () => ({
  pollCourseAssistantTurn: (...args: unknown[]) =>
    require('../src/components/VideoPlayer/courseLearningApi').pollCourseAssistantTurn(...args),
}));
// Keep the production logical-turn merger. Only native durable IO is replaced.
jest.mock('../src/components/VideoPlayer/courseChat/persistence', () => ({
  ...jest.requireActual('../src/components/VideoPlayer/courseChat/persistence'),
  loadCourseChatHistory: jest.fn(),
  saveCourseChatHistory: jest.fn(async () => undefined),
}));
jest.mock('../src/services/roknApi', () => ({getFullTrackUpgradeQuote: jest.fn(async () => ({
  upgradeAvailable: false, availablePlanCodes: [],
}))}));
jest.mock('../src/services/learnerDraftFiles', () => ({removeLearnerDraftFile: jest.fn(async () => undefined)}));
jest.mock('../src/services/operationalTelemetry', () => ({reportClientError: jest.fn()}));
jest.mock('../src/utils/secureRandom', () => ({secureRandomUuid: () => 'request-1'}));

import {useCourseChat} from '../src/components/VideoPlayer/courseChat/useCourseChat';
import {askCourseAssistant, loadCourseAssistantHistory, pollCourseAssistantTurn, uploadCourseAssistantAttachment} from '../src/components/VideoPlayer/courseLearningApi';
import {loadCourseChatHistory, saveCourseChatHistory} from '../src/components/VideoPlayer/courseChat/persistence';
import {removeLearnerDraftFile} from '../src/services/learnerDraftFiles';
import type {ChatMessage, CourseLearningData, CourseReel} from '../src/components/VideoPlayer/types';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {resolve = done;});
  return {promise, resolve};
};
const message = (id: string, text = id): ChatMessage => ({
  id, text, role: 'assistant', deliveryStatus: 'completed', createdAt: 1,
});
const course = {
  id: '3', title: 'الكورس', accessType: 'paid', chatAvailable: true,
  chatAttachmentsEnabled: true, chatAttachmentMaxFiles: 3,
} as CourseLearningData;
const file = {uploadId: 'next-file', uri: 'file:///managed/next.pdf', name: 'next.pdf', type: 'application/pdf'};

describe('remote course chat history belongs to an open foreground visit', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let chat: ReturnType<typeof useCourseChat>;
  type Props = {visible?: boolean; id?: string; lessonId?: string; entitled?: boolean};
  function Harness({visible = true, id = '3', lessonId, entitled = true}: Props) {
    chat = useCourseChat({
      visible, course: {...course, id, chatAvailable: entitled},
      reel: lessonId ? ({lessonId} as CourseReel) : undefined,
    });
    return null;
  }
  const mount = async (props: Props = {}) => {
    await act(async () => {renderer = TestRenderer.create(<Harness {...props} />);});
  };
  const update = async (props: Props = {}) => {
    await act(async () => {renderer!.update(<Harness {...props} />);});
  };
  const draft = async () => {
    await act(async () => {chat.setInput('السؤال التالي'); chat.setAttachments([file]);});
  };
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-a', epoch: 1};
    mockForeground = true;
    mockCapture.mockReset().mockImplementation(async () => ({...mockBoundary}));
    jest.mocked(loadCourseChatHistory).mockReset().mockResolvedValue([]);
    jest.mocked(loadCourseAssistantHistory).mockReset().mockResolvedValue([]);
    jest.mocked(askCourseAssistant).mockReset().mockResolvedValue({
      text: 'رد السؤال الحالي', offline: false, turnStatus: 'completed', clientRequestId: 'request-1',
    });
    jest.mocked(pollCourseAssistantTurn).mockReset().mockResolvedValue({
      text: 'رد مستعاد', offline: false, turnStatus: 'completed', clientRequestId: 'pending-1',
    });
    jest.mocked(uploadCourseAssistantAttachment).mockReset().mockResolvedValue('server-file');
  });
  afterEach(async () => {
    await act(async () => {renderer?.unmount();});
    renderer = undefined;
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it.each(['reopen', 'foreground'])(
    'retries a failed GET on %s without rehydrating or clearing the composer', async returnMode => {
      jest.mocked(loadCourseChatHistory).mockResolvedValueOnce([message('local')]);
      jest.mocked(loadCourseAssistantHistory).mockRejectedValueOnce(new Error('offline'));
      await mount();
      expect(chat.hydrated).toBe(true);
      expect(chat.hydrationError).toBe('');
      await draft();
      if (returnMode === 'reopen') await update({visible: false});
      else {mockForeground = false; await update();}
      jest.mocked(loadCourseAssistantHistory).mockResolvedValueOnce([message('remote')]);
      mockForeground = true;
      await update();
      expect(loadCourseAssistantHistory).toHaveBeenCalledTimes(2);
      expect(loadCourseChatHistory).toHaveBeenCalledTimes(1);
      expect(chat.messages.map(row => row.id)).toEqual(expect.arrayContaining(['local', 'remote']));
      expect(chat.input).toBe('السؤال التالي');
      expect(chat.attachments).toEqual([file]);
      expect(removeLearnerDraftFile).not.toHaveBeenCalled();
      expect(askCourseAssistant).not.toHaveBeenCalled();
    },
  );

  it('hydrates while hidden but only reads remote history when the chat opens', async () => {
    const local = deferred<ChatMessage[]>();
    jest.mocked(loadCourseChatHistory).mockReturnValueOnce(local.promise);
    await mount({visible: false});
    await act(async () => {local.resolve([message('local')]);});
    expect(chat.hydrated).toBe(true);
    expect(loadCourseAssistantHistory).not.toHaveBeenCalled();
    await update();
    expect(loadCourseAssistantHistory).toHaveBeenCalledTimes(1);
    expect(loadCourseChatHistory).toHaveBeenCalledTimes(1);
  });

  it('does not reset an existing composer when assistant access becomes available', async () => {
    await mount({entitled: false});
    await draft();
    expect(loadCourseAssistantHistory).not.toHaveBeenCalled();
    await update({entitled: true});
    expect(loadCourseAssistantHistory).toHaveBeenCalledTimes(1);
    expect(loadCourseChatHistory).toHaveBeenCalledTimes(1);
    expect(chat.input).toBe('السؤال التالي');
    expect(chat.attachments).toEqual([file]);
  });

  it('cannot launch a retired visit GET after a delayed account capture', async () => {
    await mount({visible: false});
    const capture = deferred<typeof mockBoundary>();
    mockCapture.mockReturnValueOnce(capture.promise);
    await update();
    await update({visible: false});
    await update();
    expect(loadCourseAssistantHistory).toHaveBeenCalledTimes(1);
    await act(async () => {capture.resolve({...mockBoundary});});
    expect(loadCourseAssistantHistory).toHaveBeenCalledTimes(1);
  });

  it.each(['close and reopen', 'background and return', 'lesson', 'course', 'account', 'unmount'])(
    'rejects an obsolete history response after %s', async departure => {
      const oldRead = deferred<ChatMessage[]>();
      jest.mocked(loadCourseAssistantHistory).mockReturnValueOnce(oldRead.promise);
      await mount();
      if (departure === 'unmount') await act(async () => {renderer!.unmount();});
      else if (departure === 'lesson') await update({lessonId: '9'});
      else if (departure === 'course') await update({id: '4'});
      else if (departure === 'account') {
        mockBoundary = {scope: 'user-b', epoch: 2};
        await update();
      } else if (departure === 'close and reopen') {
        await update({visible: false});
        jest.mocked(loadCourseAssistantHistory).mockResolvedValueOnce([message('new-visit')]);
        await update();
      } else {
        mockForeground = false; await update();
        mockForeground = true;
        jest.mocked(loadCourseAssistantHistory).mockResolvedValueOnce([message('new-visit')]);
        await update();
      }
      await act(async () => {oldRead.resolve([message('old-visit')]);});
      expect(chat.messages.some(row => row.id === 'old-visit')).toBe(false);
      if (departure.endsWith('reopen') || departure.endsWith('return'))
        expect(chat.messages.some(row => row.id === 'new-visit')).toBe(true);
    },
  );

  it.each(['different account', 'same account new epoch'])(
    'rejects remote history from a retired %s even before a render', async change => {
      const read = deferred<ChatMessage[]>();
      jest.mocked(loadCourseAssistantHistory).mockReturnValueOnce(read.promise);
      await mount();
      mockBoundary = {scope: change === 'different account' ? 'user-b' : 'user-a', epoch: 2};
      await act(async () => {read.resolve([message('retired-session')]);});
      expect(chat.messages.some(row => row.id === 'retired-session')).toBe(false);
    },
  );

  it('merges against the live transcript so a stale queued copy cannot erase a completed send', async () => {
    const read = deferred<ChatMessage[]>();
    jest.mocked(loadCourseAssistantHistory).mockReturnValueOnce(read.promise);
    await mount();
    await act(async () => chat.setInput('سؤال مدفوع'));
    await act(async () => chat.send());
    const settled = chat.messages.find(row => row.clientRequestId === 'request-1' && row.role === 'assistant')!;
    expect(settled.deliveryStatus).toBe('completed');
    await draft();
    await act(async () => {read.resolve([{
      ...message('server-assistant', ''), clientRequestId: 'request-1', deliveryStatus: 'queued',
    }]);});
    expect(chat.messages.filter(row => row.clientRequestId === 'request-1' && row.role === 'assistant')).toEqual([settled]);
    expect(chat.input).toBe('السؤال التالي');
    expect(chat.attachments).toEqual([file]);
    expect(askCourseAssistant).toHaveBeenCalledTimes(1);
    expect(pollCourseAssistantTurn).not.toHaveBeenCalled();
  });

  it('hands a canonical pending turn to existing status recovery without resending or clearing the next question', async () => {
    const read = deferred<ChatMessage[]>();
    jest.mocked(loadCourseAssistantHistory).mockReturnValueOnce(read.promise);
    await mount();
    await draft();
    await act(async () => {read.resolve([
      {...message('server-user', 'السؤال السابق'), role: 'user', clientRequestId: 'pending-1'},
      {...message('server-assistant', ''), clientRequestId: 'pending-1', deliveryStatus: 'queued'},
    ]);});
    expect(pollCourseAssistantTurn).toHaveBeenCalledWith('pending-1');
    expect(askCourseAssistant).not.toHaveBeenCalled();
    expect(chat.messages).toContainEqual(expect.objectContaining({text: 'رد مستعاد', deliveryStatus: 'completed'}));
    expect(chat.input).toBe('السؤال التالي');
    expect(chat.attachments).toEqual([file]);
  });

  it.each(['send', 'retry'])(
    'preserves restored history at attachment and request checkpoints during %s', async action => {
      const originalRequestId = action === 'retry' ? 'failed-1' : 'request-1';
      if (action === 'retry') {
        jest.mocked(loadCourseChatHistory).mockResolvedValueOnce([
          {...message('local-user', 'السؤال السابق'), role: 'user',
            deliveryStatus: 'failed', clientRequestId: originalRequestId, attachments: [file]},
          {...message('local-assistant', 'حاول مرة أخرى'), deliveryStatus: 'failed',
            clientRequestId: originalRequestId, canRetry: true},
        ]);
        jest.mocked(pollCourseAssistantTurn).mockResolvedValueOnce({
          text: 'لم يكتمل الرد', offline: false, turnStatus: 'failed', canRetry: true,
          code: 'chat_turn_failed', clientRequestId: originalRequestId,
        });
      }
      jest.mocked(loadCourseAssistantHistory).mockRejectedValueOnce(new Error('offline'));
      const upload = deferred<string>();
      jest.mocked(uploadCourseAssistantAttachment).mockReturnValueOnce(upload.promise);
      const answer = deferred<Awaited<ReturnType<typeof askCourseAssistant>>>();
      jest.mocked(askCourseAssistant).mockReturnValueOnce(answer.promise);
      await mount();
      if (action === 'send') {
        await draft();
        await act(async () => chat.send());
      } else await act(async () => chat.retry(originalRequestId));
      expect(uploadCourseAssistantAttachment).toHaveBeenCalledTimes(1);
      const liveBubble = chat.messages.find(row => row.role === 'assistant' && row.clientRequestId === originalRequestId)!;
      await update({visible: false});
      jest.mocked(loadCourseAssistantHistory).mockResolvedValueOnce([
        message('restored-before-upload'),
        {...message('server-current', 'النسخة القديمة من المحاولة'), clientRequestId: originalRequestId, deliveryStatus: 'failed'},
      ]);
      await update();
      expect(chat.messages.some(row => row.id === 'restored-before-upload')).toBe(true);
      expect(chat.messages.find(row => row.clientRequestId === originalRequestId && row.role === 'assistant')!.id).toBe(liveBubble.id);
      await act(async () => {upload.resolve('server-file');});
      expect(askCourseAssistant).toHaveBeenCalledTimes(1);
      expect(chat.messages.some(row => row.id === 'restored-before-upload')).toBe(true);
      const uploadCheckpoint = jest.mocked(saveCourseChatHistory).mock.calls.find(([, rows]) =>
        rows.some(row => row.attachments?.some(attachment => attachment.serverId === 'server-file')),
      );
      expect(uploadCheckpoint?.[1].some(row => row.id === 'restored-before-upload')).toBe(true);
      expect(chat.messages.find(row => row.id === liveBubble.id)!.clientRequestId).toBe('request-1');

      // A fresh retry has changed its immutable id. A later GET must protect
      // that actual flight id, not the old failed request or every pending row.
      await update({visible: false});
      jest.mocked(loadCourseAssistantHistory).mockResolvedValueOnce([
        message('restored-during-answer'),
        {...message('server-new-current', 'لقطة رد غير مملوكة'), clientRequestId: 'request-1'},
      ]);
      await update();
      expect(chat.messages.some(row => row.id === 'restored-during-answer')).toBe(true);
      expect(chat.messages.find(row => row.id === liveBubble.id)).toBeDefined();
      await act(async () => {answer.resolve({
        text: 'رد المحاولة الحالية', offline: false, turnStatus: 'completed', clientRequestId: 'request-1',
      });});
      expect(chat.messages.map(row => row.id)).toEqual(expect.arrayContaining([
        'restored-before-upload', 'restored-during-answer', liveBubble.id,
      ]));
      expect(chat.messages.find(row => row.id === liveBubble.id)).toMatchObject({
        text: 'رد المحاولة الحالية', deliveryStatus: 'completed',
      });
      expect(askCourseAssistant).toHaveBeenCalledTimes(1);
      expect(pollCourseAssistantTurn).toHaveBeenCalledTimes(action === 'retry' ? 1 : 0);
      await update({visible: false});
      jest.mocked(loadCourseAssistantHistory).mockResolvedValueOnce([
        {...message('server-terminal', 'النتيجة الرسمية بعد انتهاء المالك'), clientRequestId: 'request-1'},
      ]);
      await update();
      expect(chat.messages).toContainEqual(expect.objectContaining({id: 'server-terminal'}));
    },
  );
});
