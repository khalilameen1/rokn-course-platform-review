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
jest.mock('../src/constants/api', () => ({publicRequest: {delete: jest.fn(), get: jest.fn()}}));
jest.mock('../src/services/aiConsent', () => ({requestAiConsent: jest.fn(async () => true)}));
jest.mock('../src/services/productFeatures', () => ({isProductFeatureEnabled: async () => true}));
jest.mock('../src/services/systemActions', () => ({openExternalUrlOnce: jest.fn()}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  courseIncludesAssistant: () => true,
  loadCourseAssistantHistory: jest.fn(async () => []),
  askCourseAssistant: jest.fn(),
  cancelCourseAssistantTurn: (...args: unknown[]) =>
    require('../src/components/VideoPlayer/courseLearning/assistant').cancelCourseAssistantTurn(...args),
  pollCourseAssistantTurn: (...args: unknown[]) =>
    require('../src/components/VideoPlayer/courseLearning/assistant').pollCourseAssistantTurn(...args),
  uploadCourseAssistantAttachment: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseChat/persistence', () => ({
  ...jest.requireActual('../src/components/VideoPlayer/courseChat/persistence'),
  loadCourseChatHistory: jest.fn(async () => []),
  saveCourseChatHistory: jest.fn(async () => undefined),
}));
jest.mock('../src/services/roknApi', () => ({getFullTrackUpgradeQuote: jest.fn()}));
jest.mock('../src/services/learnerDraftFiles', () => ({removeLearnerDraftFile: jest.fn(async () => undefined)}));
jest.mock('../src/services/operationalTelemetry', () => ({reportClientError: jest.fn()}));
jest.mock('../src/utils/secureRandom', () => ({secureRandomUuid: jest.fn()}));

import {useCourseChat} from '../src/components/VideoPlayer/courseChat/useCourseChat';
import {askCourseAssistant} from '../src/components/VideoPlayer/courseLearningApi';
import {cancelCourseAssistantTurn} from '../src/components/VideoPlayer/courseLearning/assistant';
import {publicRequest} from '../src/constants/api';
import {secureRandomUuid} from '../src/utils/secureRandom';
import type {CourseLearningData, CourseReel} from '../src/components/VideoPlayer/types';

const requestId = 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388';
const secondRequestId = '9ec29211-6056-4996-a17f-b2cae4a12be7';
type Answer = Awaited<ReturnType<typeof askCourseAssistant>>;
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((yes, no) => {resolve = yes; reject = no;});
  return {promise, resolve, reject};
};
const cancelledReceipt = {data: {success: true, code: 'chat_turn_cancelled'}};
const completed: Answer = {text: 'الإجابة الرسمية', offline: false, turnStatus: 'completed', clientRequestId: requestId};
const course = {id: '3', title: 'الكورس', accessType: 'paid', chatAvailable: true} as CourseLearningData;

