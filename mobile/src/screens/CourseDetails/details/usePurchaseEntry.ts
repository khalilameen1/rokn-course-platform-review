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
import {getCoursePurchaseExitOffer} from '../../../services/api/engagement';
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
  presentationActive?: boolean;
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

// Once an offer was shown OR buying was attempted, do not pursue this learner
// with the same course's exit offer again during this app process.
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
  presentationActive = true,
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
  const [retentionQueued, setRetentionQueued] = useState<{
    key: string;
    planCode: string;
  } | null>(null);
  const [retentionVisible, setRetentionVisible] = useState<{
    key: string;
    planCode: string;
  } | null>(null);

  useEffect(() => {
    autoHandledRef.current = false;
    setRetentionQueued(null);
    setRetentionVisible(null);
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
      openForTerms({
        forcePlanSelection: primaryAction.kind === 'choose_plan',
        purchasePrice,
        spendableBalance,
      });
    },
    [
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
    if (!presentationActive || owned || remoteSession !== true) {
      setRetentionQueued(null);
      setRetentionVisible(null);
    }
  }, [owned, presentationActive, remoteSession]);

  useEffect(() => {
    if (
      !retentionQueued ||
      dialogStep !== null ||
      owned ||
      !presentationActive ||
      !pageReady ||
      remoteSession !== true ||
      retentionQueued.key !== `${identityKey}:${courseId}` ||
      retentionQueued.planCode !== selectedPlanCode
    )
      return;
    let current = true;
    // Wait for the subscription sheet to close, then use a fresh server read.
    // Backgrounding, reopening, changing account/plan or attempting payment
    // invalidates this result. A failed read simply omits the optional offer.
    const timer = setTimeout(() => {
      void getCoursePurchaseExitOffer(courseId, retentionQueued.planCode)
        .then(offer => {
          if (
            !current ||
            !offer ||
            retentionShownCourses.has(retentionQueued.key)
          )
            return;
          rememberRetentionOffer(retentionQueued.key);
          setRetentionVisible(retentionQueued);
        })
        .catch(() => undefined);
    }, 180);
    return () => {
      current = false;
      clearTimeout(timer);
    };
  }, [
    courseId,
    dialogStep,
    identityKey,
    owned,
    pageReady,
    presentationActive,
    remoteSession,
    retentionQueued,
    selectedPlanCode,
  ]);

  const paymentAttempted = useCallback(() => {
    // Explicit press, before authorization/network/provider launch. Even a
    // declined or cancelled attempt is not the price-exit audience.
    rememberRetentionOffer(`${identityKey}:${courseId}`);
    setRetentionQueued(null);
    setRetentionVisible(null);
  }, [courseId, identityKey]);

  const closeDialog = useCallback(() => {
    const retentionKey = `${identityKey}:${courseId}`;
    const shouldOfferTasks =
      dialogStep !== null &&
      dialogStep !== 'success' &&
      !owned &&
      pageReady &&
      presentationActive &&
      remoteSession === true &&
      Boolean(selectedPlanCode) &&
      !retentionShownCourses.has(retentionKey);
    setRetentionVisible(null);
    setRetentionQueued(
      shouldOfferTasks && selectedPlanCode
        ? {key: retentionKey, planCode: selectedPlanCode}
        : null,
    );
    if (dialogStep !== null && dialogStep !== 'success') {
      void trackProductEvent({
        event_name: 'paywall_dismissed',
        screen_key: 'course_details',
        course_id: courseId,
      });
    }
    closePurchase();
  }, [
    closePurchase,
    courseId,
    dialogStep,
    identityKey,
    owned,
    pageReady,
    presentationActive,
    remoteSession,
    selectedPlanCode,
  ]);

  const closeRetention = useCallback(() => {
    setRetentionQueued(null);
    setRetentionVisible(null);
  }, []);

  return {
    closeDialog,
    openLogin,
    paymentAttempted,
    retention: {
      close: closeRetention,
      visible:
        retentionVisible?.key === `${identityKey}:${courseId}` &&
        retentionVisible?.planCode === selectedPlanCode &&
        dialogStep === null &&
        !owned &&
        presentationActive &&
        remoteSession === true,
    },
    runPrimaryAction,
  };
}
