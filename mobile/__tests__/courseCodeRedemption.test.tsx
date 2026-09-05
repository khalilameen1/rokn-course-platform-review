import React from 'react';
import {StyleSheet} from 'react-native';
import ReactTestRenderer from 'react-test-renderer';

jest.mock('react-native-linear-gradient', () => 'LinearGradient');
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({bottom: 0, left: 0, right: 0, top: 0}),
}));
jest.mock('react-native/Libraries/Modal/Modal', () => ({
  __esModule: true,
  default: 'Modal',
}));

import {
  getDistributionCapabilities,
  type DistributionChannel,
} from '../src/constants/distribution';
import {CoursePurchaseDialog} from '../src/screens/CourseDetails/details/PurchaseDialogs';
import type {CourseAccessPlan} from '../src/services/roknApi';

const plans: CourseAccessPlan[] = [
  {
    code: 'basic',
    name: 'التعلّم',
    priceCoins: 300,
    chatEnabled: false,
    chatMessageLimit: 0,
    projectFeedbackLevel: 'pass_only',
    projectReportEnabled: false,
    projectOutputEnabled: false,
    certificateEnabled: true,
  },
  {
    code: 'guided',
    name: 'التعلّم بإرشاد',
    priceCoins: 500,
    chatEnabled: true,
    chatMessageLimit: 25,
    projectFeedbackLevel: 'report',
    projectReportEnabled: true,
    projectOutputEnabled: false,
    certificateEnabled: true,
  },
  {
    code: 'mentor',
    name: 'التعلّم بمتابعة',
    priceCoins: 700,
    chatEnabled: true,
    chatMessageLimit: 80,
    projectFeedbackLevel: 'enhanced',
    projectReportEnabled: true,
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
    'applies the expected checkout/redemption policy to %s',
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

describe('course-code redemption UI', () => {
  it('keeps all three plans and reveals educational code entry inside the purchase dialog', async () => {
    const onSelectPlan = jest.fn();
    const onRedeem = jest.fn();
    const onCourseCodeChange = jest.fn();
    let renderer: ReactTestRenderer.ReactTestRenderer;

    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <CoursePurchaseDialog
          accessPlans={plans}
          balance={1000}
          bottomInset={0}
          busy={false}
          codeBusy={false}
          courseTitle="كورس الإنتاج"
          courseCode="GRANT-42"
          courseCodeEnabled
          dialogStep="plans"
          grantActivated={false}
          isTablet={false}
          notice=""
          onBuyCoins={jest.fn()}
          onClose={jest.fn()}
          onConfirmPurchase={jest.fn()}
          onCourseCodeChange={onCourseCodeChange}
          onRedeemCourseCode={onRedeem}
          onSelectPlan={onSelectPlan}
          onSuccessStart={jest.fn()}
          packages={[]}
          purchasePrice={300}
          rewardContributionLimit={300}
          rewardContributionPercent={100}
          selectedPlan={plans[0]}
          shortfall={0}
          usableCurrentBalance={300}
        />,
      );
    });

    const tree = JSON.stringify(renderer!.toJSON());
    for (const plan of plans) expect(tree).toContain(plan.name);
    expect(tree).toContain('اكتب الكود');
    expect(tree).not.toContain('كود خصم');
    const input = renderer!.root.find(
      node => node.props.accessibilityLabel === 'كود الوصول إلى الكورس',
    );
    const submit = renderer!.root.find(
      node => node.props.accessibilityLabel === 'تفعيل كود الوصول',
    );
    expect(input.props.value).toBe('GRANT-42');
    await ReactTestRenderer.act(() => input.props.onChangeText('NEW-CODE'));
    await ReactTestRenderer.act(() => submit.props.onPress());
    expect(onCourseCodeChange).toHaveBeenCalledWith('NEW-CODE');
    expect(onRedeem).toHaveBeenCalledTimes(1);

    await ReactTestRenderer.act(() => renderer!.unmount());
  });

  it('reveals the promo code only after a pricing tier has been selected', async () => {
    let renderer: ReactTestRenderer.ReactTestRenderer;

    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <CoursePurchaseDialog
          accessPlans={plans}
          balance={1000}
          bottomInset={0}
          busy={false}
          courseTitle="كورس الإنتاج"
          couponCode="SAVE"
          dialogStep="confirm"
          grantActivated={false}
          isTablet={false}
          notice=""
          onApplyCoupon={jest.fn()}
          onBuyCoins={jest.fn()}
          onClose={jest.fn()}
          onConfirmPurchase={jest.fn()}
          onSelectPlan={jest.fn()}
          onSuccessStart={jest.fn()}
          packages={[]}
          purchasePrice={300}
          rewardContributionLimit={300}
          rewardContributionPercent={100}
          selectedPlan={plans[0]}
          shortfall={0}
          usableCurrentBalance={300}
        />,
      );
    });

    const tree = JSON.stringify(renderer!.toJSON());
    expect(tree).toContain('كود خصم');
    expect(tree).toContain('SAVE');

    await ReactTestRenderer.act(() => renderer!.unmount());
  });

  it.each([false, true])('keeps inline top-up actions consistent with coupon calculation (busy=%s)', async couponBusy => {
    const onBuyCoins = jest.fn();
    const onChangePlan = jest.fn();
    let renderer: ReactTestRenderer.ReactTestRenderer;

    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <CoursePurchaseDialog
          accessPlans={plans}
          balance={100}
          bottomInset={0}
          busy={false}
          courseTitle="كورس الإنتاج"
          couponBusy={couponBusy}
          dialogStep="topup"
          grantActivated={false}
          isTablet={false}
          notice=""
          onBuyCoins={onBuyCoins}
          onChangePlan={onChangePlan}
          onClose={jest.fn()}
          onConfirmPurchase={jest.fn()}
          onSelectPlan={jest.fn()}
          onSuccessStart={jest.fn()}
          packages={[
            {
              id: 'coins-1000',
              coins: 1000,
              price: 99,
              label: 'باقة رصيد مدفوع للاستخدام في الكورسات العملية',
              displayPrice: '٩٩٫٠٠ جنيه مصري شامل الضريبة',
            },
            {
              id: 'coins-1500',
              coins: 1500,
              price: 139,
              label: 'رصيد مدفوع',
            },
          ]}
          purchasePrice={700}
          rewardContributionLimit={300}
          rewardContributionPercent={42}
          selectedPlan={plans[2]}
          shortfall={600}
          sufficientPackage={{
            id: 'coins-1000',
            coins: 1000,
            price: 99,
            label: 'رصيد مدفوع',
          }}
          usableCurrentBalance={100}
        />,
      );
    });

    const tree = JSON.stringify(renderer!.toJSON());
    expect(tree).toContain('تغطي المبلغ الناقص');
    expect(tree).toContain('٩٩٫٠٠ جنيه مصري شامل الضريبة');
    expect(tree).toContain('١٣٩');
    expect(tree).toContain('جنيه');
    expect(tree).toContain('يتبقى ');
    expect(tree).toContain('٤٠٠');
    expect(tree).not.toContain('اختيار الباقة');
    const actions = renderer!.root.findAll(
      node =>
        node.props.accessibilityRole === 'button' &&
        typeof node.props.onPress === 'function',
    );
    const paymentAction = actions.find(node =>
      String(node.props.accessibilityLabel || '').includes(
        '٩٩٫٠٠ جنيه مصري شامل الضريبة',
      ),
    );
    expect(paymentAction).toBeDefined();
    expect(paymentAction!.props.disabled).toBe(couponBusy);
    const unresolvedPackageCardStyle = paymentAction!.props.style;
    const packageCardStyle = StyleSheet.flatten(
      typeof unresolvedPackageCardStyle === 'function'
        ? unresolvedPackageCardStyle({pressed: false})
        : unresolvedPackageCardStyle,
    );
    expect(packageCardStyle).toMatchObject({
      minWidth: 0,
      padding: 15,
      width: '100%',
    });
    const changePlan = renderer!.root.find(
      node => node.props.accessibilityLabel === 'تغيير فئة الكورس',
    );
    expect(changePlan.props.disabled).toBe(couponBusy);
    if (!couponBusy) {
      await ReactTestRenderer.act(() => paymentAction!.props.onPress());
      expect(onBuyCoins).toHaveBeenCalledTimes(1);
      expect(onBuyCoins).toHaveBeenCalledWith(
        expect.objectContaining({id: 'coins-1000'}),
      );
      await ReactTestRenderer.act(() => changePlan.props.onPress());
      expect(onChangePlan).toHaveBeenCalledTimes(1);
    }

    await ReactTestRenderer.act(() => renderer!.unmount());
  });
});
