import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {
  ActivityIndicator,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
} from 'react-native';
import * as AppleAuthentication from 'expo-apple-authentication';
import SocialAuthView from '../src/components/auth/SocialAuthView';
import {Palette, Spacing} from '../src/constants/designSystem';

const mockInsets = {top: 24, bottom: 34, left: 0, right: 0};
let mockDimensions = {width: 320, height: 568, scale: 1, fontScale: 1};
jest.mock('react-native/Libraries/Utilities/useWindowDimensions', () => ({
  __esModule: true,
  default: () => mockDimensions,
}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => mockInsets,
}));
jest.mock('expo-apple-authentication', () => ({
  AppleAuthenticationButton: 'AppleAuthenticationButton',
  AppleAuthenticationButtonStyle: {WHITE: 1},
  AppleAuthenticationButtonType: {CONTINUE: 1},
}));

type Props = React.ComponentProps<typeof SocialAuthView>;
const makeProps = (): Props => ({
  phase: 'ready',
  methods: null,
  orderedProviderIds: ['google', 'facebook', 'tiktok'],
  recommendedProvider: 'google',
  recommendationText: 'Google مناسب لحسابك 2026',
  loading: null,
  onContinue: jest.fn(),
  onRetry: jest.fn(),
  onExplore: jest.fn(),
  onOpenTerms: jest.fn(),
  onOpenPrivacy: jest.fn(),
});

