import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {AppState, Platform} from 'react-native';
import type {AppStateStatus, ScrollView} from 'react-native';

jest.mock('react-native/Libraries/AppState/AppState', () => ({
  __esModule: true,
  default: {currentState: 'active', addEventListener: jest.fn()},
}));

jest.mock('react-redux', () => ({useSelector: () => ({id: 7})}));
jest.mock('../src/constants/helpers', () => ({
  sessionIdentityKey: () => 'user-7',
  captureAccountSessionBoundary: async () => ({epoch: 1, scope: 'user-7'}),
  assertAccountSessionBoundary: jest.fn(),
  getCurrentAccountStorageScope: async () => 'user-7',
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  courseIncludesAssistant: () => true,
  loadCourseAssistantHistory: jest.fn(async () => []),
  askCourseAssistant: jest.fn(),
  pollCourseAssistantTurn: jest.fn(),
  cancelCourseAssistantTurn: jest.fn(),
  uploadCourseAssistantAttachment: jest.fn(),
  flushPendingPlaybackPositions: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/assistant', () => ({
  pollCourseAssistantTurn: (...args: unknown[]) =>
    require('../src/components/VideoPlayer/courseLearningApi').pollCourseAssistantTurn(
      ...args,
    ),
}));
jest.mock('../src/components/VideoPlayer/courseChat/persistence', () => ({
  loadCourseChatHistory: async () => [],
  saveCourseChatHistory: jest.fn(async () => undefined),
  mergeCourseChatHistories: (remote: unknown[], local: unknown[]) => [
    ...remote,
    ...local,
  ],
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  removeLearnerDraftFile: jest.fn(),
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/services/roknApi', () => ({}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => 'request-1',
}));

import {
  askCourseAssistant,
  pollCourseAssistantTurn,
} from '../src/components/VideoPlayer/courseLearningApi';
import {useCourseChat} from '../src/components/VideoPlayer/courseChat/useCourseChat';
import {useAppActiveState} from '../src/hooks/useAppActiveState';
import {useReelsLifecycle} from '../src/screens/reels/useReelsLifecycle';
import type {CourseLearningData} from '../src/components/VideoPlayer/types';

describe('chat in an Android modal', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const events = new Map<string, (value?: string) => void>();
  const course = {
    id: '3',
    title: 'الكورس',
    accessType: 'paid',
    chatAvailable: true,
  } as CourseLearningData;
  let chat: ReturnType<typeof useCourseChat>;
  let playbackActive = false;
  const onForeground = jest.fn();
  const onBackground = jest.fn();
  const lifecycleRefs = {
    delayedActions: {current: new Set<ReturnType<typeof setTimeout>>()},
    loadAbort: {current: null},
    loadRequest: {current: 0},
    mounted: {current: false},
    reviewWatcher: {current: 0},
  };
  const Harness = () => {
    playbackActive = useAppActiveState();
    useReelsLifecycle(lifecycleRefs, onForeground, onBackground);
    chat = useCourseChat({
      visible: true,
      course,
      onEntitlementChanged: jest.fn(),
      onOpenWallet: jest.fn(),
    });
    return null;
  };

  beforeEach(() => {
    jest.useFakeTimers();
    jest.replaceProperty(Platform, 'OS', 'android');
    jest.replaceProperty(AppState, 'currentState', 'active');
    jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation((type, handler) => {
        events.set(type, value => handler(value as AppStateStatus));
        return {remove: () => events.delete(type)};
      });
    jest.mocked(askCourseAssistant).mockResolvedValue({
      text: '',
      offline: false,
      unavailable: true,
      code: 'chat_answer_in_progress',
      turnStatus: 'queued',
      clientRequestId: 'request-1',
      retryAfterSeconds: 1,
      pollWindowSeconds: 10,
    });
    jest.mocked(pollCourseAssistantTurn).mockResolvedValue({
      text: 'ابدأ بتحديد نقطة الضوء',
      offline: false,
      turnStatus: 'completed',
      clientRequestId: 'request-1',
    });
  });

  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.clearAllTimers();
    jest.useRealTimers();
    jest.restoreAllMocks();
    jest.clearAllMocks();
  });

  it('continues the accepted reply while the modal blurs the activity window', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => events.get('blur')?.());
    expect(playbackActive).toBe(false);
    expect(onBackground).not.toHaveBeenCalled();
    expect(onForeground).not.toHaveBeenCalled();
    await act(async () => chat.setInput('أبدأ منين؟'));
    await act(async () => chat.send());
    const scrollToEnd = jest.fn();
    chat.scrollRef.current = {scrollToEnd} as unknown as ScrollView;
    await act(async () => jest.advanceTimersByTimeAsync(100));
    scrollToEnd.mockClear();
    await act(async () => jest.advanceTimersByTimeAsync(1400));
    // Completion must not override the message list's reader-owned position.
    expect(scrollToEnd).not.toHaveBeenCalled();

    expect(askCourseAssistant).toHaveBeenCalledTimes(1);
    expect(pollCourseAssistantTurn).toHaveBeenCalledTimes(1);
    expect(chat.messages).toContainEqual(
      expect.objectContaining({
        role: 'assistant',
        text: 'ابدأ بتحديد نقطة الضوء',
        deliveryStatus: 'completed',
      }),
    );
    expect(chat.answerPending).toBe(false);
    expect(playbackActive).toBe(false);
  });

  it('pauses on actual backgrounding and resumes without closing the chat', async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    await act(async () => events.get('blur')?.());
    await act(async () => chat.setInput('أبدأ منين؟'));
    await act(async () => chat.send());
    // Background while already blurred must still notify foreground owners.
    await act(async () => events.get('change')?.('background'));
    expect(onBackground).toHaveBeenCalledTimes(1);
    await act(async () => jest.advanceTimersByTimeAsync(2000));
    expect(pollCourseAssistantTurn).not.toHaveBeenCalled();
    expect(chat.answerPending).toBe(true);
    expect(
      chat.messages.some(message => message.text.includes('فتح الشات')),
    ).toBe(false);

    await act(async () => events.get('change')?.('active'));
    expect(onForeground).toHaveBeenCalledTimes(1);
    expect(pollCourseAssistantTurn).toHaveBeenCalledTimes(1);
    expect(askCourseAssistant).toHaveBeenCalledTimes(1);
    expect(chat.messages).toContainEqual(
      expect.objectContaining({
        text: 'ابدأ بتحديد نقطة الضوء',
        deliveryStatus: 'completed',
      }),
    );
  });
});
