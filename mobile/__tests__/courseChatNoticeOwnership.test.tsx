import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Alert} from 'react-native';

let mockBoundary = {scope: 'user-a', epoch: 1};
let mockForeground = true;
jest.mock('react-redux', () => ({useSelector: () => ({id: mockBoundary.scope})}));
jest.mock('expo-document-picker', () => ({getDocumentAsync: jest.fn()}));
jest.mock('../src/constants/helpers', () => ({
  sessionIdentityKey: () => mockBoundary.scope,
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  getCurrentAccountStorageScope: async () => mockBoundary.scope,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (boundary.scope !== mockBoundary.scope || boundary.epoch !== mockBoundary.epoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/hooks/useAppActiveState', () => ({useAppForegroundState: () => mockForeground}));
jest.mock('../src/services/aiConsent', () => ({requestAiConsent: jest.fn(async () => true)}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  courseIncludesAssistant: () => true,
  loadCourseAssistantHistory: jest.fn(async () => []),
  askCourseAssistant: jest.fn(),
  pollCourseAssistantTurn: jest.fn(),
  cancelCourseAssistantTurn: jest.fn(),
  uploadCourseAssistantAttachment: jest.fn(),
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
jest.mock('../src/services/learnerDraftFiles', () => ({removeLearnerDraftFile: jest.fn()}));
jest.mock('../src/services/operationalTelemetry', () => ({reportClientError: jest.fn()}));
jest.mock('../src/utils/secureRandom', () => ({secureRandomUuid: jest.fn()}));

import {useCourseChat} from '../src/components/VideoPlayer/courseChat/useCourseChat';
import {askCourseAssistant, pollCourseAssistantTurn} from '../src/components/VideoPlayer/courseLearningApi';
import {saveCourseChatHistory} from '../src/components/VideoPlayer/courseChat/persistence';
import {subscriptionMessages, subscriptionMessageText} from '../src/constants/subscriptionMessages';
import {secureRandomUuid} from '../src/utils/secureRandom';
import {getFullTrackUpgradeQuote} from '../src/services/roknApi';
import type {CourseLearningData, CourseReel} from '../src/components/VideoPlayer/types';

type Response = Awaited<ReturnType<typeof askCourseAssistant>>;
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {resolve = done;});
  return {promise, resolve};
};
const dailyLimit: Response = {
  text: subscriptionMessageText(subscriptionMessages.chatDailyLimit),
  offline: false, unavailable: true, turnStatus: 'failed',
  code: 'chat_daily_limit_reached', clientRequestId: 'request-1',
};
const pending: Response = {
  text: '', offline: false, turnStatus: 'queued', code: 'chat_answer_in_progress',
  retryAfterSeconds: 1, clientRequestId: 'request-1',
};
const course = {id: '3', title: 'الكورس', accessType: 'paid', chatAvailable: true} as CourseLearningData;

describe('course chat daily-limit notice owns its send/recovery visit', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let chat: ReturnType<typeof useCourseChat>;
  let alert: jest.SpyInstance;
  function Harness({visible = true, id = '3', lessonId}: {
    visible?: boolean; id?: string; lessonId?: string;
  }) {
    chat = useCourseChat({
      visible, course: {...course, id},
      reel: lessonId ? ({lessonId} as CourseReel) : undefined,
    });
    return null;
  }
  const mount = async () => {
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    await act(async () => chat.setInput('اشرح لي الفكرة'));
  };
  const depart = async (departure: string) => {
    await act(async () => {
      if (departure === 'unmount') renderer!.unmount();
      else if (departure === 'lesson') renderer!.update(<Harness lessonId="9" />);
      else if (departure === 'course') renderer!.update(<Harness id="4" />);
      else if (departure === 'account') {
        mockBoundary = {scope: 'user-b', epoch: 2};
        renderer!.update(<Harness />);
      } else if (departure.startsWith('background')) {
        mockForeground = false;
        renderer!.update(<Harness />);
      } else renderer!.update(<Harness visible={false} />);
    });
    if (departure.endsWith('return') || departure.endsWith('reopen')) {
      mockForeground = true;
      await act(async () => {renderer!.update(<Harness />);});
    }
  };
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockBoundary = {scope: 'user-a', epoch: 1};
    mockForeground = true;
    alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    let id = 0;
    jest.mocked(secureRandomUuid).mockReset().mockImplementation(() => `request-${++id}`);
    jest.mocked(askCourseAssistant).mockReset();
    jest.mocked(pollCourseAssistantTurn).mockReset();
    jest.mocked(getFullTrackUpgradeQuote).mockReset().mockResolvedValue({
      upgradeAvailable: true, availablePlanCodes: ['mentor'],
    } as Awaited<ReturnType<typeof getFullTrackUpgradeQuote>>);
  });
  afterEach(async () => {
    await act(async () => {renderer?.unmount();});
    renderer = undefined;
    jest.clearAllTimers();
    jest.useRealTimers();
    alert.mockRestore();
  });

  it('shows the approved notice once for a still-current send and stores the same result', async () => {
    jest.mocked(askCourseAssistant).mockResolvedValueOnce(dailyLimit);
    await mount();
    await act(async () => chat.send());
    expect(alert).toHaveBeenCalledTimes(1);
    expect(alert).toHaveBeenCalledWith(
      subscriptionMessages.chatDailyLimit.title,
      subscriptionMessages.chatDailyLimit.body,
      [{text: subscriptionMessages.chatDailyLimit.action}],
    );
    expect(chat.messages).toContainEqual(expect.objectContaining({
      role: 'assistant', text: dailyLimit.text, errorCode: dailyLimit.code,
      deliveryStatus: 'failed', contextEligible: false,
    }));
    expect(chat.assistantIncluded).toBe(true);
    expect(chat.planLimitReached).toBe(false);
    expect(getFullTrackUpgradeQuote).not.toHaveBeenCalled();
  });

  it.each(['close', 'close and reopen', 'background', 'background and return', 'lesson', 'course', 'account', 'unmount'])(
    'does not show a delayed send notice after %s', async departure => {
      const receipt = deferred<Response>();
      jest.mocked(askCourseAssistant).mockReturnValueOnce(receipt.promise);
      await mount();
      await act(async () => chat.send());
      await depart(departure);
      await act(async () => {receipt.resolve(dailyLimit);});
      expect(alert).not.toHaveBeenCalled();
      expect(askCourseAssistant).toHaveBeenCalledTimes(1);
      expect(getFullTrackUpgradeQuote).not.toHaveBeenCalled();
      if (departure.startsWith('close') || departure.startsWith('background')) {
        expect(chat.messages).toContainEqual(expect.objectContaining({
          role: 'assistant', text: dailyLimit.text, errorCode: dailyLimit.code,
        }));
        mockForeground = true;
        await act(async () => {renderer!.update(<Harness />);});
        expect(alert).not.toHaveBeenCalled();
        expect(askCourseAssistant).toHaveBeenCalledTimes(1);
      } else if (departure !== 'unmount') {
        expect(chat.messages.some(message => message.errorCode === dailyLimit.code)).toBe(false);
      }
    },
  );

  it.each(['current', 'close and reopen', 'background and return', 'unmount'])(
    'scopes the notice from a delayed status probe to the original visit (%s)', async departure => {
      const receipt = deferred<Response>();
      jest.mocked(askCourseAssistant).mockResolvedValueOnce(pending);
      jest.mocked(pollCourseAssistantTurn).mockReturnValueOnce(receipt.promise);
      await mount();
      await act(async () => chat.send());
      await act(async () => {await jest.advanceTimersByTimeAsync(2000);});
      expect(pollCourseAssistantTurn).toHaveBeenCalledTimes(1);
      if (departure !== 'current') await depart(departure);
      await act(async () => {receipt.resolve(dailyLimit);});
      expect(alert).toHaveBeenCalledTimes(departure === 'current' ? 1 : 0);
      if (departure !== 'unmount')
        expect(chat.messages).toContainEqual(expect.objectContaining({errorCode: dailyLimit.code}));
      expect(askCourseAssistant).toHaveBeenCalledTimes(1);
    },
  );

  it('a later explicit question has its own notice without reviving the old dialog', async () => {
    const receipt = deferred<Response>();
    jest.mocked(askCourseAssistant).mockReturnValueOnce(receipt.promise);
    await mount();
    await act(async () => chat.send());
    await depart('close and reopen');
    await act(async () => {receipt.resolve(dailyLimit);});
    expect(alert).not.toHaveBeenCalled();
    jest.mocked(askCourseAssistant).mockImplementationOnce(async ({clientRequestId}) => ({
      ...dailyLimit, clientRequestId,
    }));
    await act(async () => chat.setInput('سؤال جديد'));
    await act(async () => chat.send());
    expect(alert).toHaveBeenCalledTimes(1);
    expect(askCourseAssistant).toHaveBeenCalledTimes(2);
  });

  it('rejects a receipt for a retired account epoch even before its screen rerenders', async () => {
    const receipt = deferred<Response>();
    jest.mocked(askCourseAssistant).mockReturnValueOnce(receipt.promise);
    await mount();
    await act(async () => chat.send());
    mockBoundary = {scope: 'user-a', epoch: 2};
    await act(async () => {receipt.resolve(dailyLimit);});
    expect(alert).not.toHaveBeenCalled();
    expect(chat.messages.some(message => message.errorCode === dailyLimit.code)).toBe(false);
    expect(jest.mocked(saveCourseChatHistory).mock.calls.some(([, messages]) =>
      messages.some(message => message.errorCode === dailyLimit.code),
    )).toBe(false);
  });

  it.each(['completed', 'plan limit'])(
    'does not apply a current-protocol %s receipt after switching accounts before rerender', async outcome => {
      const receipt = deferred<Response>();
      jest.mocked(askCourseAssistant).mockReturnValueOnce(receipt.promise);
      await mount();
      await act(async () => chat.send());
      mockBoundary = {scope: 'user-b', epoch: 2};
      const response: Response = outcome === 'completed'
        ? {text: 'رد الحساب السابق', offline: false, turnStatus: 'completed', clientRequestId: 'request-1'}
        : {...dailyLimit, text: subscriptionMessageText(subscriptionMessages.chatExhausted),
          code: 'chat_plan_limit_reached', blocked: true, unavailable: false};
      await act(async () => {receipt.resolve(response);});
      expect(alert).not.toHaveBeenCalled();
      expect(chat.messages.some(message => message.text === response.text)).toBe(false);
      expect(chat.planLimitReached).toBe(false);
      expect(getFullTrackUpgradeQuote).not.toHaveBeenCalled();
      expect(jest.mocked(saveCourseChatHistory).mock.calls.some(([, messages]) =>
        messages.some(message => message.text === response.text),
      )).toBe(false);
    },
  );

  it('still records a real plan exhaustion while hidden so the returning gate is authoritative', async () => {
    const receipt = deferred<Response>();
    jest.mocked(askCourseAssistant).mockReturnValueOnce(receipt.promise);
    await mount();
    await act(async () => chat.send());
    await depart('close');
    await act(async () => {receipt.resolve({
      ...dailyLimit, text: subscriptionMessageText(subscriptionMessages.chatExhausted),
      code: 'chat_plan_limit_reached', blocked: true, unavailable: false,
    });});
    await act(async () => {renderer!.update(<Harness />);});
    expect(chat.planLimitReached).toBe(true);
    expect(chat.assistantIncluded).toBe(false);
    expect(getFullTrackUpgradeQuote).toHaveBeenCalledWith('3', {requiredFeature: 'chat'});
    expect(alert).not.toHaveBeenCalled();
  });
});
