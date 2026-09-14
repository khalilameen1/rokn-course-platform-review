import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {
  getDistributionCapabilities,
  type DistributionChannel,
} from '../src/constants/distribution';
import {CoursePurchaseDialog} from '../src/screens/CourseDetails/details/PurchaseDialogs';
import type {CourseAccessPlan} from '../src/services/roknApi';

const mockConfirm = jest.fn();
const mockApply = jest.fn();
const mockSetCoupon = jest.fn();
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
    coupon: 'SAVE',
    setCoupon: mockSetCoupon,
    appliedCoupon: '',
    applyCoupon: mockApply,
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
  async function mount() {
    const onSelectPlan = jest.fn();
    const onRedeem = jest.fn();
    const onCodeChange = jest.fn();
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(() => {
      renderer = TestRenderer.create(
        <CoursePurchaseDialog
          courseId="3"
          accessPlans={plans}
          balance={100}
          bottomInset={0}
          busy={false}
          courseTitle="كورس الإنتاج"
          projectCount={3}
          courseCode="GRANT-42"
          courseCodeEnabled
          dialogStep="plans"
          grantActivated={false}
          isTablet={false}
          notice=""
          onBuyCoins={jest.fn()}
          onClose={jest.fn()}
          onConfirmPurchase={jest.fn()}
          onCourseCodeChange={onCodeChange}
          onRedeemCourseCode={onRedeem}
          onSelectPlan={onSelectPlan}
          onSuccessStart={jest.fn()}
          packages={[]}
          purchasePrice={500}
          rewardContributionLimit={100}
          rewardContributionPercent={20}
          selectedPlan={plans[1]}
          shortfall={400}
          usableCurrentBalance={100}
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
    expect(tree).toContain('تدريب أعمق وتطوير مشروعك');
    expect(tree).toContain('٢٠ ج م');
    expect(tree).toContain('مكافآت مستخدمة');
    expect(tree).not.toContain('تغطي المبلغ الناقص');
    expect(tree).not.toContain('تغيير الفئة');
    expect(tree).not.toContain('كود الوصول إلى الكورس');
    await act(() =>
      buttonWithText(view.renderer, 'شحن واشتراك').props.onPress(),
    );
    expect(mockConfirm).toHaveBeenCalledTimes(1);
    await act(() => view.renderer.unmount());
  });

  it('keeps optional coupon and educational code behind one disclosure', async () => {
    const view = await mount();
    await act(() => buttonWithText(view.renderer, 'معاك كود').props.onPress());
    const input = view.renderer.root.find(
      node => node.props.accessibilityLabel === 'كود الوصول إلى الكورس',
    );
    expect(input.props.value).toBe('GRANT-42');
    await act(() => input.props.onChangeText('NEW-CODE'));
    const submit = view.renderer.root.find(
      node => node.props.accessibilityLabel === 'تفعيل كود الوصول',
    );
    await act(() => submit.props.onPress());
    expect(view.onCodeChange).toHaveBeenCalledWith('NEW-CODE');
    expect(view.onRedeem).toHaveBeenCalledTimes(1);
    expect(JSON.stringify(view.renderer.toJSON())).toContain('SAVE');
    await act(() => view.renderer.unmount());
  });

  it('discloses actual message limits only on request', async () => {
    const view = await mount();
    expect(JSON.stringify(view.renderer.toJSON())).not.toContain(
      'حتى ٥٠ رسالة للأسئلة',
    );
    const disclosure = view.renderer.root.find(
      node => node.props.accessibilityLabel === 'تفاصيل Plus',
    );
    await act(() => disclosure.props.onPress());
    expect(JSON.stringify(view.renderer.toJSON())).toContain(
      'حتى ٥٠ رسالة للأسئلة',
    );
    await act(() => view.renderer.unmount());
  });

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
});
