import {useCallback, useEffect, useRef, useState} from 'react';
import {AppState} from 'react-native';
import {
  captureAccountSessionBoundary,
  assertAccountSessionBoundary,
} from '../constants/helpers';
import {
  CAN_START_COIN_CHECKOUT,
  IS_STORE_DISTRIBUTION,
} from '../constants/distribution';
import {getCoinPackages} from '../services/roknApi';
import {
  openCoinCheckout,
  subscribeCoinCheckoutCredits,
} from '../services/coinCheckout';
import {errorCode, learnerErrorMessage} from '../utils/errorPayload';
import {
  authorizeCourseCheckout,
  cancelCourseCheckout,
  getCourseCheckout,
  getLatestCourseCheckout,
  quoteCourseCheckout,
  resumeCourseCheckout,
  selectCheckoutPackage,
  type CourseCheckout,
  type CourseCheckoutMode,
} from '../services/api/courseCheckout';
import type {CoinPackage} from '../services/api/coinPackageMapper';

type Params = {
  courseId: string;
  mode?: CourseCheckoutMode;
  planCode?: string;
  courseRevision?: number;
  visible: boolean;
  onCompleted: () => void | Promise<void>;
};

/** One explicit authorization owns both the top-up and enrollment on the server.
 * Foreground/restart recovery only reads that intent; it never opens payment. */
