import React from 'react';
import {ScrollView, StyleSheet, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import CourseSubscriptionSheet, {
  type CourseSubscriptionSheetProps,
} from '../src/components/CourseSubscriptionSheet';
import type {CourseAccessPlan} from '../src/services/roknApi';

let mockDimensions = {width: 360, height: 800, scale: 2, fontScale: 1};
const mockConfirm = jest.fn();
const mockCancel = jest.fn();
let mockBlocked = false;
jest.mock('react-native/Libraries/Utilities/useWindowDimensions', () => ({
  __esModule: true,
  default: () => mockDimensions,
}));
jest.mock('react-native/Libraries/Modal/Modal', () => ({
  __esModule: true,
  default: 'Modal',
}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 24, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('../src/hooks/useCourseSubscriptionCheckout', () => ({
  useCourseSubscriptionCheckout: () => ({
    quote: {status: 'quoted', deficit: 0, rewardCoins: 80},
    loading: false,
    busy: false,
    pending: false,
    blockedByPreviousCheckout: mockBlocked,
    notice: '',
    confirm: mockConfirm,
    cancelPending: mockCancel,
  }),
}));

const plans: CourseAccessPlan[] = ['basic', 'guided', 'mentor'].map(
  (code, index) => ({
    code,
    name: code,
    priceCoins: [400, 650, 900][index],
    chatEnabled: index > 0,
    chatMessageLimit: 40,
    projectsEnabled: index > 0,
    projectFeedbackLevel: 'report',
    projectReportEnabled: index > 0,
    projectOutputEnabled: false,
    certificateEnabled: index > 0,
  }),
);

describe('horizontal subscription comparison on phones', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const onSelectPlan = jest.fn();
  const props: CourseSubscriptionSheetProps = {
    visible: true,
    courseId: 'test-course',
    courseTitle: 'عمل الطباعة ثلاثية الأبعاد',
    plans,
    selectedPlan: plans[0],
    onSelectPlan,
    onCompleted: jest.fn(),
    onClose: jest.fn(),
    hasProjects: true,
  };
  const mount = (overrides: Partial<CourseSubscriptionSheetProps> = {}) => {
    act(() => {
      renderer = TestRenderer.create(
        <CourseSubscriptionSheet {...props} {...overrides} />,
      );
    });
  };
  const choices = () =>
    renderer.root.findAll(
      node =>
        node.props.accessibilityRole === 'radio' &&
        typeof node.props.style === 'function',
    );
  const rail = () =>
    renderer.root
      .findAllByType(ScrollView)
      .find(node => node.props.accessibilityRole === 'radiogroup')!;
  const choiceStyle = (index = 0) =>
    StyleSheet.flatten(choices()[index].props.style({pressed: false}));

  beforeEach(() => {
    jest.clearAllMocks();
    mockBlocked = false;
    mockDimensions = {width: 360, height: 800, scale: 2, fontScale: 1};
  });
  afterEach(() => act(() => renderer?.unmount()));

  it('offers explicit recovery and cancellation of the previous course payment', () => {
    mockBlocked = true;
    mount();
    const text = renderer.root
      .findAllByType(Text)
      .map(node => node.props.children);
    expect(text).toContain('التحقق من الدفع السابق');
    expect(text).toContain('إلغاء الطلب السابق');
    expect(text).not.toContain('ابدأ الكورس');
    expect(choices().every(node => node.props.disabled)).toBe(true);
    act(() =>
      renderer.root
        .findByProps({accessibilityLabel: 'إلغاء الطلب السابق'})
        .props.onPress(),
    );
    expect(mockCancel).toHaveBeenCalledTimes(1);
    expect(mockConfirm).not.toHaveBeenCalled();
  });

  // Structural regression coverage, not a substitute for native text rendering.
  it.each(
    [320, 360, 390, 768].flatMap(width =>
      [1, 1.15, 1.3, 1.35, 1.5].map(fontScale => ({width, fontScale})),
    ),
  )(
    'fits three horizontal choices at $width dp / $fontScale text',
    dimensions => {
      mockDimensions = {...mockDimensions, ...dimensions};
      mount();
      expect(rail().props.horizontal).toBe(true);
      const row = StyleSheet.flatten(rail().props.contentContainerStyle);
      expect(row).toMatchObject({
        flexDirection: 'row',
        direction: 'rtl',
        flexGrow: 1,
      });
      expect(choices()).toHaveLength(3);
      expect(choiceStyle()).toMatchObject({
        flexGrow: 1,
        flexShrink: 0,
        flexBasis: 0,
      });
      const minimumRowWidth =
        choices().length * choiceStyle().minWidth + row.gap * 2;
      expect(minimumRowWidth).toBeLessThanOrEqual(
        Math.min(dimensions.width, 620) - 36,
      );
    },
  );

  it('keeps enlarged text horizontal and scrollable through resize and embedding', () => {
    mount();
    for (const fontScale of [2, 3]) {
      mockDimensions = {...mockDimensions, width: 320, fontScale};
      act(() =>
        renderer.update(<CourseSubscriptionSheet {...props} embedded />),
      );
      expect(rail().props.horizontal).toBe(true);
      expect(rail().props.scrollEnabled).not.toBe(false);
      expect(choiceStyle().minWidth * 3 + 16).toBeGreaterThan(320 - 36);
      expect(choices()).toHaveLength(3);
      const label = renderer.root
        .findAllByType(Text)
        .find(node => node.props.children === 'Basic')!;
      expect(label.props.allowFontScaling).not.toBe(false);
    }
    mockDimensions = {...mockDimensions, width: 800, fontScale: 1};
    act(() => renderer.update(<CourseSubscriptionSheet {...props} />));
    expect(choiceStyle().minWidth).toBe(88);
    expect(rail().props.horizontal).toBe(true);
  });

  it('retains selection, a separate payment action and locking', () => {
    mount();
    act(() => choices()[1].props.onPress());
    expect(onSelectPlan).toHaveBeenCalledWith(plans[1]);
    expect(mockConfirm).not.toHaveBeenCalled();
    act(() =>
      renderer.update(
        <CourseSubscriptionSheet {...props} selectedPlan={plans[1]} />,
      ),
    );
    expect(
      choices().map(node => node.props.accessibilityState.checked),
    ).toEqual([false, true, false]);
    const vertical = renderer.root
      .findAllByType(ScrollView)
      .find(node => !node.props.horizontal)!;
    const subscribe = renderer.root
      .findAll(node => typeof node.props.style === 'function')
      .find(node =>
        node.findAllByType(Text).some(text => text.props.children === 'اشترك'),
      )!;
    expect(
      vertical.findAll(node => typeof node.props.style === 'function'),
    ).not.toContain(subscribe);
    act(() =>
      renderer.update(<CourseSubscriptionSheet {...props} externalBusy />),
    );
    expect(choices().every(node => node.props.disabled)).toBe(true);
  });
});
