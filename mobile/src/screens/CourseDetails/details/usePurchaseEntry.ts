import {useCallback, useEffect, useRef, useState} from 'react';
import type {Dispatch, SetStateAction} from 'react';
import {CAN_START_COIN_CHECKOUT} from '../../../constants/distribution';
import {openGuestLogin} from '../../../navigation/journeyNavigation';
import type {
  CourseDetailsRouteParams,
  LoginReturnTo,
  RootNavigation,
} from '../../../navigation/types';
import type {CourseAccessPlan} from '../../../services/roknApi';
import {trackProductEvent} from '../../../services/productAnalytics';
import {normalizeHumanIdentifier} from '../../../utils/unicodeText';
import type {DialogStep, PurchaseFlowTerms} from './useCoursePurchaseFlow';
import {type CoursePrimaryAction} from './selectors';

type Params = {
  accessPlans: CourseAccessPlan[];
  busy: boolean;
  couponBusy: boolean;
  courseId: string;
  coursePrice: number | null;
  dialogStep: DialogStep;
  effectivePurchasePrice: number;
  effectiveSpendableBalance: number;
  identityKey: string;
  navigation: RootNavigation;
  owned: boolean;
  pageReady: boolean;
  primaryAction: CoursePrimaryAction;
  purchaseCouponCode: string;
  purchasePrice: number;
  purchaseRestoreStatus: 'idle' | 'quoting' | 'ready' | 'failed';
  remoteBalance: number | null;
  remoteSession: boolean | null;
  routeParams: CourseDetailsRouteParams;
  selectedPlanCode?: string;
  closePurchase: () => void;
  openForTerms: (terms: PurchaseFlowTerms) => void;
  showPlans: () => void;
  setNotice: Dispatch<SetStateAction<string>>;
  spendableBalance: number;
};

type PrimaryActionHandlers = {
  onPreview: () => void;
  onStart: () => void;
};

const retentionShownCourses = new Set<string>();

const rememberRetentionOffer = (key: string) => {
  retentionShownCourses.delete(key);
  retentionShownCourses.add(key);
  while (retentionShownCourses.size > 64) {
    const oldest = retentionShownCourses.values().next().value;
    if (typeof oldest !== 'string') break;
    retentionShownCourses.delete(oldest);
  }
};

