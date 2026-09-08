import {useCallback, useEffect, useRef, useState} from 'react';
import type {Dispatch, SetStateAction} from 'react';
import {CAN_START_COIN_CHECKOUT} from '../../../constants/distribution';
import type {CoinPackage} from '../../../services/api/coinPackageMapper';
import {openCoinCheckout} from '../../../services/coinCheckout';
import {
  coinCheckoutFailureDisposition,
  coinCheckoutOutcome,
} from '../../../services/coinCheckoutTypes';
import {
  getCoinPackages,
  getWallet,
  purchaseCourse,
  quoteCoursePurchase,
  type CourseAccessPlan,
  type CoursePurchaseQuote,
} from '../../../services/roknApi';
import {trackProductEvent} from '../../../services/productAnalytics';
import {errorCode, learnerErrorMessage} from '../../../utils/errorPayload';
import {derivePurchaseTerms} from './purchaseTerms';
import type {CourseWalletUpdate} from './useCourseDetailsData';

const isRejectedCoupon = (code: string) =>
  [
    'coupon_invalid',
    'coupon_already_used',
    'coupon_not_applicable',
    'coupon_quota_reached',
  ].includes(code);

type Params = {
  closePurchase: () => void;
  couponApplied: boolean;
  couponCode?: string;
  courseId: string;
  effectivePrice: number;
  identityKey: string;
  invalidateCoupon: (clearCode: boolean) => void;
  packages: CoinPackage[];
  publishedRevision?: number;
  purchasePrice: number;
  reload: () => void;
  replaceCouponQuote: (quote: CoursePurchaseQuote | null) => void;
  selectedPlan?: CourseAccessPlan;
  shortfall: number;
  showConfirm: () => void;
  showPlans: () => void;
  showSuccess: () => void;
  showTopup: () => void;
  setNotice: Dispatch<SetStateAction<string>>;
  setOwned: (owned: boolean) => void;
  setPackages: Dispatch<SetStateAction<CoinPackage[]>>;
  updateWallet: (wallet: CourseWalletUpdate) => void;
};

