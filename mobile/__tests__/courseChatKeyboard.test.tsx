import React from 'react';
import {
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  Text,
  ToastAndroid,
} from 'react-native';
import Clipboard from '@react-native-clipboard/clipboard';
import TestRenderer, {act} from 'react-test-renderer';

const mockChatState = {
  assistantIncluded: true,
  assistantPresence: 'connected',
  attachments: [],
  input: 'سؤال مكتوب',
  messages: [
    {
      id: 'answer-1',
      role: 'assistant',
      text: 'ابدأ بتحديد الهدف',
      deliveryStatus: 'completed',
    },
  ],
  sending: false,
  answerPending: false,
  isSendInFlight: () => false,
  scrollRef: {current: null},
  setInput: jest.fn(),
};
jest.unmock('react-native/Libraries/Components/ScrollView/ScrollView');
jest.mock('../src/components/VideoPlayer/courseChat/useCourseChat', () => ({
  useCourseChat: () => mockChatState,
}));
jest.mock(
  '../src/components/VideoPlayer/courseChat/useCourseChatAttachments',
  () => ({
    useCourseChatAttachments: () => ({
      pickerIsActive: () => false,
      pickAttachments: jest.fn(),
    }),
  }),
);
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  openCourseAssistantAttachment: jest.fn(),
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  removeLearnerDraftFile: jest.fn(),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: jest.fn()}),
}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('@react-native-clipboard/clipboard', () => ({setString: jest.fn()}));

import CourseChatOverlay from '../src/components/VideoPlayer/CourseChatOverlay';
import type {CourseLearningData} from '../src/components/VideoPlayer/types';

describe('course conversation keyboard ownership', () => {
  const onClose = jest.fn();
  let renderer: TestRenderer.ReactTestRenderer;
  const render = async () => {
    await act(async () => {
      renderer = TestRenderer.create(
        <CourseChatOverlay
          visible
          course={{id: '7', accessType: 'paid'} as CourseLearningData}
          onClose={onClose}
          onEntitlementChanged={jest.fn()}
          onOpenCourseAccess={jest.fn()}
        />,
      );
    });
  };
  beforeEach(() => {
    jest.clearAllMocks();
    jest.spyOn(ToastAndroid, 'show').mockImplementation(() => undefined);
  });
  afterEach(async () => {
    await act(async () => renderer?.unmount());
    jest.restoreAllMocks();
  });

  it.each(['android', 'ios'] as const)(
    'has one keyboard resize owner on %s',
    async os => {
      jest.replaceProperty(Platform, 'OS', os);
      await render();
      expect(
        renderer.root.findByType(KeyboardAvoidingView).props.behavior,
      ).toBe(os === 'ios' ? 'padding' : undefined);
      expect(
        renderer.root.findByType(ScrollView).props.keyboardShouldPersistTaps,
      ).toBe('always');
    },
  );

  it('copies the message without closing the sheet or changing its layout and draft', async () => {
    jest.replaceProperty(Platform, 'OS', 'android');
    await render();
    const before = renderer.toJSON();
    const message = renderer.root
      .findAllByType(Text)
      .find(node => node.props.children === 'ابدأ بتحديد الهدف');
    expect(message?.props.selectable).toBe(false);
    await act(async () => {
      renderer.root
        .findByProps({accessibilityLabel: 'نسخ الرسالة'})
        .props.onPress();
    });
    expect(Clipboard.setString).toHaveBeenCalledWith('ابدأ بتحديد الهدف');
    expect(onClose).not.toHaveBeenCalled();
    expect(mockChatState.setInput).not.toHaveBeenCalled();
    expect(renderer.toJSON()).toEqual(before);
  });

  it('does not dismiss a focused composer when a plain message receives a tap', async () => {
    await render();
    // The preset's TextInput.State is a mock, not the state used by the real ScrollView above.
    const inputState =
      // eslint-disable-next-line @react-native/no-deep-imports
      require('react-native/Libraries/Components/TextInput/TextInputState').default;
    jest.spyOn(inputState, 'currentlyFocusedInput').mockReturnValue({});
    const blur = jest.spyOn(inputState, 'blurTextInput');
    const nativeScroll = renderer.root.find(
      node => typeof node.instance?._handleResponderRelease === 'function',
    ).instance;
    jest.spyOn(nativeScroll, '_keyboardIsDismissible').mockReturnValue(true);
    await act(async () => {
      nativeScroll._handleResponderRelease({
        target: {},
        nativeEvent: {touches: []},
      });
    });
    expect(blur).not.toHaveBeenCalled();
    expect(onClose).not.toHaveBeenCalled();
  });
});