export function usePurchaseEntry({
  accessPlans,
  busy,
  couponBusy,
  courseId,
  coursePrice,
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
  remoteBalance,
  remoteSession,
  routeParams,
  selectedPlanCode,
  closePurchase,
  openForTerms,
  showPlans,
  setNotice,
  spendableBalance,
}: Params) {
  const autoHandledRef = useRef(false);
  const [retentionQueued, setRetentionQueued] = useState(false);
  const [retentionVisible, setRetentionVisible] = useState(false);

  useEffect(() => {
    autoHandledRef.current = false;
    setRetentionQueued(false);
    setRetentionVisible(false);
  }, [courseId, identityKey]);

  const consumeRouteIntent = useCallback(() => {
    navigation.setParams({
      openPurchase: false,
      purchasePlanCode: undefined,
      purchaseCouponCode: undefined,
    });
  }, [navigation]);

  const openLogin = useCallback(() => {
    const requestedPlan = String(routeParams.purchasePlanCode || '').trim();
    const planCode = accessPlans.some(plan => plan.code === requestedPlan)
      ? requestedPlan
      : '';
    const couponCode = normalizeHumanIdentifier(
      purchaseCouponCode || routeParams.purchaseCouponCode,
    );
    const returnTo: LoginReturnTo = {
      name: 'CourseDetails',
      params: {
        courseId,
        openPurchase: true,
        ...(planCode ? {purchasePlanCode: planCode} : {}),
        ...(couponCode ? {purchaseCouponCode: couponCode} : {}),
        ...(routeParams.resumeAfterPreview
          ? {
              resumeAfterPreview: true,
              ...(String(routeParams.resumeReelId || '').trim()
                ? {resumeReelId: String(routeParams.resumeReelId).trim()}
                : {}),
            }
          : {}),
      },
    };
    openGuestLogin(navigation, returnTo);
  }, [
    accessPlans,
    courseId,
    navigation,
    purchaseCouponCode,
    routeParams.purchaseCouponCode,
    routeParams.purchasePlanCode,
    routeParams.resumeAfterPreview,
    routeParams.resumeReelId,
  ]);

  const runPrimaryAction = useCallback(
    ({onPreview, onStart}: PrimaryActionHandlers) => {
      setNotice('');
      switch (primaryAction.kind) {
        case 'resume':
        case 'start':
          onStart();
          return;
        case 'login':
          openLogin();
          return;
        case 'preview':
          onPreview();
          return;
        case 'price_unavailable':
          setNotice('سعر الكورس لم يُنشر بعد\nلم نبدأ أي عملية شراء');
          return;
        case 'wallet_unavailable':
          setNotice('تعذّر التحقق من رصيدك\nحاول بعد لحظات');
          return;
        case 'checkout_unavailable':
          setNotice('الشراء غير متاح الآن');
          return;
        case 'disabled':
          return;
        case 'choose_plan':
        case 'free':
        case 'purchase':
          break;
      }
      if (
        primaryAction.kind === 'purchase' ||
        primaryAction.kind === 'choose_plan'
      ) {
        void trackProductEvent({
          event_name: 'paywall_viewed',
          screen_key: 'course_details',
          course_id: courseId,
        });
      }
      openForTerms({
        forcePlanSelection: primaryAction.kind === 'choose_plan',
        purchasePrice,
        spendableBalance,
      });
    },
    [
      courseId,
      openLogin,
      primaryAction.kind,
      purchasePrice,
      openForTerms,
      setNotice,
      spendableBalance,
    ],
  );

  useEffect(() => {
    if (routeParams.openPurchase !== true) autoHandledRef.current = false;
  }, [routeParams.openPurchase]);

  useEffect(() => {
    if (!routeParams.openPurchase || !pageReady || !owned) return;
    autoHandledRef.current = true;
    consumeRouteIntent();
  }, [consumeRouteIntent, owned, pageReady, routeParams.openPurchase]);

  useEffect(() => {
    const resumedPlanCode = String(routeParams.purchasePlanCode || '').trim();
    const resumedCoupon = normalizeHumanIdentifier(
      routeParams.purchaseCouponCode,
    );
    if (
      !routeParams.openPurchase ||
      autoHandledRef.current ||
      !pageReady ||
      owned ||
      remoteSession === null
    ) {
      return;
    }
    if (!CAN_START_COIN_CHECKOUT) {
      autoHandledRef.current = true;
      consumeRouteIntent();
      return;
    }
    if (remoteSession === false) {
      autoHandledRef.current = true;
      setNotice('');
      consumeRouteIntent();
      openLogin();
      return;
    }
    if (resumedPlanCode) {
      if (!accessPlans.some(plan => plan.code === resumedPlanCode)) {
        autoHandledRef.current = true;
        setNotice('تغيّرت فئات الكورس\nاختر الفئة المناسبة');
        showPlans();
        consumeRouteIntent();
        return;
      }
      if (selectedPlanCode !== resumedPlanCode) return;
    }
    if (
      resumedCoupon &&
      (purchaseRestoreStatus === 'idle' || purchaseRestoreStatus === 'quoting')
    ) {
      return;
    }
    if (resumedCoupon && purchaseRestoreStatus === 'failed') {
      autoHandledRef.current = true;
      consumeRouteIntent();
      return;
    }
    autoHandledRef.current = true;
    if (purchaseRestoreStatus !== 'failed') setNotice('');
    if (coursePrice === null) {
      setNotice('سعر الكورس لم يُنشر بعد\nلم نبدأ أي عملية شراء');
      consumeRouteIntent();
      return;
    }
    if (coursePrice > 0 && remoteBalance === null) {
      setNotice('تعذّر التحقق من رصيدك\nحاول بعد لحظات');
      consumeRouteIntent();
      return;
    }
    openForTerms({
      forcePlanSelection: !resumedPlanCode && accessPlans.length > 1,
      purchasePrice: effectivePurchasePrice,
      spendableBalance: effectiveSpendableBalance,
    });
    consumeRouteIntent();
  }, [
    accessPlans,
    coursePrice,
    consumeRouteIntent,
    effectivePurchasePrice,
    effectiveSpendableBalance,
    openLogin,
    owned,
    pageReady,
    purchaseRestoreStatus,
    remoteBalance,
    remoteSession,
    routeParams.openPurchase,
    routeParams.purchaseCouponCode,
    routeParams.purchasePlanCode,
    selectedPlanCode,
    showPlans,
    openForTerms,
    setNotice,
  ]);

  useEffect(() => {
    if (!retentionQueued || dialogStep !== null) return;
    const timer = setTimeout(() => {
      setRetentionQueued(false);
      if (!owned) setRetentionVisible(true);
    }, 180);
    return () => clearTimeout(timer);
  }, [dialogStep, owned, retentionQueued]);

  const closeDialog = useCallback(() => {
    if (busy || couponBusy) return;
    const retentionKey = `${identityKey}:${courseId}`;
    const shouldOfferTasks =
      dialogStep !== null &&
      dialogStep !== 'success' &&
      !owned &&
      !retentionShownCourses.has(retentionKey);
    if (shouldOfferTasks) {
      rememberRetentionOffer(retentionKey);
      setRetentionQueued(true);
    }
    if (dialogStep !== null && dialogStep !== 'success') {
      void trackProductEvent({
        event_name: 'paywall_dismissed',
        screen_key: 'course_details',
        course_id: courseId,
      });
    }
    closePurchase();
  }, [
    busy,
    closePurchase,
    couponBusy,
    courseId,
    dialogStep,
    identityKey,
    owned,
  ]);

  const closeRetention = useCallback(() => setRetentionVisible(false), []);

  return {
    closeDialog,
    openLogin,
    retention: {
      close: closeRetention,
      visible: retentionVisible,
    },
    runPrimaryAction,
  };
}
