import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import * as DocumentPicker from 'expo-document-picker';

let mockBoundary = {scope: 'user-7', epoch: 1};
let mockForeground = true;
const mockCapture = jest.fn();
const mockConsent = jest.fn();
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
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => mockForeground,
}));
jest.mock('../src/services/aiConsent', () => ({
  requestAiConsent: (...args: unknown[]) => mockConsent(...args),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  courseIncludesAssistant: (course: {chatAvailable?: boolean}) => course.chatAvailable === true,
  loadCourseAssistantHistory: jest.fn(async () => []),
  askCourseAssistant: jest.fn(),
  pollCourseAssistantTurn: jest.fn(),
  cancelCourseAssistantTurn: jest.fn(),
  uploadCourseAssistantAttachment: jest.fn(async () => 'server-file-1'),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/assistant', () => ({
  pollCourseAssistantTurn: (...args: unknown[]) =>
    require('../src/components/VideoPlayer/courseLearningApi').pollCourseAssistantTurn(...args),
}));
jest.mock('../src/components/VideoPlayer/courseChat/persistence', () => ({
  loadCourseChatHistory: jest.fn(async () => []),
  saveCourseChatHistory: jest.fn(async () => undefined),
  mergeCourseChatHistories: (_remote: unknown[], local: unknown[]) => local,
}));
jest.mock('../src/services/roknApi', () => ({getFullTrackUpgradeQuote: jest.fn()}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  removeLearnerDraftFile: jest.fn(async () => undefined),
  cacheLearnerDraftFile: jest.fn(),
}));
jest.mock('../src/services/operationalTelemetry', () => ({reportClientError: jest.fn()}));
jest.mock('../src/utils/secureRandom', () => ({secureRandomUuid: () => 'request-1'}));

import {useCourseChat} from '../src/components/VideoPlayer/courseChat/useCourseChat';
import {getFullTrackUpgradeQuote} from '../src/services/roknApi';
import {askCourseAssistant, pollCourseAssistantTurn, uploadCourseAssistantAttachment} from '../src/components/VideoPlayer/courseLearningApi';
import {loadCourseChatHistory} from '../src/components/VideoPlayer/courseChat/persistence';
import {COURSE_CHAT_DEFAULT_POLL_WINDOW_MS} from '../src/components/VideoPlayer/courseChat/turnPolling';
import {cacheLearnerDraftFile, removeLearnerDraftFile} from '../src/services/learnerDraftFiles';
import type {CourseLearningData, CourseReel} from '../src/components/VideoPlayer/types';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(yes => {resolve = yes;});
  return {promise, resolve};
};
const course = {
  id: '3', title: 'الكورس', accessType: 'paid', chatAvailable: true,
  chatAttachmentsEnabled: true, chatAttachmentMaxFiles: 3,
} as CourseLearningData;
const answer = {
  text: 'الإجابة محفوظة', offline: false, turnStatus: 'completed' as const, clientRequestId: 'request-1',
};

