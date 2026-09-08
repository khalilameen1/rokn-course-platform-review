import React from 'react';
import {
  KeyboardAvoidingView,
  Keyboard,
  Dimensions,
  Platform,
  ScrollView,
  Text,
  ToastAndroid,
  StyleSheet,
  TextInput,
  View,
} from 'react-native';
import Clipboard from '@react-native-clipboard/clipboard';
import TestRenderer, {act} from 'react-test-renderer';
import {execFileSync} from 'node:child_process';
import path from 'node:path';
import {courseChatStyles as styles} from '../src/components/VideoPlayer/courseChat/styles';
import type {ChatAttachmentDraft} from '../src/components/VideoPlayer/types';

const {execPath} = require('node:process') as {execPath: string};
const mockInsets = {top: 0, bottom: 0, left: 0, right: 0};
jest.unmock('react-native/Libraries/Components/Keyboard/KeyboardAvoidingView');

const mockChatState = {
  assistantIncluded: true,
  assistantPresence: 'connected',
  attachments: [] as ChatAttachmentDraft[],
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
  useSafeAreaInsets: () => mockInsets,
  SafeAreaListener: 'SafeAreaListener',
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
    mockChatState.attachments = [];
    mockChatState.answerPending = false;
    mockChatState.input = 'سؤال مكتوب';
    mockInsets.top = 0;
    mockInsets.bottom = 0;
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
      ).toBe('padding');
      expect(
        renderer.root.findByType(ScrollView).props.keyboardShouldPersistTaps,
      ).toBe('always');
    },
  );

  it('avoids only the remaining IME overlap and clears it after resize or hide', async () => {
    jest.replaceProperty(Platform, 'OS', 'android');
    await render();
    // Exercise React Native's installed implementation, not a fake padding calculator.
    const avoidance = renderer.root.findByType(KeyboardAvoidingView).instance;
    const layout = async (height: number) => {
      await act(async () =>
        avoidance._onLayout({
          persist: () => undefined,
          nativeEvent: {layout: {x: 0, y: 0, width: 360, height}},
        }),
      );
    };
    await layout(800);
    await act(async () =>
      avoidance._onKeyboardChange({
        duration: 0,
        endCoordinates: {screenY: 480, screenX: 0, width: 360, height: 320},
      }),
    );
    expect(avoidance.state.bottom).toBe(320);
    expect(
      StyleSheet.flatten(avoidance.render().props.style).paddingBottom,
    ).toBe(320);
    await layout(480); // Older Android already resized the native Dialog.
    expect(avoidance.state.bottom).toBe(0);
    expect(
      StyleSheet.flatten(avoidance.render().props.style).paddingBottom,
    ).toBe(0);
    await layout(800); // Edge-to-edge viewport stays full height instead.
    expect(avoidance.state.bottom).toBe(320);
    await act(async () => avoidance._onKeyboardHide());
    expect(avoidance.state.bottom).toBe(0);
    expect(Keyboard.isVisible()).toBe(false);
  });

  it('uses the Modal safe area instead of the parent Activity or an Android zero', async () => {
    jest.replaceProperty(Platform, 'OS', 'android');
    await render();
    const listener = renderer.root.findByType('SafeAreaListener' as any);
    const composerPadding = () =>
      StyleSheet.flatten(
        renderer.root
          .findAllByType(View)
          .find(
            node =>
              Array.isArray(node.props.style) &&
              node.props.style.includes(styles.composer),
          )!.props.style,
      ).paddingBottom;
    await act(async () =>
      listener.props.onChange({
        frame: {x: 0, y: 0, width: 360, height: 800},
        insets: {top: 24, bottom: 48, left: 32, right: 48},
      }),
    );
    expect(composerPadding()).toBe(54);
    expect(StyleSheet.flatten(listener.props.style)).toMatchObject({
      paddingLeft: 32,
      paddingRight: 48,
    });
    // Listener is inside avoidance: once above the IME it no longer intersects the nav bar.
    await act(async () =>
      listener.props.onChange({
        frame: {x: 0, y: 0, width: 360, height: 480},
        insets: {top: 24, bottom: 0, left: 0, right: 0},
      }),
    );
    expect(composerPadding()).toBe(10);
  });

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

  it.each([
    {width: 320, screen: 568, viewport: 264, fontScale: 1},
    {width: 320, screen: 568, viewport: 224, fontScale: 2},
    {width: 568, screen: 320, viewport: 192, fontScale: 1},
    {width: 568, screen: 320, viewport: 152, fontScale: 2},
    {width: 411, screen: 923, viewport: 430, fontScale: 1},
    {width: 800, screen: 1280, viewport: 640, fontScale: 2},
  ])(
    'keeps six-line input and send inside a resized Modal $width/$viewport/$fontScale',
    async dimensions => {
      mockChatState.input = Array(6).fill('سطر من السؤال').join('\n');
      mockInsets.top = dimensions.width < dimensions.screen ? 24 : 0;
      mockInsets.bottom = 24;
      jest.replaceProperty(Platform, 'OS', 'android');
      const originalGet = Dimensions.get;
      jest.spyOn(Dimensions, 'get').mockImplementation(kind => ({
        ...originalGet(kind),
        width: dimensions.width,
        height: dimensions.screen,
        fontScale: dimensions.fontScale,
      }));
      await render();
      const viewport = renderer.root
        .findAllByType(View)
        .filter(
          node => node.props.onLayout && node.props.style === styles.modal,
        )
        .at(-1)!;
      for (const height of [
        dimensions.screen,
        dimensions.viewport,
        dimensions.screen,
        dimensions.viewport,
      ]) {
        await act(async () =>
          renderer.root.findByType('SafeAreaListener' as any).props.onChange({
            frame: {x: 0, y: 0, width: dimensions.width, height},
            insets: {
              ...mockInsets,
              bottom: height === dimensions.screen ? 24 : 0,
            },
          }),
        );
        await act(async () =>
          viewport.props.onLayout({nativeEvent: {layout: {height}}}),
        );
        const sheet = renderer.root.findByProps({
          accessibilityViewIsModal: true,
        });
        const sheetStyle = StyleSheet.flatten(sheet.props.style);
        const input = renderer.root.findByType(TextInput);
        const views = renderer.root.findAllByType(View);
        const composerStyle = StyleSheet.flatten(
          views.find(
            node =>
              Array.isArray(node.props.style) &&
              node.props.style.includes(styles.composer),
          )!.props.style,
        );
        const headerStyle = StyleSheet.flatten(
          views.find(
            node =>
              Array.isArray(node.props.style) &&
              node.props.style.includes(styles.header),
          )!.props.style,
        );
        const handleVisible = views.some(
          node => node.props.style === styles.handle,
        );
        const tree = {
          name: 'viewport',
          style: {height, width: dimensions.width, justifyContent: 'flex-end'},
          children: [
            {
              name: 'sheet',
              style: sheetStyle,
              children: [
                ...(handleVisible ? [{style: styles.handle}] : []),
                {
                  name: 'header',
                  style: headerStyle,
                  children: [
                    // Yoga measures native text externally too. Supply a conservative
                    // two-line header height and SIX input lines, not a mocked 48px input.
                    {
                      style: styles.headerCopy,
                      measure: {width: 180, height: 32 * dimensions.fontScale},
                    },
                    {style: styles.closeButton},
                  ],
                },
                {
                  name: 'history',
                  style: styles.messages,
                  measure: {width: 200, height: 1000},
                },
                {
                  name: 'composer',
                  style: composerStyle,
                  children: [
                    {
                      name: 'input',
                      style: StyleSheet.flatten(input.props.style),
                      measure: {
                        width: 180,
                        height: 6 * 21 * dimensions.fontScale,
                      },
                    },
                    {style: styles.attachButton},
                    {name: 'send', style: styles.sendButton},
                  ],
                },
              ],
            },
          ],
        };
        const geometry = JSON.parse(
          execFileSync(
            execPath,
            [path.join(__dirname, 'fixtures/chatYogaLayout.mjs')],
            {input: JSON.stringify(tree), encoding: 'utf8'},
          ),
        );
        expect(geometry.send.top + geometry.send.height).toBeLessThanOrEqual(
          height,
        );
        expect(geometry.send.top).toBeGreaterThanOrEqual(geometry.sheet.top);
        expect(geometry.composer.top).toBeGreaterThanOrEqual(
          geometry.header.top + geometry.header.height,
        );
        expect(geometry.history.height).toBeGreaterThan(0);
        expect(geometry.send.width).toBe(48);
        expect(geometry.input.width).toBeGreaterThan(60);
        if (height === dimensions.viewport) {
          expect(sheetStyle.height).toBe(height - mockInsets.top - 8);
        }
        if (
          dimensions.width === 320 &&
          dimensions.fontScale === 1 &&
          height === dimensions.viewport
        ) {
          // This is the pre-fix tree: 78% of the already resized Modal,
          // fixed attachments/stop siblings, and a six-line 110dp input.
          const oldTree = {
            ...tree,
            children: [
              {
                style: {...sheetStyle, height: '78%'},
                children: [
                  {style: styles.handle},
                  {style: styles.header},
                  {style: styles.messages, measure: {width: 200, height: 1000}},
                  {style: {height: 58}},
                  {style: {height: 40}},
                  {
                    style: composerStyle,
                    children: [
                      {
                        style: {...styles.input, maxHeight: 110},
                        measure: {width: 180, height: 6 * 21},
                      },
                      {name: 'send', style: styles.sendButton},
                    ],
                  },
                ],
              },
            ],
          };
          const oldGeometry = JSON.parse(
            execFileSync(
              execPath,
              [path.join(__dirname, 'fixtures/chatYogaLayout.mjs')],
              {input: JSON.stringify(oldTree), encoding: 'utf8'},
            ),
          );
          expect(
            oldGeometry.send.top + oldGeometry.send.height,
          ).toBeGreaterThan(height);
        }
      }
    },
  );

  it('keeps attachment removal and stop in the scrollable area rather than pushing send below it', async () => {
    mockChatState.answerPending = true;
    mockChatState.attachments = [
      {
        uploadId: 'file-1',
        name: 'مشروعي.pdf',
        type: 'application/pdf',
        uri: 'file:///project.pdf',
      } as ChatAttachmentDraft,
    ];
    await render();
    const history = renderer.root
      .findAllByType(ScrollView)
      .find(
        node => node.props.accessibilityLabel === 'محادثة استفسارات الكورس',
      )!;
    expect(
      history.findAllByProps({accessibilityLabel: 'حذف مشروعي.pdf'}).length,
    ).toBeGreaterThan(0);
    expect(
      history.findAllByType(Text).some(node => node.props.children === 'إيقاف'),
    ).toBe(true);
    expect(history.findAllByProps({accessibilityLabel: 'إرسال'})).toHaveLength(
      0,
    );
    expect(
      history.findAllByType(ScrollView).find(node => node.props.horizontal)
        ?.props.keyboardShouldPersistTaps,
    ).toBe('always');
  });
});
