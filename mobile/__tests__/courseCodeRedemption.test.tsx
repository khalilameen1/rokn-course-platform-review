import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {
  getDistributionCapabilities,
  type DistributionChannel,
} from '../src/constants/distribution';
import {CoursePurchaseDialog} from '../src/screens/CourseDetails/details/PurchaseDialogs';
import type {CourseAccessPlan} from '../src/services/roknApi';

const mockConfirm = jest.fn();
let mockBusy = false;
let mockPending = false;
jest.mock('../src/hooks/useCourseSubscriptionCheckout', () => ({
  useCourseSubscriptionCheckout: () => ({
    quote: {
      status: 'quoted',
      planCode: 'basic',
      originalPrice: 500,
      discountAmount: 0,
      finalPrice: 500,
      paidCoins: 400,
      rewardCoins: 100,
      paidBalance: 0,
      rewardBalance: 100,
      deficit: 400,
      remainingPaidCoins: 0,
    },
    coinPackage: {id: '1', coins: 400, price: 20, displayPrice: '٢٠ ج م'},
    loading: false,
    busy: mockBusy,
    pending: mockPending,
    notice: '',
    confirm: mockConfirm,
    cancelPending: jest.fn(),
    retry: jest.fn(),
  }),
}));
jest.mock('react-native-linear-gradient', () => 'LinearGradient');
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({bottom: 0, left: 0, right: 0, top: 0}),
}));
jest.mock('react-native/Libraries/Modal/Modal', () => ({
  __esModule: true,
  default: 'Modal',
}));

const plans: CourseAccessPlan[] = [
  {
    code: 'basic',
    name: 'Basic',
    priceCoins: 300,
    chatEnabled: false,
    chatMessageLimit: 0,
    projectsEnabled: false,
    projectFeedbackLevel: 'pass_only',
    projectReportEnabled: false,
    projectOutputEnabled: false,
    certificateEnabled: false,
  },
  {
    code: 'guided',
    name: 'Plus',
    priceCoins: 500,
    chatEnabled: true,
    chatMessageLimit: 50,
    projectsEnabled: true,
    projectFeedbackLevel: 'report',
    projectReportEnabled: true,
    projectOutputEnabled: false,
    certificateEnabled: true,
  },
  {
    code: 'mentor',
    name: 'Pro',
    priceCoins: 750,
    chatEnabled: true,
    chatMessageLimit: 150,
    projectsEnabled: true,
    projectFeedbackLevel: 'enhanced',
    projectReportEnabled: true,
    projectFollowupEnabled: true,
    projectFollowupMessageLimit: 50,
    projectOutputEnabled: true,
    certificateEnabled: true,
  },
];

describe('course-code distribution boundary', () => {
  it.each<[DistributionChannel, boolean, boolean, boolean]>([
    ['direct', true, false, true],
    ['play', false, true, true],
    ['appstore', false, true, false],
  ])(
    'retains channel policy for %s',
    (
      channel,
      canStartExternalCheckout,
      canStartNativeCheckout,
      canRedeemCourseAccessCode,
    ) => {
      expect(getDistributionCapabilities(channel)).toEqual({
        canStartExternalCheckout,
        canStartNativeCheckout,
        canRedeemCourseAccessCode,
      });
    },
  );
});