describe('course chat consent owns the preparing conversation visit', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let chat: ReturnType<typeof useCourseChat>;
  function Harness({visible = true, id = '3', lessonId, enabled = true}: {
    visible?: boolean; id?: string; lessonId?: string; enabled?: boolean;
  }) {
    chat = useCourseChat({
      visible, course: {...course, id, chatAvailable: enabled},
      reel: lessonId ? ({lessonId} as CourseReel) : undefined,
    });
    return null;
  }
  const mount = async () => {
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    await act(async () => chat.setInput('اشرح لي هذه الفكرة'));
  };
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-7', epoch: 1};
    mockForeground = true;
    mockCapture.mockReset().mockImplementation(async () => ({...mockBoundary}));
    mockConsent.mockReset().mockResolvedValue(true);
    jest.mocked(askCourseAssistant).mockReset().mockResolvedValue(answer);
    jest.mocked(loadCourseChatHistory).mockReset().mockResolvedValue([]);
    // The real API always returns a Promise. A failed discovery read must
    // retire consent without fabricating a purchasable upgrade offer.
    jest.mocked(getFullTrackUpgradeQuote).mockReset().mockRejectedValue(new Error('offline'));
    jest.mocked(pollCourseAssistantTurn).mockReset();
    jest.mocked(DocumentPicker.getDocumentAsync).mockReset().mockResolvedValue({canceled: true, assets: null});
    jest.mocked(cacheLearnerDraftFile).mockReset().mockImplementation(async (_kind, file) => ({
      ...file, uri: 'file:///managed/work.pdf',
    }));
  });
  afterEach(async () => {
    await act(async () => {renderer?.unmount();});
    renderer = undefined;
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it.each(['close', 'close and reopen', 'background and return', 'different lesson', 'different course', 'different account', 'disabled entitlement', 'unmount'])(
    'does not send the old question after %s during consent', async departure => {
      const consent = deferred<boolean>();
      mockConsent.mockReturnValueOnce(consent.promise);
      await mount();
      await act(async () => chat.send());
      expect(chat.consentPending).toBe(true);
      const owns = mockConsent.mock.calls[0][1] as () => boolean;
      expect(owns()).toBe(true);
      await act(async () => {
        if (departure === 'unmount') renderer!.unmount();
        else if (departure === 'different lesson') renderer!.update(<Harness lessonId="9" />);
        else if (departure === 'different course') renderer!.update(<Harness id="4" />);
        else if (departure === 'different account') {
          mockBoundary = {scope: 'user-8', epoch: 2};
          renderer!.update(<Harness />);
        } else if (departure === 'disabled entitlement') renderer!.update(<Harness enabled={false} />);
        else if (departure === 'background and return') {
          mockForeground = false;
          renderer!.update(<Harness />);
        } else renderer!.update(<Harness visible={false} />);
      });
      if (departure === 'close and reopen' || departure === 'background and return') {
        mockForeground = true;
        await act(async () => {renderer!.update(<Harness />);});
      }
      expect(owns()).toBe(false);
      await act(async () => {consent.resolve(true);});
      expect(askCourseAssistant).not.toHaveBeenCalled();
      expect(uploadCourseAssistantAttachment).not.toHaveBeenCalled();
      if (departure === 'disabled entitlement') {
        expect(getFullTrackUpgradeQuote).toHaveBeenCalledWith('3', {requiredFeature: 'chat'});
        expect(chat.upgradeStatus).toBe('error');
      }
      if (departure === 'close' || departure === 'close and reopen' || departure === 'background and return')
        expect(chat.input).toBe('اشرح لي هذه الفكرة');
    },
  );

  it('does not even request consent after closing while account capture is pending', async () => {
    await mount();
    const capture = deferred<typeof mockBoundary>();
    mockCapture.mockReturnValueOnce(capture.promise);
    await act(async () => chat.send());
    await act(async () => {renderer!.update(<Harness visible={false} />);});
    await act(async () => {renderer!.update(<Harness />);});
    await act(async () => {capture.resolve({...mockBoundary});});
    expect(mockConsent).not.toHaveBeenCalled();
    expect(askCourseAssistant).not.toHaveBeenCalled();
  });

  it('freezes the intended text and attachments and prevents duplicate preparation taps', async () => {
    const consent = deferred<boolean>();
    mockConsent.mockReturnValueOnce(consent.promise);
    await mount();
    const file = {uri: 'file:///draft.png', name: 'work.png', type: 'image/png', uploadId: 'file-1'};
    await act(async () => chat.setAttachments([file]));
    await act(async () => {chat.send(); chat.send();});
    expect(mockConsent).toHaveBeenCalledTimes(1);
    expect(chat.isSendInFlight()).toBe(true);
    await act(async () => {chat.setInput('سؤال آخر'); chat.setAttachments([]);});
    expect(chat.input).toBe('اشرح لي هذه الفكرة');
    expect(chat.attachments).toEqual([file]);
    await act(async () => {consent.resolve(true);});
    expect(askCourseAssistant).toHaveBeenCalledTimes(1);
    expect(askCourseAssistant).toHaveBeenCalledWith(expect.objectContaining({
      message: 'اشرح لي هذه الفكرة', attachmentIds: ['server-file-1'],
    }));
    expect(chat.consentPending).toBe(false);
  });

  it('a retired consent finally cannot release a new visit preparation', async () => {
    const first = deferred<boolean>();
    const second = deferred<boolean>();
    mockConsent.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    await mount();
    await act(async () => chat.send());
    await act(async () => {renderer!.update(<Harness visible={false} />);});
    await act(async () => {renderer!.update(<Harness />);});
    await act(async () => chat.send());
    await act(async () => {first.resolve(true);});
    expect(chat.consentPending).toBe(true);
    expect(chat.isSendInFlight()).toBe(true);
    await act(async () => chat.send());
    expect(mockConsent).toHaveBeenCalledTimes(2);
    expect(askCourseAssistant).not.toHaveBeenCalled();
    await act(async () => {second.resolve(true);});
    expect(askCourseAssistant).toHaveBeenCalledTimes(1);
  });

  it('keeps the composer draft after declining and permits an explicit retry', async () => {
    mockConsent.mockResolvedValueOnce(false);
    await mount();
    await act(async () => chat.send());
    expect(chat.input).toBe('اشرح لي هذه الفكرة');
    expect(chat.consentPending).toBe(false);
    expect(askCourseAssistant).not.toHaveBeenCalled();
    await act(async () => chat.send());
    expect(askCourseAssistant).toHaveBeenCalledTimes(1);
  });

  it.each([false, true])(
    'owns explicit failed-turn retry through consent with departure=%s', async departed => {
      const requestId = 'previous-request';
      const consent = deferred<boolean>();
      mockConsent.mockReturnValueOnce(consent.promise);
      jest.mocked(loadCourseChatHistory).mockResolvedValue([
        {id: 'previous-user', role: 'user', text: 'سؤالي المحفوظ',
          createdAt: 1, clientRequestId: requestId, deliveryStatus: 'sent'},
        {id: 'previous-assistant', role: 'assistant', text: 'لم يصل سؤالك',
          createdAt: 2, clientRequestId: requestId, deliveryStatus: 'failed',
          errorCode: 'chat_turn_not_found', canRetry: true},
      ]);
      jest.mocked(pollCourseAssistantTurn).mockResolvedValue({
        ...answer, text: 'لم يصل سؤالك', turnStatus: 'failed',
        clientRequestId: requestId, code: 'chat_turn_not_found', canRetry: true,
      });
      jest.mocked(askCourseAssistant).mockResolvedValue({...answer, clientRequestId: requestId});
      await mount();
      await act(async () => chat.retry(requestId));
      expect(chat.consentPending).toBe(true);
      expect(pollCourseAssistantTurn).not.toHaveBeenCalled();
      if (departed) {
        await act(async () => {renderer!.update(<Harness visible={false} />);});
        await act(async () => {renderer!.update(<Harness />);});
      }
      await act(async () => {consent.resolve(true);});
      if (departed) {
        expect(pollCourseAssistantTurn).not.toHaveBeenCalled();
        expect(askCourseAssistant).not.toHaveBeenCalled();
      } else {
        expect(pollCourseAssistantTurn).toHaveBeenCalledWith(requestId);
        expect(askCourseAssistant).toHaveBeenCalledTimes(1);
        expect(askCourseAssistant).toHaveBeenCalledWith(expect.objectContaining({
          clientRequestId: requestId, message: 'سؤالي المحفوظ',
        }));
      }
    },
  );

  it('does not cancel a question already handed to the turn owner when its consent visit closes', async () => {
    const receipt = deferred<typeof answer>();
    jest.mocked(askCourseAssistant).mockReturnValueOnce(receipt.promise);
    await mount();
    await act(async () => chat.send());
    expect(askCourseAssistant).toHaveBeenCalledTimes(1);
    await act(async () => {renderer!.update(<Harness visible={false} />);});
    await act(async () => {receipt.resolve(answer);});
    expect(chat.messages).toContainEqual(expect.objectContaining({role: 'assistant', text: answer.text}));
    await act(async () => {renderer!.update(<Harness />);});
    expect(askCourseAssistant).toHaveBeenCalledTimes(1);
  });

  it('the controller gates send and retry through native picking and managed-copy completion', async () => {
    await mount();
    const native = deferred<DocumentPicker.DocumentPickerResult>();
    const cache = deferred<Awaited<ReturnType<typeof cacheLearnerDraftFile>>>();
    jest.mocked(DocumentPicker.getDocumentAsync).mockReturnValueOnce(native.promise);
    jest.mocked(cacheLearnerDraftFile).mockReturnValueOnce(cache.promise);
    let picking!: Promise<void>;
    await act(async () => {picking = chat.pickAttachments(); chat.send(); chat.retry('previous-request');});
    expect(mockConsent).not.toHaveBeenCalled();
    expect(pollCourseAssistantTurn).not.toHaveBeenCalled();
    await act(async () => {
      native.resolve({canceled: false, assets: [{uri: 'file:///picker/work.pdf', name: 'work.pdf', mimeType: 'application/pdf', lastModified: 1}]});
    });
    expect(cacheLearnerDraftFile).toHaveBeenCalledTimes(1);
    await act(async () => {chat.send(); chat.retry('previous-request');});
    expect(mockConsent).not.toHaveBeenCalled();
    expect(askCourseAssistant).not.toHaveBeenCalled();
    await act(async () => {
      cache.resolve({uri: 'file:///managed/work.pdf', fileName: 'work.pdf', type: 'application/pdf'});
      await picking;
    });
    await act(async () => chat.send());
    expect(mockConsent).toHaveBeenCalledTimes(1);
    expect(askCourseAssistant).toHaveBeenCalledWith(expect.objectContaining({attachmentIds: ['server-file-1']}));
  });

  it('the real conversation scope retires a copied attachment on a same-course lesson change', async () => {
    await mount();
    const cache = deferred<Awaited<ReturnType<typeof cacheLearnerDraftFile>>>();
    jest.mocked(DocumentPicker.getDocumentAsync).mockResolvedValueOnce({
      canceled: false, assets: [{uri: 'file:///picker/work.pdf', name: 'work.pdf', mimeType: 'application/pdf', lastModified: 1}],
    });
    jest.mocked(cacheLearnerDraftFile).mockReturnValueOnce(cache.promise);
    let picking!: Promise<void>;
    await act(async () => {picking = chat.pickAttachments();});
    await act(async () => {renderer!.update(<Harness lessonId="9" />);});
    await act(async () => {renderer!.update(<Harness />);});
    await act(async () => {cache.resolve({uri: 'file:///managed/work.pdf'}); await picking;});
    expect(chat.attachments).toEqual([]);
    // The file owner consumes only its first argument. Array.map also passes
    // the index/list; assert the exact cleanup batch, not callback arity.
    expect(jest.mocked(removeLearnerDraftFile).mock.calls.map(([file]) => file))
      .toEqual([expect.objectContaining({uri: 'file:///managed/work.pdf'})]);
    expect(askCourseAssistant).not.toHaveBeenCalled();
  });

  it('native-picker return can recover an older turn without losing the next-question draft', async () => {
    const pending = {
      ...answer, text: '', code: 'chat_answer_in_progress', turnStatus: 'queued' as const,
      retryAfterSeconds: 1,
    };
    jest.mocked(askCourseAssistant).mockResolvedValueOnce(pending);
    jest.mocked(pollCourseAssistantTurn).mockResolvedValue(pending);
    await mount();
    await act(async () => chat.send());
    await act(async () => {await jest.advanceTimersByTimeAsync(COURSE_CHAT_DEFAULT_POLL_WINDOW_MS + 1000);});
    expect(chat.messages).toContainEqual(expect.objectContaining({deliveryStatus: 'interrupted'}));
    expect(chat.isSendInFlight()).toBe(false);
    await act(async () => chat.setInput('السؤال التالي'));

    const native = deferred<DocumentPicker.DocumentPickerResult>();
    const receipt = deferred<typeof answer>();
    jest.mocked(DocumentPicker.getDocumentAsync).mockReturnValueOnce(native.promise);
    let picking!: Promise<void>;
    await act(async () => {picking = chat.pickAttachments();});
    mockForeground = false;
    await act(async () => {renderer!.update(<Harness />);});
    jest.mocked(pollCourseAssistantTurn).mockReturnValueOnce(receipt.promise);
    mockForeground = true;
    await act(async () => {renderer!.update(<Harness />);});
    expect(chat.isSendInFlight()).toBe(true);
    await act(async () => {
      native.resolve({canceled: false, assets: [{
        uri: 'file:///picker/work.pdf', name: 'work.pdf', mimeType: 'application/pdf', lastModified: 1,
      }]});
      await picking;
    });
    expect(chat.attachments).toEqual([expect.objectContaining({name: 'work.pdf'})]);
    expect(chat.input).toBe('السؤال التالي');
    await act(async () => {receipt.resolve(answer);});
    expect(chat.attachments).toEqual([expect.objectContaining({name: 'work.pdf'})]);
    expect(chat.input).toBe('السؤال التالي');
    expect(chat.messages).toContainEqual(expect.objectContaining({text: answer.text, deliveryStatus: 'completed'}));
    expect(askCourseAssistant).toHaveBeenCalledTimes(1);
    expect(uploadCourseAssistantAttachment).not.toHaveBeenCalled();
  });
});