export function useCourseSubscriptionCheckout({
  courseId,
  mode = 'purchase',
  planCode,
  courseRevision,
  visible,
  onCompleted,
}: Params) {
  const [quote, setQuote] = useState<CourseCheckout | null>(null);
  const [coinPackage, setCoinPackage] = useState<CoinPackage>();
  const [rewardCashSaving, setRewardCashSaving] = useState('');
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const [coupon, setCoupon] = useState('');
  const [appliedCoupon, setAppliedCoupon] = useState('');
  const [reloadKey, setReloadKey] = useState(0);
  const generation = useRef(0);
  const flight = useRef(false);
  const current = useRef({courseId, planCode, visible});
  const quoteRef = useRef(quote);
  const completedRef = useRef(new Set<string>());
  const callback = useRef(onCompleted);
  current.current = {courseId, planCode, visible};
  quoteRef.current = quote;
  callback.current = onCompleted;
  const owns = useCallback(
    (token: number) =>
      generation.current === token &&
      current.current.visible &&
      current.current.courseId === courseId &&
      current.current.planCode === planCode,
    [courseId, planCode],
  );
  const acceptCompleted = useCallback(async (next: CourseCheckout) => {
    setQuote(next);
    if (next.status === 'completed' && !completedRef.current.has(next.id)) {
      completedRef.current.add(next.id);
      await callback.current();
    }
  }, []);

  useEffect(() => {
    setCoupon('');
    setAppliedCoupon('');
  }, [courseId, planCode]);

  useEffect(() => {
    const token = ++generation.current;
    setQuote(null);
    setCoinPackage(undefined);
    setRewardCashSaving('');
    setNotice('');
    setBusy(false);
    flight.current = false;
    if (!visible || !planCode) {
      setLoading(false);
      return;
    }
    setLoading(true);
    void (async () => {
      try {
        const boundary = await captureAccountSessionBoundary();
        let latest = await getLatestCourseCheckout(courseId);
        assertAccountSessionBoundary(boundary);
        if (!owns(token)) return;
        if (latest?.status === 'pending_payment') {
          latest = await resumeCourseCheckout(latest.id);
          assertAccountSessionBoundary(boundary);
          if (!owns(token)) return;
          if (latest.status === 'completed') {
            await acceptCompleted(latest);
            return;
          }
          if (latest.status === 'pending_payment') {
            setQuote(latest);
            setNotice('الدفع قيد التأكيد\nلا تحتاج إلى الشحن مرة أخرى');
            return;
          }
        }
        // A completed old purchase may precede a new upgrade. A fresh quote
        // remains authoritative for whether this requested target is available.
        const input = {courseId, planCode, mode, couponCode: appliedCoupon};
        let next = await quoteCourseCheckout(input);
        assertAccountSessionBoundary(boundary);
        if (!owns(token)) return;
        if (
          courseRevision !== undefined &&
          next.courseRevision !== courseRevision
        )
          throw new Error('COURSE_CHECKOUT_TERMS_CHANGED');
        if (next.deficit > 0) {
          const available = await getCoinPackages();
          assertAccountSessionBoundary(boundary);
          if (!owns(token)) return;
          const eligibleIds = new Set(next.packages.map(item => item.id));
          const eligiblePackages = available.filter(
            item =>
              eligibleIds.has(item.id) &&
              (!IS_STORE_DISTRIBUTION || Boolean(item.displayPrice)),
          );
          const chosen = selectCheckoutPackage(eligiblePackages, next.deficit);
          if (chosen) {
            next = await quoteCourseCheckout({...input, packageId: chosen.id});
            assertAccountSessionBoundary(boundary);
            if (!owns(token)) return;
            if (
              courseRevision !== undefined &&
              next.courseRevision !== courseRevision
            )
              throw new Error('COURSE_CHECKOUT_TERMS_CHANGED');
            if (
              next.selectedPackageId !== chosen.id ||
              chosen.coins < next.deficit
            )
              throw new Error('COURSE_CHECKOUT_PACKAGE_CHANGED');
            setCoinPackage(chosen);
            const withoutRewards = selectCheckoutPackage(
              eligiblePackages,
              Math.max(0, next.finalPrice - next.paidBalance),
            );
            if (
              next.rewardCoins > 0 &&
              withoutRewards &&
              withoutRewards.price > chosen.price &&
              withoutRewards.currency === chosen.currency
            ) {
              const currency =
                chosen.currency || (!IS_STORE_DISTRIBUTION ? 'EGP' : '');
              if (currency)
                setRewardCashSaving(
                  new Intl.NumberFormat('ar-EG', {
                    style: 'currency',
                    currency,
                  }).format(withoutRewards.price - chosen.price),
                );
            }
          } else {
            setNotice('الدفع غير متاح لهذا الاشتراك الآن');
          }
        }
        setQuote(next);
      } catch (error) {
        if (!owns(token)) return;
        setNotice(
          errorCode(error) === 'COURSE_CHECKOUT_TERMS_CHANGED'
            ? 'تغيّرت الاشتراكات\nأغلق النافذة وحدّث الكورس لعرض التفاصيل الجديدة'
            : learnerErrorMessage(error, 'تعذّر تجهيز الاشتراك\nحاول مرة أخرى'),
        );
      } finally {
        if (owns(token)) setLoading(false);
      }
    })();
    return () => {
      generation.current += 1;
    };
  }, [
    acceptCompleted,
    appliedCoupon,
    courseId,
    courseRevision,
    mode,
    owns,
    planCode,
    reloadKey,
    visible,
  ]);

  const refreshPending = useCallback(async () => {
    const previous = quoteRef.current;
    if (!previous || previous.status !== 'pending_payment' || flight.current)
      return;
    const token = generation.current;
    flight.current = true;
    setBusy(true);
    try {
      const next = await resumeCourseCheckout(previous.id);
      if (!owns(token)) return;
      await acceptCompleted(next);
      if (next.status === 'reconfirm_required')
        setNotice(
          'تغيّرت تفاصيل الاشتراك\nرصيد الشحن محفوظ ويمكنك مراجعة الاختيار',
        );
      else if (next.status === 'pending_payment')
        setNotice('الدفع قيد التأكيد\nلا تحتاج إلى الشحن مرة أخرى');
    } catch {
      if (owns(token))
        setNotice('تعذّر تأكيد النتيجة\nلا تشحن مرة أخرى قبل التحقق');
    } finally {
      if (owns(token)) {
        flight.current = false;
        setBusy(false);
      }
    }
  }, [acceptCompleted, owns]);

  useEffect(() => {
    if (!visible) return;
    const app = AppState.addEventListener('change', state => {
      if (state === 'active') void refreshPending();
    });
    const credits = subscribeCoinCheckoutCredits(() => {
      void refreshPending();
    });
    return () => {
      app.remove();
      credits();
    };
  }, [refreshPending, visible]);

  const confirm = useCallback(async () => {
    if (!quote || loading || flight.current || !CAN_START_COIN_CHECKOUT) return;
    if (quote.status === 'pending_payment') {
      await refreshPending();
      return;
    }
    if (quote.status !== 'quoted') {
      setReloadKey(value => value + 1);
      return;
    }
    if (quote.deficit > 0 && !coinPackage) return;
    if (Date.parse(quote.expiresAt) <= Date.now()) {
      setReloadKey(value => value + 1);
      return;
    }
    const token = generation.current;
    flight.current = true;
    setBusy(true);
    setNotice('');
    let authorized = false;
    try {
      const boundary = await captureAccountSessionBoundary();
      let next = await authorizeCourseCheckout(quote.id);
      assertAccountSessionBoundary(boundary);
      if (!owns(token)) return;
      authorized = next.status === 'pending_payment';
      setQuote(next);
      if (next.status === 'completed') {
        await acceptCompleted(next);
        return;
      }
      if (next.status !== 'pending_payment' || !coinPackage) {
        setNotice('تغيّرت تفاصيل الاشتراك\nراجعها قبل المتابعة');
        return;
      }
      const payment = await openCoinCheckout(coinPackage, {
        courseCheckoutId: next.id,
        returnTo: {
          name: 'CourseDetails',
          params: {
            courseId,
            ...(mode === 'upgrade'
              ? {openFullTrackUpgrade: true}
              : {openPurchase: true, purchasePlanCode: planCode}),
          },
        },
      });
      assertAccountSessionBoundary(boundary);
      if (!owns(token)) return;
      if (payment.cancelled) {
        await cancelCourseCheckout(next.id);
        assertAccountSessionBoundary(boundary);
        if (!owns(token)) return;
        setReloadKey(value => value + 1);
        return;
      }
      next = await resumeCourseCheckout(next.id);
      assertAccountSessionBoundary(boundary);
      if (!owns(token)) return;
      await acceptCompleted(next);
      if (next.status === 'pending_payment')
        setNotice('الدفع قيد التأكيد\nسيُفعّل الاشتراك بعد التأكيد');
      else if (next.status !== 'completed')
        setNotice('تم تحديث الرصيد\nراجع تفاصيل الاشتراك قبل المتابعة');
    } catch (error) {
      if (!owns(token)) return;
      // A lost authorization response can still represent a committed debit.
      // Read its durable result before allowing another payment attempt.
      try {
        const next = await getCourseCheckout(quote.id);
        if (!owns(token)) return;
        await acceptCompleted(next);
        if (next.status === 'completed') return;
        authorized = next.status === 'pending_payment';
      } catch {}
      if (!owns(token)) return;
      setNotice(
        authorized
          ? 'تعذّر تأكيد النتيجة\nتحقق من الدفع قبل محاولة جديدة'
          : learnerErrorMessage(
              error,
              errorCode(error).includes('changed')
                ? 'تغيّرت التفاصيل\nراجع الاشتراك من جديد'
                : 'لم تكتمل العملية\nحاول مرة أخرى',
            ),
      );
    } finally {
      if (owns(token)) {
        flight.current = false;
        setBusy(false);
      }
    }
  }, [
    acceptCompleted,
    coinPackage,
    courseId,
    loading,
    mode,
    owns,
    planCode,
    quote,
    refreshPending,
  ]);

  const retry = useCallback(() => {
    if (!flight.current) setReloadKey(value => value + 1);
  }, []);
  const cancelPending = useCallback(async () => {
    const previous = quoteRef.current;
    if (!previous || previous.status !== 'pending_payment' || flight.current)
      return;
    const token = generation.current;
    flight.current = true;
    setBusy(true);
    try {
      const next = await cancelCourseCheckout(previous.id);
      if (!owns(token)) return;
      if (next.status === 'completed') {
        await acceptCompleted(next);
        return;
      }
      setQuote(next);
      setReloadKey(value => value + 1);
    } catch {
      if (owns(token))
        setNotice('تعذّر إلغاء الطلب\nتحقق من حالته قبل محاولة جديدة');
    } finally {
      if (owns(token)) {
        flight.current = false;
        setBusy(false);
      }
    }
  }, [acceptCompleted, owns]);
  const applyCoupon = useCallback(() => {
    if (!flight.current) setAppliedCoupon(coupon.trim());
  }, [coupon]);
  const changeCoupon = useCallback((value: string) => {
    if (flight.current) return;
    setCoupon(value);
    if (!value.trim()) setAppliedCoupon('');
  }, []);
  return {
    quote,
    coinPackage,
    rewardCashSaving,
    loading,
    busy,
    notice,
    coupon,
    setCoupon: changeCoupon,
    appliedCoupon,
    applyCoupon,
    confirm,
    cancelPending,
    retry,
    pending: quote?.status === 'pending_payment',
  };
}
