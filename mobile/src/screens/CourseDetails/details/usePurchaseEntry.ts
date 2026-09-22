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
import type {DialogStep, PurchaseFlowTerms} from './useCoursePurchaseFlow';
import {type CoursePrimaryAction} from './selectors';

type Params = {
  accessPlans: CourseAccessPlan[];
  courseId: string;
  dialogStep: DialogStep;
  identityKey: string;
  navigation: RootNavigation;
  owned: boolean;
  pageReady: boolean;
  primaryAction: CoursePrimaryAction;
  purchasePrice: number;
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
  courseId,
  dialogStep,
  identityKey,
  navigation,
  owned,
  pageReady,
  primaryAction,
  purchasePrice,
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
    const returnTo: LoginReturnTo = {
      name: 'CourseDetails',
      params: {
        courseId,
        openPurchase: true,
        ...(planCode ? {purchasePlanCode: planCode} : {}),
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
    if (
      !routeParams.openPurchase ||
      autoHandledRef.current ||
      !pageReady ||
      owned ||
      remoteSession === null ||
      primaryAction.kind === 'disabled'
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
        setNotice('تغيّرت الاشتراكات المتاحة\nاختر الاشتراك المناسب');
        showPlans();
        consumeRouteIntent();
        return;
      }
      if (selectedPlanCode !== resumedPlanCode) return;
    }
    autoHandledRef.current = true;
    setNotice('');
    if (primaryAction.kind === 'price_unavailable') {
      setNotice('سعر الكورس لم يُنشر بعد\nلم نبدأ أي عملية شراء');
      consumeRouteIntent();
      return;
    }
    if (primaryAction.kind === 'wallet_unavailable') {
      setNotice('تعذّر التحقق من رصيدك\nحاول بعد لحظات');
      consumeRouteIntent();
      return;
    }
    openForTerms({
      forcePlanSelection: !resumedPlanCode && accessPlans.length > 1,
      purchasePrice,
      spendableBalance,
    });
    consumeRouteIntent();
  }, [
    accessPlans,
    consumeRouteIntent,
    purchasePrice,
    spendableBalance,
    openLogin,
    owned,
    pageReady,
    primaryAction.kind,
    remoteSession,
    routeParams.openPurchase,
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
  }, [closePurchase, courseId, dialogStep, identityKey, owned]);

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