describe('course chat cancellation owns preparation and its dispatched receipt', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let chat: ReturnType<typeof useCourseChat>;
  let sendReceipt: ReturnType<typeof deferred<Answer>>;
  type Props = {visible?: boolean; id?: string; lessonId?: string};
  function Harness({visible = true, id = '3', lessonId}: Props) {
    chat = useCourseChat({
      visible, course: {...course, id},
      reel: lessonId ? ({lessonId} as CourseReel) : undefined,
    });
    return null;
  }
  const update = async (props: Props = {}) => {
    await act(async () => {renderer!.update(<Harness {...props} />);});
  };
  const mountAndSend = async () => {
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    await act(async () => chat.setInput('اشرح الفكرة'));
    await act(async () => chat.send());
    expect(askCourseAssistant).toHaveBeenCalledTimes(1);
  };
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-a', epoch: 1};
    mockForeground = true;
    mockCapture.mockReset().mockImplementation(async () => ({...mockBoundary}));
    jest.mocked(secureRandomUuid).mockReset().mockReturnValueOnce(requestId).mockReturnValue(secondRequestId);
    sendReceipt = deferred<Answer>();
    jest.mocked(askCourseAssistant).mockReset().mockReturnValueOnce(sendReceipt.promise);
    jest.mocked(publicRequest.delete).mockReset().mockResolvedValue(cancelledReceipt as never);
    jest.mocked(publicRequest.get).mockReset().mockResolvedValue({data: {
      success: true, data: {message: completed.text, turn_status: 'completed', client_request_id: requestId},
    }} as never);
  });
  afterEach(async () => {
    await act(async () => {renderer?.unmount();});
    renderer = undefined;
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it('keeps stop single-flight and delays recovery until its receipt even across reopening', async () => {
    const deletion = deferred<typeof cancelledReceipt>();
    jest.mocked(publicRequest.delete).mockReturnValueOnce(deletion.promise as never);
    await mountAndSend();
    let stopping!: Promise<void>;
    await act(async () => {stopping = chat.stop();});
    await act(async () => {void chat.stop(); chat.retry(requestId); chat.send();});
    expect(publicRequest.delete).toHaveBeenCalledTimes(1);
    expect(chat.isSendInFlight()).toBe(true);
    await update({visible: false});
    await update();
    await act(async () => {sendReceipt.resolve(completed);});
    expect(publicRequest.get).not.toHaveBeenCalled();
    expect(chat.isSendInFlight()).toBe(true);
    // The backend says this answer could not be cancelled. Its status owns the
    // result, not the late send receipt that Stop already retired locally.
    await act(async () => {deletion.reject({status: 409, data: {code: 'chat_turn_completed'}}); await stopping;});
    expect(publicRequest.get).toHaveBeenCalledTimes(1);
    expect(chat.messages).toContainEqual(expect.objectContaining({text: completed.text, deliveryStatus: 'completed'}));
    expect(askCourseAssistant).toHaveBeenCalledTimes(1);
  });

  it.each(['close', 'background'])(
    'preserves a dispatched same-account cancellation receipt after %s', async departure => {
      const deletion = deferred<typeof cancelledReceipt>();
      jest.mocked(publicRequest.delete).mockReturnValueOnce(deletion.promise as never);
      await mountAndSend();
      await act(async () => chat.setInput('سؤال بعد الإيقاف'));
      let stopping!: Promise<void>;
      await act(async () => {stopping = chat.stop();});
      if (departure === 'close') await update({visible: false});
      else {mockForeground = false; await update();}
      await act(async () => {deletion.resolve(cancelledReceipt); await stopping;});
      expect(chat.messages).toContainEqual(expect.objectContaining({deliveryStatus: 'cancelled', text: 'تم إيقاف الرد'}));
      expect(chat.input).toBe('سؤال بعد الإيقاف');
      await act(async () => {sendReceipt.resolve(completed);});
      mockForeground = true;
      await update();
      expect(chat.messages.some(row => row.text === completed.text)).toBe(false);
      expect(publicRequest.get).not.toHaveBeenCalled();
    },
  );

  it.each(['close and reopen', 'background and return', 'lesson', 'course', 'account', 'unmount'])(
    'does not dispatch Stop after its preparing visit retires (%s)', async departure => {
      await mountAndSend();
      const capture = deferred<typeof mockBoundary>();
      const oldBoundary = {...mockBoundary};
      mockCapture.mockReturnValueOnce(capture.promise);
      let stopping!: Promise<void>;
      await act(async () => {stopping = chat.stop();});
      if (departure === 'unmount') await act(async () => {renderer!.unmount();});
      else if (departure === 'course') await update({id: '4'});
      else if (departure === 'lesson') await update({lessonId: '9'});
      else if (departure === 'account') {
        mockBoundary = {scope: 'user-b', epoch: 2}; await update();
      } else if (departure.startsWith('close')) {
        await update({visible: false}); await update();
      } else {
        mockForeground = false; await update(); mockForeground = true; await update();
      }
      await act(async () => {capture.resolve(oldBoundary); await stopping;});
      expect(publicRequest.delete).not.toHaveBeenCalled();
      expect(chat.messages.some(row => row.text === 'جارٍ إيقاف الرد')).toBe(false);
    },
  );

  it('cannot revive an old retained Stop callback on a new visit', async () => {
    await mountAndSend();
    const oldStop = chat.stop;
    await update({visible: false}); await update();
    await act(async () => {await oldStop();});
    expect(publicRequest.delete).not.toHaveBeenCalled();
    await act(async () => {await chat.stop();});
    expect(publicRequest.delete).toHaveBeenCalledTimes(1);
  });

  it('does not turn an answer completed during Stop preparation back into a pending/cancelled bubble', async () => {
    await mountAndSend();
    const capture = deferred<typeof mockBoundary>();
    mockCapture.mockReturnValueOnce(capture.promise);
    let stopping!: Promise<void>;
    await act(async () => {stopping = chat.stop();});
    await act(async () => {sendReceipt.resolve(completed);});
    await act(async () => {capture.resolve({...mockBoundary}); await stopping;});
    expect(publicRequest.delete).not.toHaveBeenCalled();
    expect(chat.messages).toContainEqual(expect.objectContaining({text: completed.text, deliveryStatus: 'completed'}));
  });

  it.each(['different account', 'same account new epoch'])(
    'cannot settle or trigger status work for a retired %s before rerender', async change => {
      const deletion = deferred<typeof cancelledReceipt>();
      jest.mocked(publicRequest.delete).mockReturnValueOnce(deletion.promise as never);
      await mountAndSend();
      let stopping!: Promise<void>;
      await act(async () => {stopping = chat.stop();});
      const snapshot = chat.messages;
      mockBoundary = {scope: change === 'different account' ? 'user-b' : 'user-a', epoch: 2};
      await act(async () => {deletion.resolve(cancelledReceipt); await stopping;});
      expect(chat.messages).toEqual(snapshot);
      expect(publicRequest.get).not.toHaveBeenCalled();
    },
  );

  it('an old preparation finally cannot release a new visit Stop flight', async () => {
    await mountAndSend();
    const capture = deferred<typeof mockBoundary>();
    mockCapture.mockReturnValueOnce(capture.promise);
    let oldStop!: Promise<void>;
    await act(async () => {oldStop = chat.stop();});
    await update({visible: false}); await update();
    const deletion = deferred<typeof cancelledReceipt>();
    jest.mocked(publicRequest.delete).mockReturnValueOnce(deletion.promise as never);
    let newStop!: Promise<void>;
    await act(async () => {newStop = chat.stop();});
    await act(async () => {capture.resolve({...mockBoundary}); await oldStop;});
    expect(chat.isSendInFlight()).toBe(true);
    await act(async () => {void chat.stop();});
    expect(publicRequest.delete).toHaveBeenCalledTimes(1);
    await act(async () => {deletion.resolve(cancelledReceipt); await newStop;});
    expect(chat.messages).toContainEqual(expect.objectContaining({deliveryStatus: 'cancelled'}));
  });

  it('a late retired send cannot clear or settle the next question after confirmed cancellation', async () => {
    await mountAndSend();
    await act(async () => {await chat.stop();});
    const nextAnswer = deferred<Answer>();
    jest.mocked(askCourseAssistant).mockReturnValueOnce(nextAnswer.promise);
    await act(async () => chat.setInput('سؤال جديد'));
    await act(async () => chat.send());
    expect(askCourseAssistant).toHaveBeenCalledTimes(2);
    await act(async () => {sendReceipt.resolve(completed);});
    expect(chat.isSendInFlight()).toBe(true);
    expect(chat.messages.some(row => row.text === completed.text)).toBe(false);
    await act(async () => {nextAnswer.resolve({...completed, text: 'رد السؤال الجديد', clientRequestId: secondRequestId});});
    expect(chat.messages).toContainEqual(expect.objectContaining({text: 'رد السؤال الجديد', deliveryStatus: 'completed'}));
  });

  it.each(['409', 'transport error', 'malformed success'])(
    'reconciles the same id read-only instead of assuming cancellation for %s', async failure => {
      await mountAndSend();
      if (failure === '409') jest.mocked(publicRequest.delete).mockRejectedValueOnce({status: 409});
      else if (failure === 'transport error') jest.mocked(publicRequest.delete).mockRejectedValueOnce(new Error('offline'));
      else jest.mocked(publicRequest.delete).mockResolvedValueOnce({data: {success: true}} as never);
      await act(async () => {await chat.stop();});
      await act(async () => {sendReceipt.resolve(completed);});
      expect(publicRequest.get).toHaveBeenCalledWith(`course-chat/turns/${requestId}`, expect.any(Object));
      expect(chat.messages).toContainEqual(expect.objectContaining({text: completed.text, deliveryStatus: 'completed'}));
      expect(askCourseAssistant).toHaveBeenCalledTimes(1);
    },
  );

  it('the transport rejects a supplied retired boundary before DELETE rather than recapturing the new account', async () => {
    const retired = {...mockBoundary};
    mockBoundary = {scope: 'user-b', epoch: 2};
    await expect(cancelCourseAssistantTurn(requestId, retired)).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(publicRequest.delete).not.toHaveBeenCalled();
    expect(mockCapture).not.toHaveBeenCalled();
  });
});