export function useCourseCheckout({
  closePurchase,
  couponApplied,
  couponCode,
  courseId,
  effectivePrice,
  identityKey,
  invalidateCoupon,
  packages,
  publishedRevision,
  purchasePrice,
  reload,
  replaceCouponQuote,
  selectedPlan,
  shortfall,
  showConfirm,
  showPlans,
  showSuccess,
  showTopup,
  setNotice,
  setOwned,
  setPackages,
  updateWallet,
}: Params) {
  const [busy, setBusy] = useState(false);
  const activeScopeRef = useRef({courseId, identityKey});
  const generationRef = useRef(0);
  const inFlightRef = useRef(false);
  const completedTrackedRef = useRef(false);

  activeScopeRef.current = {courseId, identityKey};

  const ownsOperation = useCallback(
    (expectedCourseId: string, expectedIdentity: string, generation: number) =>
      activeScopeRef.current.courseId === expectedCourseId &&
      activeScopeRef.current.identityKey === expectedIdentity &&
      generationRef.current === generation,
    [],
  );

  useEffect(() => {
    generationRef.current += 1;
    inFlightRef.current = false;
    completedTrackedRef.current = false;
    setBusy(false);
    return () => {
      generationRef.current += 1;
    };
  }, [courseId, identityKey]);

  // A rejected quote is not a purchase to retry. Refresh the displayed terms
  // only and leave the next debit to a new, explicit student confirmation.
  const reviewCurrentTerms = useCallback(
    async (
      operationCourseId: string,
      operationIdentity: string,
      operationGeneration: number,
      notice: string,
      rejectedPurchase?: unknown,
    ) => {
      const operationPlan = selectedPlan;
      if (!operationPlan) {
        showPlans();
        return;
      }
      setNotice('جارٍ تحديث الإجمالي');
      const stillOwned = () =>
        ownsOperation(
          operationCourseId,
          operationIdentity,
          operationGeneration,
        );
      let currentCoupon = couponApplied ? couponCode || '' : '';
      const discardCoupon = (error: unknown) => {
        currentCoupon = '';
        invalidateCoupon(true);
        notice = `${learnerErrorMessage(
          error,
          'لم يعد الكود صالحًا',
        )}\nراجع الإجمالي بدون خصم قبل الشراء`;
      };
      replaceCouponQuote(null);
      if (isRejectedCoupon(errorCode(rejectedPurchase))) {
        discardCoupon(rejectedPurchase);
      }
      try {
        const [walletResult, quoteResult] = await Promise.allSettled([
          getWallet().then(value => {
            // Confirmed credit remains visible even if the separate quote
            // fails or rejects the coupon.
            if (stillOwned()) updateWallet(value);
            return value;
          }),
          (async () => {
            try {
              return await quoteCoursePurchase(
                operationCourseId,
                operationPlan.code,
                currentCoupon,
                publishedRevision,
              );
            } catch (error) {
              if (
                !stillOwned() ||
                !currentCoupon ||
                !isRejectedCoupon(errorCode(error))
              ) {
                throw error;
              }
              discardCoupon(error);
              // Only definitive coupon rejection permits one quote without
              // that coupon. Network/terms failures must not remove a valid one.
              return quoteCoursePurchase(
                operationCourseId,
                operationPlan.code,
                '',
                publishedRevision,
              );
            }
          })(),
        ]);
        if (!stillOwned()) return;
        if (quoteResult.status === 'rejected') throw quoteResult.reason;
        if (walletResult.status === 'rejected') throw walletResult.reason;
        const wallet = walletResult.value;
        const refreshedQuote = quoteResult.value;
        replaceCouponQuote(refreshedQuote.couponCode ? refreshedQuote : null);
        if (
          refreshedQuote.originalPrice !== purchasePrice ||
          refreshedQuote.courseRevision !== publishedRevision
        ) {
          reload();
          showPlans();
          setNotice('تغيّرت تفاصيل الفئة\nراجعها قبل الشراء');
          return;
        }
        const terms = derivePurchaseTerms({
          balance: wallet.balance,
          minimumPaidCoins: operationPlan.minimumPaidCoins ?? 0,
          packages,
          paidBalance: wallet.paidBalance,
          price: refreshedQuote.finalPrice,
          rewardBalance: wallet.rewardBalance,
          rewardContributionLimit: wallet.rewardContributionCap,
        });
        setNotice(notice);
        if (terms.spendableBalance >= refreshedQuote.finalPrice) showConfirm();
        else showTopup();
      } catch (error) {
        if (!stillOwned()) return;
        // Do not leave a stale confirmation or a new top-up CTA open when the
        // current total could not be verified.
        closePurchase();
        reload();
        setNotice(
          ['course_terms_changed', 'course_plan_unavailable'].includes(
            errorCode(error),
          )
            ? 'تغيّرت تفاصيل الفئة\nراجعها قبل الشراء'
            : 'تعذّر تحديث الإجمالي\nافتح الشراء للمحاولة مرة أخرى',
        );
      }
    },
    [
      closePurchase,
      couponApplied,
      couponCode,
      invalidateCoupon,
      ownsOperation,
      packages,
      publishedRevision,
      purchasePrice,
      reload,
      replaceCouponQuote,
      selectedPlan,
      setNotice,
      showConfirm,
      showPlans,
      showTopup,
      updateWallet,
    ],
  );

  const activate = useCallback(
    async (
      operationCourseId: string,
      operationIdentity: string,
      operationGeneration: number,
    ) => {
      const operationPlanCode = selectedPlan?.code;
      if (!operationPlanCode) {
        setNotice('اختر فئة الكورس أولًا');
        showPlans();
        return false;
      }
      void trackProductEvent({
        event_name: 'purchase_started',
        screen_key: 'course_details',
        course_id: operationCourseId,
      });
      const result = await purchaseCourse(
        operationCourseId,
        operationPlanCode,
        couponApplied ? couponCode : undefined,
        effectivePrice,
        publishedRevision,
      );
      if (
        !ownsOperation(
          operationCourseId,
          operationIdentity,
          operationGeneration,
        )
      ) {
        return false;
      }
      updateWallet(result);
      if (result.kind === 'success') {
        if (!completedTrackedRef.current) {
          completedTrackedRef.current = true;
          void trackProductEvent({
            event_name: 'purchase_completed',
            screen_key: 'course_details',
            course_id: operationCourseId,
          });
        }
        setOwned(true);
        showSuccess();
        return true;
      }
      setNotice('رصيدك لا يكفي\nاختر باقة شحن لإكمال الشراء');
      showTopup();
      return false;
    },
    [
      couponApplied,
      couponCode,
      effectivePrice,
      ownsOperation,
      publishedRevision,
      selectedPlan?.code,
      showPlans,
      showSuccess,
      showTopup,
      setNotice,
      setOwned,
      updateWallet,
    ],
  );

  const buyCoins = useCallback(
    async (coinPackage: CoinPackage) => {
      const operationPlan = selectedPlan;
      if (
        !operationPlan ||
        !CAN_START_COIN_CHECKOUT ||
        coinPackage.coins < shortfall ||
        inFlightRef.current
      ) {
        return;
      }
      const operationCourseId = courseId;
      const operationIdentity = identityKey;
      const operationGeneration = generationRef.current;
      inFlightRef.current = true;
      setBusy(true);
      setNotice('');
      try {
        const result = await openCoinCheckout(coinPackage, {
          returnTo: {
            name: 'CourseDetails',
            params: {
              courseId: operationCourseId,
              openPurchase: true,
              purchasePlanCode: operationPlan.code,
              ...(couponApplied && couponCode
                ? {purchaseCouponCode: couponCode}
                : {}),
            },
          },
        });
        if (
          !ownsOperation(
            operationCourseId,
            operationIdentity,
            operationGeneration,
          )
        ) {
          return;
        }
        const outcome = coinCheckoutOutcome(result);
        if (outcome === 'pending' && result.orderRef) {
          setNotice('جارٍ تأكيد الدفع\nسيحدّث رصيدك فور التأكيد');
        } else if (outcome === 'paid') {
          await reviewCurrentTerms(
            operationCourseId,
            operationIdentity,
            operationGeneration,
            'تم شحن رصيدك\nراجع الإجمالي ثم أكد الشراء',
          );
        } else {
          setNotice('لم يكتمل الدفع\nيمكنك المحاولة مرة أخرى');
        }
      } catch (error) {
        if (
          !ownsOperation(
            operationCourseId,
            operationIdentity,
            operationGeneration,
          )
        ) {
          return;
        }
        const code = errorCode(error);
        if (code === 'coin_checkout_in_progress') {
          setNotice('هناك عملية شحن مفتوحة\nأكملها أو أغلقها ثم حاول مرة أخرى');
          return;
        }
        const failureDisposition = coinCheckoutFailureDisposition(code);
        if (failureDisposition === 'catalogue_changed') {
          const operationCouponCode =
            couponApplied && couponCode ? couponCode : '';
          setPackages([]);
          replaceCouponQuote(null);
          setNotice('تغيّرت تفاصيل الباقة\nنحدّث خيارات الدفع');
          try {
            const [refreshedPackages, refreshedQuote] = await Promise.all([
              getCoinPackages(),
              quoteCoursePurchase(
                operationCourseId,
                operationPlan.code,
                operationCouponCode,
                publishedRevision,
              ),
            ]);
            if (
              !ownsOperation(
                operationCourseId,
                operationIdentity,
                operationGeneration,
              )
            ) {
              return;
            }
            setPackages(refreshedPackages);
            if (refreshedQuote.originalPrice !== purchasePrice) {
              invalidateCoupon(true);
              showPlans();
              setNotice('تغيّر السعر\nراجع الفئات قبل الشراء');
            } else {
              replaceCouponQuote(
                refreshedQuote.couponCode ? refreshedQuote : null,
              );
              showTopup();
              setNotice('تم تحديث باقات الشحن\nاختر الباقة من جديد');
            }
          } catch {
            if (
              !ownsOperation(
                operationCourseId,
                operationIdentity,
                operationGeneration,
              )
            ) {
              return;
            }
            showTopup();
            setNotice('تعذّر تحديث باقات الشحن\nحدّث الصفحة ثم اختر من جديد');
          }
          reload();
          return;
        }
        if (failureDisposition === 'opening_unavailable') {
          setNotice('الدفع متوقف مؤقتًا\nحاول لاحقًا');
          return;
        }
        reload();
        setNotice(
          'تعذّر تأكيد حالة الدفع\nحدّث الصفحة قبل محاولة الشحن مرة أخرى',
        );
      } finally {
        if (
          ownsOperation(
            operationCourseId,
            operationIdentity,
            operationGeneration,
          )
        ) {
          inFlightRef.current = false;
          setBusy(false);
        }
      }
    },
    [
      couponApplied,
      couponCode,
      courseId,
      identityKey,
      invalidateCoupon,
      ownsOperation,
      publishedRevision,
      purchasePrice,
      reload,
      replaceCouponQuote,
      reviewCurrentTerms,
      selectedPlan,
      showPlans,
      showTopup,
      setNotice,
      setPackages,
      shortfall,
    ],
  );

  const confirm = useCallback(async () => {
    if (
      (!CAN_START_COIN_CHECKOUT && purchasePrice > 0) ||
      inFlightRef.current
    ) {
      return;
    }
    const operationCourseId = courseId;
    const operationIdentity = identityKey;
    const operationGeneration = generationRef.current;
    inFlightRef.current = true;
    setBusy(true);
    setNotice('');
    try {
      await activate(operationCourseId, operationIdentity, operationGeneration);
    } catch (error) {
      if (
        !ownsOperation(
          operationCourseId,
          operationIdentity,
          operationGeneration,
        )
      ) {
        return;
      }
      const code = errorCode(error);
      if (code === 'course_access_changed') {
        closePurchase();
        replaceCouponQuote(null);
        setNotice('تغيّر وصولك للكورس\nنحدّث تفاصيل فئتك الحالية');
        reload();
        return;
      }
      if (code === 'course_price_changed' || isRejectedCoupon(code)) {
        await reviewCurrentTerms(
          operationCourseId,
          operationIdentity,
          operationGeneration,
          learnerErrorMessage(error, 'تغيّر السعر\nراجع الإجمالي قبل الشراء'),
          error,
        );
        return;
      }
      reload();
      if (['course_terms_changed', 'course_plan_unavailable'].includes(code)) {
        replaceCouponQuote(null);
        showPlans();
        setNotice('تغيّرت تفاصيل الفئة\nراجعها قبل الشراء');
      } else {
        setNotice('تعذّر تأكيد فتح الكورس\nحدّث الصفحة قبل المحاولة مرة أخرى');
      }
    } finally {
      if (
        ownsOperation(operationCourseId, operationIdentity, operationGeneration)
      ) {
        inFlightRef.current = false;
        setBusy(false);
      }
    }
  }, [
    activate,
    closePurchase,
    courseId,
    identityKey,
    ownsOperation,
    purchasePrice,
    reload,
    replaceCouponQuote,
    reviewCurrentTerms,
    showPlans,
    setNotice,
  ]);

  const reviewAfterCredit = useCallback(async () => {
    if (inFlightRef.current) return;
    const operationCourseId = courseId;
    const operationIdentity = identityKey;
    const operationGeneration = generationRef.current;
    inFlightRef.current = true;
    setBusy(true);
    try {
      await reviewCurrentTerms(
        operationCourseId,
        operationIdentity,
        operationGeneration,
        'تم تحديث رصيدك\nراجع الإجمالي ثم أكد الشراء',
      );
    } finally {
      if (
        ownsOperation(operationCourseId, operationIdentity, operationGeneration)
      ) {
        inFlightRef.current = false;
        setBusy(false);
      }
    }
  }, [courseId, identityKey, ownsOperation, reviewCurrentTerms]);

  return {busy, buyCoins, confirm, reviewAfterCredit};
}