describe('compact course subscription sheet', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockBusy = false;
    mockPending = false;
  });
  async function mount(selectedPlan = plans[1], grantActivated = false) {
    const onSelectPlan = jest.fn();
    const onRedeem = jest.fn();
    const onCodeChange = jest.fn();
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(() => {
      renderer = TestRenderer.create(
        <CoursePurchaseDialog
          courseId="3"
          accessPlans={plans}
          courseTitle="كورس الإنتاج"
          projectCount={3}
          courseCode="GRANT-42"
          courseCodeEnabled
          dialogStep={grantActivated ? 'success' : 'plans'}
          grantActivated={grantActivated}
          notice=""
          onSubscribed={jest.fn()}
          onClose={jest.fn()}
          onCourseCodeChange={onCodeChange}
          onRedeemCourseCode={onRedeem}
          onSelectPlan={onSelectPlan}
          onSuccessStart={jest.fn()}
          selectedPlan={selectedPlan}
        />,
      );
    });
    return {renderer, onSelectPlan, onRedeem, onCodeChange};
  }
  const buttonWithText = (
    renderer: TestRenderer.ReactTestRenderer,
    text: string,
  ) =>
    renderer.root
      .findAll(
        node =>
          node.props.accessibilityRole === 'button' &&
          typeof node.props.onPress === 'function',
      )
      .find(node =>
        JSON.stringify(
          node
            .findAllByType(require('react-native').Text)
            .map(item => item.props.children),
        ).includes(text),
      )!;

  it('shows three concise choices and real cash shortfall without a package catalogue', async () => {
    const view = await mount();
    const tree = JSON.stringify(view.renderer.toJSON());
    expect(tree).toContain('اختر الاشتراك');
    for (const plan of plans) expect(tree).toContain(plan.name);
    expect(tree).toContain(
      'تنفيذ مشاريع عملية والحصول على تقييم لتحسين مستواك',
    );
    expect(tree).toContain('٢٠ ج م');
    expect(tree).toContain('حصلت على خصم');
    expect(tree).toContain('المطلوب دفعه');
    expect(tree).not.toContain('من رصيدك المشترى');
    expect(tree).not.toContain('يتبقى رصيد مشترى');
    expect(tree).not.toContain('مكافآتك المتبقية محفوظة');
    expect(tree).not.toContain('تغطي المبلغ الناقص');
    expect(tree).not.toContain('تغيير الفئة');
    expect(tree).not.toContain('كود الوصول إلى الكورس');
    await act(() => buttonWithText(view.renderer, 'اشترك').props.onPress());
    expect(mockConfirm).toHaveBeenCalledTimes(1);
    await act(() => view.renderer.unmount());
  });

  it('shows only the educational access code behind one disclosure', async () => {
    const view = await mount(plans[0]);
    await act(() => buttonWithText(view.renderer, 'كود منحة').props.onPress());
    const input = view.renderer.root.find(
      node => node.props.accessibilityLabel === 'كود منحة',
    );
    expect(input.props.value).toBe('GRANT-42');
    await act(() => input.props.onChangeText('NEW-CODE'));
    const submit = view.renderer.root.find(
      node => node.props.accessibilityLabel === 'تفعيل كود المنحة',
    );
    await act(() => submit.props.onPress());
    expect(view.onCodeChange).toHaveBeenCalledWith('NEW-CODE');
    expect(view.onRedeem).toHaveBeenCalledTimes(1);
    expect(JSON.stringify(view.renderer.toJSON())).not.toContain(
      'كود خصم الكورس',
    );
    await act(() => view.renderer.unmount());
  });

  it('shows the selected plan limits directly without a details step', async () => {
    const view = await mount();
    const disclosure = view.renderer.root.findAll(
      node => node.props.accessibilityLabel === 'تفاصيل Plus',
    );
    expect(disclosure).toHaveLength(0);
    expect(JSON.stringify(view.renderer.toJSON())).toContain(
      '٥٠ رسالة لمناقشة محتوى الكورس',
    );
    await act(() => view.renderer.unmount());
  });

  it.each(plans)(
    'offers grant entry only for Basic and consistent payment copy for $name',
    async plan => {
      const view = await mount(plan);
      const grantButton = buttonWithText(view.renderer, 'كود منحة');
      if (plan.code === 'basic') await act(() => grantButton.props.onPress());
      else expect(grantButton).toBeUndefined();
      const {TextInput} = require('react-native');
      expect(view.renderer.root.findAllByType(TextInput)).toHaveLength(
        plan.code === 'basic' ? 1 : 0,
      );
      const tree = JSON.stringify(view.renderer.toJSON());
      expect(tree).toContain('حصلت على خصم');
      expect(tree).toContain('المطلوب دفعه');
      for (const retired of [
        'معك كود',
        'معاك كود',
        'كود خصم',
        'مكافآت مستخدمة',
        'من رصيدك المشترى',
      ]) {
        expect(tree).not.toContain(retired);
      }
      await act(() => view.renderer.unmount());
    },
  );

  it('shows the actual recovered pending plan instead of the default Plus selection', async () => {
    mockPending = true;
    const view = await mount();
    const radios = view.renderer.root.findAll(
      node =>
        node.props.accessibilityRole === 'radio' &&
        typeof node.props.onPress === 'function',
    );
    expect(radios[0].props.accessibilityState.checked).toBe(true);
    expect(radios[1].props.accessibilityState.checked).toBe(false);
    expect(JSON.stringify(view.renderer.toJSON())).toContain(
      'إلغاء طلب الاشتراك',
    );
    expect(buttonWithText(view.renderer, 'كود منحة')).toBeUndefined();
    await act(() => view.renderer.unmount());
  });

  it('locks plan changes while a store authorization is running', async () => {
    mockBusy = true;
    const view = await mount();
    const radios = view.renderer.root.findAll(
      node =>
        node.props.accessibilityRole === 'radio' &&
        typeof node.props.onPress === 'function',
    );
    expect(radios).toHaveLength(3);
    radios.forEach(node => expect(node.props.disabled).toBe(true));
    await act(() => view.renderer.unmount());
  });

  it('shows grant success with watch-only terms once and no payment or upsell', async () => {
    const view = await mount(plans[0], true);
    const {Text} = require('react-native');
    const copy = view.renderer.root
      .findAllByType(Text)
      .map(node => node.props.children);
    expect(copy.filter(text => text === 'مشاهدة الكورس مجانًا')).toHaveLength(
      1,
    );
    expect(copy).toContain('تم تفعيل المنحة');
    expect(copy).toContain('بدون شهادة اجتياز للكورس');
    expect(copy).not.toContain('المطلوب دفعه');
    expect(copy).not.toContain('حصلت على خصم');
    expect(copy).not.toContain('Plus');
    expect(buttonWithText(view.renderer, 'ابدأ الكورس')).toBeDefined();
    expect(buttonWithText(view.renderer, 'كود منحة')).toBeUndefined();
    await act(() => view.renderer.unmount());
  });

  it('omits practical projects for a theory course', async () => {
    const {
      subscriptionPlanDetails,
    } = require('../src/components/CourseSubscriptionSheet');
    const details = subscriptionPlanDetails(plans[2], false).map(
      (row: {text: string}) => row.text,
    );
    expect(details.some((text: string) => text.includes('مشاريع'))).toBe(false);
    expect(details).toContain('شهادة بعد اجتياز الكورس');
  });

  it('does not advertise project discussion when the plan excludes projects', () => {
    const {
      subscriptionPlanDetails,
    } = require('../src/components/CourseSubscriptionSheet');
    const details = subscriptionPlanDetails(
      {...plans[2], projectsEnabled: false},
      true,
    ).map((row: {text: string}) => row.text);
    expect(details.some((text: string) => text.includes('مشاريع'))).toBe(false);
  });
});
