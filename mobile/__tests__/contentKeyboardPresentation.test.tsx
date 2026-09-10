import React from 'react';
import {
  KeyboardAvoidingView,
  Platform,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {Content} from '../src/components/containers/Containers';

jest.mock('react-native-safe-area-context', () => ({
  SafeAreaView: ({children}: {children: React.ReactNode}) => children,
}));

// This checks the rendered prop/ref contract, not native keyboard geometry.
// Visibility above the IME remains a separate device-preview assertion.
describe('Content keyboard presentation contract', () => {
  const originalOS = Platform.OS;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const setOS = (os: 'android' | 'ios') =>
    Object.defineProperty(Platform, 'OS', {configurable: true, value: os});

  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
    Object.defineProperty(Platform, 'OS', {
      configurable: true,
      value: originalOS,
    });
  });

  it.each([
    {os: 'android' as const, avoidanceEnabled: true},
    {os: 'ios' as const, avoidanceEnabled: false},
  ])(
    'enables padding avoidance only on Android ($os)',
    async ({os, avoidanceEnabled}) => {
      setOS(os);
      await act(async () => {
        renderer = TestRenderer.create(
          <Content>
            <Text>محتوى قابل للتمرير</Text>
          </Content>,
        );
      });
      const avoidance = renderer!.root.findByType(KeyboardAvoidingView);
      const scroll = avoidance.findByType(ScrollView);
      expect(avoidance.props.enabled).toBe(avoidanceEnabled);
      expect(avoidance.props.behavior).toBe('padding');
      expect(StyleSheet.flatten(avoidance.props.style).flex).toBe(1);
      // iOS retains ScrollView's automatic keyboard inset adjustment while
      // its enclosing KeyboardAvoidingView is disabled, avoiding two owners.
      expect(scroll.props.automaticallyAdjustKeyboardInsets).toBe(true);
      expect(scroll.props.contentInsetAdjustmentBehavior).toBe('automatic');
      expect(scroll.props.keyboardShouldPersistTaps).toBe('handled');
      expect(scroll.props.keyboardDismissMode).toBe('interactive');
      expect(scroll.findByType(Text).props.children).toBe('محتوى قابل للتمرير');
    },
  );

  it.each(['android', 'ios'] as const)(
    'keeps the ScrollView controls ref refresh element and scroll callbacks on %s',
    async os => {
      setOS(os);
      const controls = jest.fn();
      const nextControls = jest.fn();
      const onScroll = jest.fn();
      const onScrollBeginDrag = jest.fn();
      const onRefresh = jest.fn();
      const refreshControl = (
        <RefreshControl refreshing={false} onRefresh={onRefresh} />
      );
      const content = (bind: typeof controls) => (
        <Content
          controls={bind}
          onScroll={onScroll}
          onScrollBeginDrag={onScrollBeginDrag}
          refreshControl={refreshControl}
          scrollEventThrottle={250}>
          <Text>نهاية المحتوى</Text>
        </Content>
      );
      await act(async () => {
        renderer = TestRenderer.create(content(controls));
      });
      const scroll = renderer!.root.findByType(ScrollView);
      const scrollInstance = scroll.instance;
      expect(controls).toHaveBeenCalledTimes(1);
      expect(controls).toHaveBeenCalledWith(scrollInstance);
      expect(scroll.props.scrollEnabled).toBe(true);
      expect(scroll.props.nestedScrollEnabled).toBe(true);
      expect(scroll.props.scrollEventThrottle).toBe(250);
      expect(scroll.props.refreshControl).toBe(refreshControl);
      expect(scroll.props.onScroll).toBe(onScroll);
      expect(scroll.props.onScrollBeginDrag).toBe(onScrollBeginDrag);
      const scrollEvent = {nativeEvent: {contentOffset: {x: 0, y: 72}}};
      act(() => {
        scroll.props.onScroll(scrollEvent);
        scroll.props.onScrollBeginDrag(scrollEvent);
        scroll.props.refreshControl.props.onRefresh();
      });
      expect(onScroll).toHaveBeenCalledWith(scrollEvent);
      expect(onScrollBeginDrag).toHaveBeenCalledWith(scrollEvent);
      expect(onRefresh).toHaveBeenCalledTimes(1);

      await act(async () => renderer!.update(content(nextControls)));
      expect(renderer!.root.findByType(ScrollView).instance).toBe(
        scrollInstance,
      );
      expect(nextControls).toHaveBeenCalledTimes(1);
      expect(nextControls).toHaveBeenCalledWith(scrollInstance);
      expect(controls).toHaveBeenCalledTimes(1);
    },
  );
});
