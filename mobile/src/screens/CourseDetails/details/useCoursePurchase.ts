import {useCallback, useEffect} from 'react';
import type {Dispatch, SetStateAction} from 'react';
import {CAN_REDEEM_COURSE_ACCESS_CODE} from '../../../constants/distribution';
import type {
  CourseDetailsRouteParams,
  RootNavigation,
} from '../../../navigation/types';
import type {CourseDetails as CourseDetailsDto} from '../../../services/roknApi';
import {selectCourseDetailsPresentation} from './selectors';
import {useCourseAccessCode} from './useCourseAccessCode';
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
    showPlans,
    showSuccess,
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
    canChooseAccess,
    owned,
    pageReady,
    planSpendableBalances,
    primaryAction,
    purchasePrice,
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
      ensurePlan(
        (accessPlans.find(plan => plan.code === 'guided') || accessPlans[0])
          .code,
      );
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

  // CourseSubscriptionSheet owns quotes, payment and recovery for every plan.
  // Grant access codes are the only alternate entry on this screen.

  useEffect(() => {
    if (!owned || dialogStep === null || dialogStep === 'success') return;
    setNotice('');
    showSuccess();
  }, [dialogStep, owned, setNotice, showSuccess]);

  const {closeDialog, openLogin, retention, runPrimaryAction} =
    usePurchaseEntry({
      accessPlans,
      courseId,
      dialogStep,
      identityKey,
      navigation,
      owned,
      pageReady,
      primaryAction,
      purchasePrice,
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
    // CourseSubscriptionSheet locks this entry while authorizing payment.
    checkoutBusy: false,
    courseId,
    identityKey,
    openLogin,
    session: course.session,
    closePurchase,
    showSuccess,
    setNotice,
    setOwned: course.setOwned,
  });

  const selectPlan = useCallback(
    (plan: (typeof accessPlans)[number]) => {
      setNotice('');
      selectPlanForTerms(plan.code, {
        purchasePrice: plan.priceCoins,
        spendableBalance: planSpendableBalances[plan.code] ?? 0,
      });
    },
    [planSpendableBalances, selectPlanForTerms, setNotice],
  );

  return {
    presentation,
    runPrimaryAction,
    retention,
    dialog: {
      courseId,
      courseRevision: remoteCourse?.publishedRevision,
      onSubscribed: async () => {
        course.setOwned(true);
        showSuccess();
        course.reload();
      },
      accessPlans,
      codeBusy: accessCode.busy,
      grantActivated: accessCode.grantActivated,
      courseCode: accessCode.code,
      courseCodeEnabled: CAN_REDEEM_COURSE_ACCESS_CODE && canChooseAccess,
      dialogStep,
      onClose: closeDialog,
      onCourseCodeChange: accessCode.setCode,
      onRedeemCourseCode: accessCode.redeem,
      onSelectPlan: selectPlan,
      selectedPlan,
    },
    closeSuccess: closePurchase,
  };
};
