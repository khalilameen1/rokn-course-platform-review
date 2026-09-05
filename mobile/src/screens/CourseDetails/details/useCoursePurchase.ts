import {useCallback, useEffect} from 'react';
import type {Dispatch, SetStateAction} from 'react';
import {CAN_REDEEM_COURSE_ACCESS_CODE} from '../../../constants/distribution';
import type {
  CourseDetailsRouteParams,
  RootNavigation,
} from '../../../navigation/types';
import type {CourseDetails as CourseDetailsDto} from '../../../services/roknApi';
import {selectCourseDetailsPresentation} from './selectors';
import {derivePurchaseTerms} from './purchaseTerms';
import {useCourseAccessCode} from './useCourseAccessCode';
import {useCourseCheckout} from './useCourseCheckout';
import {useCourseCoupon} from './useCourseCoupon';
import {useCoursePurchaseFlow} from './useCoursePurchaseFlow';
import {usePurchaseEntry} from './usePurchaseEntry';
import type {CourseDetailsData} from './useCourseDetailsData';

type Params = {
  courseId: string;
  data: CourseDetailsData;
  identityKey: string;
  navigation: RootNavigation;
  routeParams: CourseDetailsRouteParams;
  setNotice: Dispatch<SetStateAction<string>>;
};

export const useCoursePurchase = ({
  courseId,
  data,
  identityKey,
  navigation,
  routeParams,
  setNotice,
}: Params) => {
  const {course, commerce} = data;
  const remoteCourse: CourseDetailsDto | null = course.value;
  const {
    close: closePurchase,
    ensurePlan,
    openForTerms,
    reset: resetPurchase,
    restorePlan,
    restoredPlanKey,
    selectPlanForTerms,
    selectedPlanCode,
    showConfirm,
    showPlans,
    showSuccess,
    showTopup,
    step: dialogStep,
  } = useCoursePurchaseFlow();

  const presentation = selectCourseDetailsPresentation({
    remoteBalance: commerce.balance,
    remoteCommerceLoading: commerce.loading,
    remoteCourse,
    remoteError: course.error,
    remoteLoading: course.loading,
    remotePaidBalance: commerce.paidBalance,
    remotePackages: commerce.packages,
    remoteRewardBalance: commerce.rewardBalance,
    remoteRewardContributionCap: commerce.rewardContributionCap,
    remoteSession: course.session,
    remoteSpendableBalance: commerce.spendableBalance,
    selectedPlanCode,
  });

  const {
    accessPlans,
    balance,
    canChooseAccess,
    owned,
    packages,
    pageReady,
    planSpendableBalances,
    primaryAction,
    purchasePrice,
    rewardContributionLimit,
    selectedPlan,
    spendableBalance,
  } = presentation;

  useEffect(() => {
    setNotice('');
    resetPurchase();
  }, [courseId, identityKey, resetPurchase, setNotice]);

  useEffect(() => {
    if (!accessPlans.length) return;
    const resumedPlanCode = String(routeParams.purchasePlanCode || '').trim();
    if (
      routeParams.openPurchase &&
      resumedPlanCode &&
      accessPlans.some(plan => plan.code === resumedPlanCode) &&
      (restoredPlanKey !== `${courseId}|${resumedPlanCode}` ||
        selectedPlanCode !== resumedPlanCode)
    ) {
      restorePlan(resumedPlanCode, `${courseId}|${resumedPlanCode}`);
      return;
    }
    if (!accessPlans.some(plan => plan.code === selectedPlanCode)) {
      ensurePlan(accessPlans[0].code);
    }
  }, [
    accessPlans,
    courseId,
    ensurePlan,
    restorePlan,
    restoredPlanKey,
    routeParams.openPurchase,
    routeParams.purchasePlanCode,
    selectedPlanCode,
  ]);

  const coupon = useCourseCoupon({
    balance,
    courseId,
    identityKey,
    originalPrice: purchasePrice,
    packages,
    pageReady,
    paidBalance: commerce.paidBalance,
    publishedRevision: remoteCourse?.publishedRevision,
    rewardBalance: commerce.rewardBalance,
    rewardContributionLimit,
    routeParams,
    selectedPlan,
    session: course.session,
    showConfirm,
    showTopup,
    setNotice,
  });
  const appliedCoupon = coupon.applied;
  const applyCoupon = coupon.apply;
  const couponBusy = coupon.busy;
  const couponQuote = coupon.quote;
  const changeCouponCode = coupon.changeCode;
  const effectivePurchasePrice = coupon.effectivePrice;
  const invalidateCoupon = coupon.invalidate;
  const purchaseCouponCode = coupon.code;
  const replaceCouponQuote = coupon.replaceQuote;
  const purchaseTerms = derivePurchaseTerms({
    balance,
    minimumPaidCoins: selectedPlan?.minimumPaidCoins ?? 0,
    packages,
    paidBalance: commerce.paidBalance,
    price: effectivePurchasePrice,
    rewardBalance: commerce.rewardBalance,
    rewardContributionLimit,
  });
  const {
    rewardContributionLimit: effectiveRewardContributionLimit,
    rewardContributionPercent: effectiveRewardContributionPercent,
    shortfall: effectiveShortfall,
    spendableBalance: effectiveSpendableBalance,
    sufficientPackage: effectiveSufficientPackage,
    sufficientPackages: effectivePackages,
    usableCurrentBalance: effectiveUsableCurrentBalance,
  } = purchaseTerms;

  const purchaseRestoreStatus = coupon.restoreStatus;
  const checkout = useCourseCheckout({
    closePurchase,
    couponApplied: appliedCoupon,
    couponCode: couponQuote?.couponCode,
    courseId,
    effectivePrice: effectivePurchasePrice,
    identityKey,
    invalidateCoupon,
    packages,
    publishedRevision: remoteCourse?.publishedRevision,
    purchasePrice,
    reload: course.reload,
    replaceCouponQuote,
    selectedPlan,
    shortfall: effectiveShortfall,
    showConfirm,
    showPlans,
    showSuccess,
    showTopup,
    setNotice,
    setOwned: course.setOwned,
    setPackages: commerce.setPackages,
    updateWallet: commerce.updateWallet,
  });

  useEffect(() => {
    if (
      dialogStep !== 'topup' ||
      checkout.busy ||
      commerce.balance === null ||
      effectiveShortfall > 0
    ) {
      return;
    }
    setNotice('تم تحديث رصيدك\nراجع الإجمالي ثم أكد الشراء');
    showConfirm();
  }, [
    checkout.busy,
    commerce.balance,
    dialogStep,
    effectiveShortfall,
    setNotice,
    showConfirm,
  ]);

  useEffect(() => {
    if (!owned || dialogStep === null || dialogStep === 'success') return;
    setNotice('');
    showSuccess();
  }, [dialogStep, owned, setNotice, showSuccess]);

  const {closeDialog, openLogin, retention, runPrimaryAction} =
    usePurchaseEntry({
      accessPlans,
      busy: checkout.busy,
      couponBusy,
      courseId,
      dialogStep,
      effectivePurchasePrice,
      effectiveSpendableBalance,
      identityKey,
      navigation,
      owned,
      pageReady,
      primaryAction,
      purchaseCouponCode,
      purchasePrice,
      purchaseRestoreStatus,
      remoteSession: course.session,
      routeParams,
      selectedPlanCode: selectedPlan?.code,
      closePurchase,
      openForTerms,
      showPlans,
      setNotice,
      spendableBalance,
    });

  const accessCode = useCourseAccessCode({
    checkoutBusy: checkout.busy,
    courseId,
    identityKey,
    openLogin,
    session: course.session,
    closePurchase,
    showSuccess,
    setNotice,
    setOwned: course.setOwned,
  });

  const changePlan = useCallback(() => {
    invalidateCoupon(true);
    setNotice('');
    showPlans();
  }, [invalidateCoupon, setNotice, showPlans]);

  const selectPlan = useCallback(
    (plan: (typeof accessPlans)[number]) => {
      invalidateCoupon(true);
      setNotice('');
      selectPlanForTerms(plan.code, {
        purchasePrice: plan.priceCoins,
        spendableBalance: planSpendableBalances[plan.code] ?? 0,
      });
    },
    [invalidateCoupon, planSpendableBalances, selectPlanForTerms, setNotice],
  );

  return {
    presentation,
    runPrimaryAction,
    retention,
    dialog: {
      accessPlans,
      balance,
      busy: checkout.busy,
      codeBusy: accessCode.busy,
      courseCode: accessCode.code,
      courseCodeEnabled: CAN_REDEEM_COURSE_ACCESS_CODE && canChooseAccess,
      couponApplied: appliedCoupon,
      couponBusy,
      couponCode: purchaseCouponCode,
      couponDiscountAmount: appliedCoupon
        ? couponQuote?.discountAmount ?? 0
        : 0,
      dialogStep,
      grantActivated: accessCode.grantActivated,
      onApplyCoupon: applyCoupon,
      onBuyCoins: checkout.buyCoins,
      onChangePlan: changePlan,
      onClose: closeDialog,
      onConfirmPurchase: checkout.confirm,
      onCouponCodeChange: changeCouponCode,
      onCourseCodeChange: accessCode.setCode,
      onRedeemCourseCode: accessCode.redeem,
      onSelectPlan: selectPlan,
      packages: effectivePackages,
      originalPurchasePrice: purchasePrice,
      purchasePrice: effectivePurchasePrice,
      rewardContributionLimit: effectiveRewardContributionLimit,
      rewardContributionPercent: effectiveRewardContributionPercent,
      selectedPlan,
      shortfall: effectiveShortfall,
      sufficientPackage: effectiveSufficientPackage,
      usableCurrentBalance: effectiveUsableCurrentBalance,
    },
    closeSuccess: closePurchase,
  };
};