describe('social sign-in sheet', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const mount = (props: Props) => {
    act(() => {
      renderer = TestRenderer.create(<SocialAuthView {...props} />);
    });
    return renderer;
  };
  const button = (label: string) =>
    renderer.root.findAll(
      node =>
        node.props.accessibilityLabel === label &&
        typeof node.props.onPress === 'function',
    )[0];

  beforeEach(() => {
    mockDimensions = {width: 320, height: 568, scale: 1, fontScale: 1};
  });
  afterEach(() => {
    act(() => renderer?.unmount());
    jest.restoreAllMocks();
  });

  it('dismisses through either the dimmer or close button without starting a provider', () => {
    const props = makeProps();
    mount(props);
    act(() => button('إغلاق خيارات تسجيل الدخول').props.onPress());
    act(() => button('إغلاق').props.onPress());
    expect(props.onExplore).toHaveBeenCalledTimes(2);
    expect(props.onContinue).not.toHaveBeenCalled();
    expect(
      StyleSheet.flatten(button('إغلاق خيارات تسجيل الدخول').props.style),
    ).toMatchObject({
      backgroundColor: Palette.overlay,
      position: 'absolute',
      top: 0,
      bottom: 0,
    });
    const closeStyle = StyleSheet.flatten(
      button('إغلاق').props.style({pressed: false}),
    );
    expect(closeStyle.width).toBeGreaterThanOrEqual(48);
    expect(closeStyle.height).toBeGreaterThanOrEqual(48);
  });

  it('keeps server ordering, authored recommendation, provider actions and legal links', () => {
    const props = {
      ...makeProps(),
      orderedProviderIds: ['tiktok', 'google'] as Props['orderedProviderIds'],
    };
    mount(props);
    const providers = renderer.root
      .findAll(node => typeof node.props.onPress === 'function')
      .filter(node =>
        String(node.props.accessibilityLabel).startsWith('المتابعة بحساب'),
      );
    expect(providers.map(node => node.props.accessibilityLabel)).toEqual([
      'المتابعة بحساب TikTok',
      'المتابعة بحساب Google',
    ]);
    expect(
      renderer.root.findAllByType(Text).map(node => node.props.children),
    ).toContain(props.recommendationText);
    act(() => providers[1].props.onPress());
    expect(props.onContinue).toHaveBeenCalledWith('google');
    expect(props.onExplore).not.toHaveBeenCalled();
    act(() => button('فتح شروط الاستخدام').props.onPress());
    act(() => button('فتح سياسة الخصوصية').props.onPress());
    expect(props.onOpenTerms).toHaveBeenCalledTimes(1);
    expect(props.onOpenPrivacy).toHaveBeenCalledTimes(1);
  });

  it.each(['provider loading', 'authorizing phase'])(
    'blocks close, backdrop and provider activation during %s',
    state => {
      const props = {
        ...makeProps(),
        ...(state === 'provider loading'
          ? {loading: 'google' as const}
          : {phase: 'authorizing' as const}),
      };
      mount(props);
      for (const label of [
        'إغلاق',
        'إغلاق خيارات تسجيل الدخول',
        'المتابعة بحساب Google',
        'المتابعة بحساب Facebook',
      ]) {
        const target = button(label);
        expect(target.props.disabled).toBe(true);
        expect(target.props.accessibilityState.disabled).toBe(true);
        act(() => target.props.onPress());
      }
      expect(props.onExplore).not.toHaveBeenCalled();
      expect(props.onContinue).not.toHaveBeenCalled();
      if (state === 'provider loading')
        expect(renderer.root.findAllByType(ActivityIndicator)).toHaveLength(1);
    },
  );

  it('keeps discovery failure retry inside the sheet', () => {
    const props = {
      ...makeProps(),
      phase: 'discovery_failed' as const,
      failureCode: 'NETWORK_TIMEOUT',
      orderedProviderIds: [],
    };
    mount(props);
    expect(
      renderer.root.findAllByType(Text).map(node => node.props.children),
    ).toContain('تحقق من الاتصال\nثم حاول مرة أخرى');
    act(() => button('إعادة تحميل طرق تسجيل الدخول').props.onPress());
    expect(props.onRetry).toHaveBeenCalledTimes(1);
    expect(button('إغلاق').props.disabled).toBe(false);
  });

  it('bounds the sheet by the viewport and keeps all content scrollable after a landscape/large-text resize', () => {
    const props = makeProps();
    mount(props);
    const sheetStyle = () =>
      StyleSheet.flatten(
        renderer.root.findByProps({testID: 'social-auth-sheet'}).props.style,
      );
    expect(sheetStyle()).toMatchObject({
      maxWidth: 520,
      width: '100%',
      maxHeight: 568 - 24 - Spacing.sm,
      backgroundColor: Palette.surface,
    });
    mockDimensions = {width: 700, height: 320, scale: 1, fontScale: 2.4};
    act(() => renderer.update(<SocialAuthView {...props} />));
    expect(sheetStyle().maxHeight).toBe(320 - 24 - Spacing.sm);
    const scroll = renderer.root.findByType(ScrollView);
    expect(
      StyleSheet.flatten(scroll.props.contentContainerStyle).paddingBottom,
    ).toBe(34 + Spacing.sm);
    const labels = scroll
      .findAll(node => typeof node.props.onPress === 'function')
      .map(node => node.props.accessibilityLabel);
    expect(labels).toEqual(
      expect.arrayContaining([
        'إغلاق',
        'المتابعة بحساب Google',
        'فتح سياسة الخصوصية',
      ]),
    );
    const title = scroll
      .findAllByType(Text)
      .find(node => node.props.accessibilityRole === 'header')!;
    expect(title.props.numberOfLines).toBeUndefined();
  });

  (Platform.OS === 'ios' ? it : it.skip)(
    'retains the native Apple action and blocks it while authorizing',
    () => {
      const props = {
        ...makeProps(),
        orderedProviderIds: ['apple'] as Props['orderedProviderIds'],
      };
      mount(props);
      act(() =>
        renderer.root
          .findByType(AppleAuthentication.AppleAuthenticationButton)
          .props.onPress(),
      );
      expect(props.onContinue).toHaveBeenCalledWith('apple');
      act(() =>
        renderer.update(
          <SocialAuthView {...props} loading="apple" phase="authorizing" />,
        ),
      );
      act(() =>
        renderer.root
          .findByType(AppleAuthentication.AppleAuthenticationButton)
          .props.onPress(),
      );
      expect(props.onContinue).toHaveBeenCalledTimes(1);
    },
  );
});
