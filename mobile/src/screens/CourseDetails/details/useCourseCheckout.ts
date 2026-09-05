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
import {errorCode} from '../../../utils/errorPayload';
import {derivePurchaseTerms} from './purchaseTerms';
import type {CourseWalletUpdate} from './useCourseDetailsData';

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
          try {
            const [wallet, refreshedQuote] = await Promise.all([
              getWallet(),
              quoteCoursePurchase(
                operationCourseId,
                operationPlan.code,
                couponApplied ? couponCode || '' : '',
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
            updateWallet(wallet);
            if (refreshedQuote.originalPrice !== purchasePrice) {
              replaceCouponQuote(null);
              reload();
              setNotice('تغيّر السعر\nراجع الفئات قبل الشراء');
              showPlans();
              return;
            }
            replaceCouponQuote(
              refreshedQuote.couponCode ? refreshedQuote : null,
            );
            const refreshedTerms = derivePurchaseTerms({
              balance: wallet.balance,
              minimumPaidCoins: operationPlan.minimumPaidCoins ?? 0,
              packages,
              paidBalance: wallet.paidBalance,
              price: refreshedQuote.finalPrice,
              rewardBalance: wallet.rewardBalance,
              rewardContributionLimit: wallet.rewardContributionCap,
            });
            setNotice('تم شحن رصيدك\nراجع الإجمالي ثم أكد الشراء');
            if (
              refreshedTerms.spendableBalance >= refreshedQuote.finalPrice
            ) {
              showConfirm();
            } else {
              showTopup();
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
            if (couponApplied) replaceCouponQuote(null);
            reload();
            showTopup();
            setNotice('تم تأكيد الشحن\nنحدّث الرصيد والسعر\nلا تدفع مرة أخرى');
          }
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
        setNotice('تعذّر تأكيد حالة الدفع\nحدّث الصفحة قبل محاولة الشحن مرة أخرى');
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
      packages,
      publishedRevision,
      purchasePrice,
      reload,
      replaceCouponQuote,
      selectedPlan,
      showConfirm,
      showPlans,
      showTopup,
      setNotice,
      setPackages,
      updateWallet,
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
    showPlans,
    setNotice,
  ]);

  return {busy, buyCoins, confirm};
}
